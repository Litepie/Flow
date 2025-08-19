<?php
<?php

namespace Litepie\Flow\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Litepie\Flow\Models\StateHistory;
use Litepie\Flow\Models\PendingTransition;
use Litepie\Flow\StateMachine\StateWrapper;
use Carbon\Carbon;

trait HasStateMachine
{
    protected array $stateMachineInstances = [];

    /**
     * Boot the trait and set up dynamic methods and event handlers
     */
    public static function bootHasStateMachine()
    {
        static::registerStateMachineMethods();
        static::registerEventHandlers();
    }

    /**
     * Register dynamic methods for each state machine field
     */
    protected static function registerStateMachineMethods(): void
    {
        $model = new static();
        $stateMachines = $model->stateMachines ?? [];

        foreach ($stateMachines as $field => $stateMachineClass) {
            static::registerFieldMethods($field);
            static::registerQueryMacros($field);
        }
    }

    /**
     * Register methods for a specific field (snake_case, camelCase, StudlyCase)
     */
    protected static function registerFieldMethods(string $field): void
    {
        $camelField = Str::camel($field);
        $studlyField = Str::studly($field);

        // Add dynamic methods for different naming conventions
        if (!method_exists(static::class, $field)) {
            static::addStateMachineMethod($field);
        }
        
        if (!method_exists(static::class, $camelField) && $camelField !== $field) {
            static::addStateMachineMethod($camelField, $field);
        }
        
        if (!method_exists(static::class, $studlyField) && $studlyField !== $field) {
            static::addStateMachineMethod($studlyField, $field);
        }
    }

    /**
     * Add a dynamic state machine method
     */
    protected static function addStateMachineMethod(string $methodName, string $field = null): void
    {
        $field = $field ?? $methodName;
        
        // This would ideally use a macro system, but for simplicity we'll document the pattern
        // In practice, you might want to use a macro package or implement __call
    }

    /**
     * Register query builder macros for state history filtering
     */
    protected static function registerQueryMacros(string $field): void
    {
        $studlyField = Str::studly($field);
        
        Builder::macro("whereHas{$studlyField}", function (callable $callback = null) use ($field) {
            return $this->whereHas('stateHistory', function ($query) use ($field, $callback) {
                $query->forField($field);
                if ($callback !== null) {
                    $callback($query);
                }
            });
        });

        Builder::macro("whereWas{$studlyField}", function (string $state) use ($field) {
            return $this->whereHas('stateHistory', function ($query) use ($field, $state) {
                $query->forField($field)->to($state);
            });
        });

        Builder::macro("whereNeverWas{$studlyField}", function (string $state) use ($field) {
            return $this->whereDoesntHave('stateHistory', function ($query) use ($field, $state) {
                $query->forField($field)->to($state);
            });
        });
    }

    /**
     * Register model event handlers
     */
    protected static function registerEventHandlers(): void
    {
        static::creating(function (Model $model) {
            $model->initializeStateMachineFields();
        });

        static::created(function (Model $model) {
            $model->recordInitialStates();
        });

        static::updating(function (Model $model) {
            $model->prepareStateChanges();
        });

        static::updated(function (Model $model) {
            $model->handleStateChanges();
        });
    }

    /**
     * Initialize state machine fields with default values
     */
    public function initializeStateMachineFields(): void
    {
        $stateMachines = $this->stateMachines ?? [];

        foreach ($stateMachines as $field => $stateMachineClass) {
            if ($this->{$field} === null) {
                $stateMachine = new $stateMachineClass($field, $this);
                $this->{$field} = $stateMachine->getDefaultState();
            }
        }
    }

    /**
     * Record initial states when model is created
     */
    public function recordInitialStates(): void
    {
        $stateMachines = $this->stateMachines ?? [];

        foreach ($stateMachines as $field => $stateMachineClass) {
            $currentState = $this->{$field};
            
            if ($currentState === null) {
                continue;
            }

            $stateMachine = $this->stateMachine($field);
            
            if (!$stateMachine->shouldRecordHistory()) {
                continue;
            }

            $this->recordStateChange($field, null, $currentState, [], auth()->user());
        }
    }

    /**
     * Prepare for state changes during model update
     */
    public function prepareStateChanges(): void
    {
        $this->originalStateValues = [];
        $stateMachines = $this->stateMachines ?? [];

        foreach ($stateMachines as $field => $stateMachineClass) {
            $this->originalStateValues[$field] = $this->getOriginal($field);
        }
    }

