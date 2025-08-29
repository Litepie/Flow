<?php
namespace Litepie\Flow\StateMachine;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

abstract class AbstractStateMachine
{
    protected Model $model;
    protected string $field;

    public function __construct(Model $model, string $field)
    {
        $this->model = $model;
        $this->field = $field;
    }

    /**
     * Define the transitions for this state machine
     */
    abstract public function transitions(): array;
    
    /**
     * Define actions/callbacks for transitions
     */
    public function actions(): array
    {
        return [];
    }

    /**
     * Define human-readable state labels
     */
    public function stateLabels(): array
    {
        return [];
    }

    /**
     * Get all available states for this state machine
     */
    public function states(): Collection
    {
        $states = collect();
        
        foreach ($this->transitions() as $transition) {
            if (is_string($transition['from'])) {
                $states->push($transition['from']);
            } elseif (is_array($transition['from'])) {
                $states = $states->merge($transition['from']);
            }
            
            $states->push($transition['to']);
        }
        
        return $states->unique()->values();
    }

    /**
     * Get current state of the model
     */
    public function currentState(): ?string
    {
        return $this->model->{$this->field};
    }

    /**
     * Check if current state equals given state
     */
    public function is(string $state): bool
    {
        return $this->currentState() === $state;
    }

    /**
     * Check if current state is in given states
     */
    public function in(array $states): bool
    {
        return in_array($this->currentState(), $states);
    }

    /**
     * Get available transitions from current state
     */
    public function availableTransitions(): Collection
    {
        $currentState = $this->currentState();
        
        return collect($this->transitions())->filter(function ($transition) use ($currentState) {
            $from = $transition['from'];
            
            if ($from === '*') {
                return true;
            }
            
            if (is_string($from)) {
                return $from === $currentState;
            }
            
            if (is_array($from)) {
                return in_array($currentState, $from);
            }
            
            return false;
        });
    }

    /**
     * Check if transition is allowed from current state
     */
    public function canTransitionTo(string $state): bool
    {
        return $this->availableTransitions()
            ->contains('to', $state);
    }

    /**
     * Get transition configuration for given target state
     */
    public function getTransition(string $toState): ?array
    {
        return $this->availableTransitions()
            ->first(fn($transition) => $transition['to'] === $toState);
    }

    /**
     * Transition to new state
     */
    public function transitionTo(string $newState, array $payload = []): bool
    {
        if (!$this->canTransitionTo($newState)) {
            throw new \InvalidArgumentException(
                "Cannot transition from [{$this->currentState()}] to [{$newState}]"
            );
        }

        $transition = $this->getTransition($newState);
        $oldState = $this->currentState();

        // Execute guards if present
        if (isset($transition['guard']) && is_callable($transition['guard'])) {
            if (!call_user_func($transition['guard'], $this->model, $payload)) {
                return false;
            }
        }

        // Execute before action
        if (isset($transition['before']) && is_callable($transition['before'])) {
            call_user_func($transition['before'], $this->model, $payload);
        }

        // Update the model state
        $this->model->{$this->field} = $newState;

        // Record history if enabled
        if ($this->shouldRecordHistory()) {
            $this->recordStateHistory($oldState, $newState, $payload);
        }

        // Execute after action
        if (isset($transition['after']) && is_callable($transition['after'])) {
            call_user_func($transition['after'], $this->model, $payload);
        }

        // Fire events
        $this->fireTransitionEvent($oldState, $newState, $payload);

        return true;
    }

    /**
     * Force transition without validation
     */
    public function forceTransitionTo(string $newState, array $payload = []): void
    {
        $oldState = $this->currentState();
        
        $this->model->{$this->field} = $newState;

        if ($this->shouldRecordHistory()) {
            $this->recordStateHistory($oldState, $newState, $payload);
        }

        $this->fireTransitionEvent($oldState, $newState, $payload);
    }

    /**
     * Get default state for this state machine
     */
    public function getDefaultState(): string
    {
        // Look for initial state in transitions
        foreach ($this->transitions() as $config) {
            $from = $config['from'];
            if (is_string($from) && $from !== '*') {
                return $from;
            }
            if (is_array($from) && !empty($from)) {
                return $from[0];
            }
        }

        // Fallback to first state in labels
        $labels = $this->stateLabels();
        if (!empty($labels)) {
            return array_key_first($labels);
        }

        // Fallback to first state found in transitions
        $states = $this->states();
        if ($states->isNotEmpty()) {
            return $states->first();
        }

        throw new \Exception('No default state found for state machine');
    }

    /**
     * Get label for given state
     */
    public function getStateLabel(string $state): string
    {
        $labels = $this->stateLabels();
        return $labels[$state] ?? $state;
    }

    /**
     * Get label for current state
     */
    public function getCurrentStateLabel(): string
    {
        return $this->getStateLabel($this->currentState());
    }

    /**
     * Check if this state machine should record history
     */
    public function shouldRecordHistory(): bool
    {
        return property_exists($this, 'recordHistory') ? $this->recordHistory : true;
    }

    /**
     * Record state history
     */
    protected function recordStateHistory(string $from, string $to, array $payload = []): void
    {
        $this->model->stateHistory()->create([
            'field' => $this->field,
            'from' => $from,
            'to' => $to,
            'payload' => $payload,
            'transitioned_at' => now(),
        ]);
    }

    /**
     * Fire transition event
     */
    protected function fireTransitionEvent(string $from, string $to, array $payload = []): void
    {
        $modelClass = get_class($this->model);
        $stateMachineClass = get_class($this);

        event("eloquent.state-machine.transitioned", [
            'model' => $this->model,
            'field' => $this->field,
            'from' => $from,
            'to' => $to,
            'payload' => $payload,
            'state_machine' => $stateMachineClass,
        ]);

        // Fire specific event for this transition
        event("eloquent.state-machine.transitioned.{$modelClass}.{$this->field}", [
            'model' => $this->model,
            'from' => $from,
            'to' => $to,
            'payload' => $payload,
        ]);
    }

    /**
     * Get the model instance
     */
    public function getModel(): Model
    {
        return $this->model;
    }

    /**
     * Get the field name
     */
    public function getField(): string
    {
        return $this->field;
    }

    /**
     * Create a pending transition
     */
    public function scheduleTo(string $state, \DateTime $when, array $payload = []): void
    {
        if (!$this->canTransitionTo($state)) {
            throw new \InvalidArgumentException(
                "Cannot schedule transition from [{$this->currentState()}] to [{$state}]"
            );
        }

        $this->model->pendingTransitions()->create([
            'field' => $this->field,
            'from' => $this->currentState(),
            'to' => $state,
            'payload' => $payload,
            'scheduled_at' => $when,
        ]);
    }

    /**
     * Get pending transitions for this field
     */
    public function pendingTransitions(): Collection
    {
        return $this->model->pendingTransitions()
            ->where('field', $this->field)
            ->where('scheduled_at', '>', now())
            ->get();
    }

    /**
     * Magic method to check state
     */
    public function __call(string $method, array $arguments): bool
    {
        // Handle is{State} methods
        if (str_starts_with($method, 'is') && strlen($method) > 2) {
            $state = strtolower(substr($method, 2));
            return $this->is($state);
        }

        throw new \BadMethodCallException("Method {$method} not found");
    }
}