    /**
     * Handle state changes after model update
     */
    public function handleStateChanges(): void
    {
        if (!isset($this->originalStateValues)) {
            return;
        }

        $stateMachines = $this->stateMachines ?? [];

        foreach ($stateMachines as $field => $stateMachineClass) {
            $oldState = $this->originalStateValues[$field];
            $newState = $this->{$field};

            if ($oldState !== $newState) {
                $this->recordStateChange(
                    $field, 
                    $oldState, 
                    $newState, 
                    $this->getChangedAttributes(),
                    auth()->user()
                );
            }
        }
    }

    /**
     * Get changed attributes with old and new values
     */
    public function getChangedAttributes(): array
    {
        return collect($this->getDirty())
            ->mapWithKeys(function ($newValue, $attribute) {
                return [
                    $attribute => [
                        'old' => $this->getOriginal($attribute),
                        'new' => $newValue,
                    ],
                ];
            })
            ->toArray();
    }

    /**
     * Record a state change in history
     */
    protected function recordStateChange(string $field, ?string $from, string $to, array $context = [], $causer = null): StateHistory
    {
        return $this->stateHistory()->create([
            'field' => $field,
            'from' => $from,
            'to' => $to,
            'transition' => $context['transition'] ?? null,
            'custom_properties' => $context,
            'causer_type' => $causer ? get_class($causer) : null,
            'causer_id' => $causer?->getKey(),
        ]);
    }

    /**
     * Get state history relationship
     */
    public function stateHistory(): MorphMany
    {
        return $this->morphMany(StateHistory::class, 'model');
    }

    /**
     * Get pending transitions relationship
     */
    public function pendingTransitions(): MorphMany
    {
        return $this->morphMany(PendingTransition::class, 'model');
    }

    /**
     * Get state machine instance for a specific field
     */
    public function stateMachine(string $field = 'state')
    {
        if (!isset($this->stateMachineInstances[$field])) {
            $stateMachineClass = $this->getStateMachineClass($field);
            $this->stateMachineInstances[$field] = new $stateMachineClass($field, $this);
        }

        return $this->stateMachineInstances[$field];
    }

    /**
     * Get state wrapper for fluent API
     */
    public function state(string $field = 'state'): StateWrapper
    {
        $stateMachine = $this->stateMachine($field);
        return new StateWrapper($stateMachine->currentState(), $stateMachine);
    }

    /**
     * Record a pending transition
     */
    public function recordPendingTransition(
        string $field,
        string $from,
        string $to,
        string $transition,
        Carbon $when,
        array $properties = [],
        $causer = null
    ): PendingTransition {
        return $this->pendingTransitions()->create([
            'field' => $field,
            'from' => $from,
            'to' => $to,
            'transition' => $transition,
            'apply_at' => $when,
            'custom_properties' => $properties,
            'causer_type' => $causer ? get_class($causer) : null,
            'causer_id' => $causer?->getKey(),
        ]);
    }

    /**
     * Check if model was ever in a specific state for a field
     */
    public function wasInState(string $state, string $field = 'state'): bool
    {
        return $this->stateHistory()
            ->forField($field)
            ->to($state)
            ->exists();
    }

    /**
     * Count how many times model was in a specific state
     */
    public function timesInState(string $state, string $field = 'state'): int
    {
        return $this->stateHistory()
            ->forField($field)
            ->to($state)
            ->count();
    }

    /**
     * Get timestamp when model was last in a specific state
     */
    public function whenInState(string $state, string $field = 'state'): ?Carbon
    {
        $history = $this->stateHistory()
            ->forField($field)
            ->to($state)
            ->latest()
            ->first();

        return $history?->created_at;
    }

    /**
     * Get the state machine class for a field
     */
    protected function getStateMachineClass(string $field): string
    {
        $stateMachines = $this->stateMachines ?? [];
        
        if (isset($stateMachines[$field])) {
            return $stateMachines[$field];
        }
        
        // Default naming convention: ModelNameStateMachine
        $modelName = class_basename($this);
        $defaultClass = "App\\StateMachines\\{$modelName}StateMachine";
        
        if (class_exists($defaultClass)) {
            return $defaultClass;
        }
        
        throw new \Exception("No state machine class defined for field '{$field}' on model " . get_class($this));
    }

    /**
     * Magic method to handle dynamic state machine methods
     */
    public function __call($method, $parameters)
    {
        $stateMachines = $this->stateMachines ?? [];
        
        // Check if method matches a state machine field (any case)
        foreach ($stateMachines as $field => $stateMachineClass) {
            if (strtolower($method) === strtolower($field) ||
                strtolower($method) === strtolower(Str::camel($field)) ||
                strtolower($method) === strtolower(Str::studly($field))) {
                
                return $this->state($field);
            }
        }

        return parent::__call($method, $parameters);
    }
}
