<?php

namespace Litepie\Flow\Transitions;

use Litepie\Actions\Contracts\ActionContract;
use Closure;

class Transition
{
    protected array $guards = [];
    protected array $actions = [];
    protected array $metadata = [];

    public function __construct(
        protected string $from,
        protected string $to,
        protected string $event,
        protected string $label = ''
    ) {
        $this->label = $label ?: "{$this->from} → {$this->to}";
    }

    public function getFrom(): string
    {
        return $this->from;
    }

    public function getTo(): string
    {
        return $this->to;
    }

    public function getEvent(): string
    {
        return $this->event;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function addGuard(Closure|string $guard): self
    {
        $this->guards[] = $guard;
        return $this;
    }

    public function addAction(ActionContract|string $action): self
    {
        $this->actions[] = $action;
        return $this;
    }

    public function canTransition(array $context = []): bool
    {
        foreach ($this->guards as $guard) {
            if (is_string($guard)) {
                // Handle string guard (class name)
                $guardInstance = app($guard);
                if (!$guardInstance($context)) {
                    return false;
                }
            } elseif ($guard instanceof Closure) {
                if (!$guard($context)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function executeActions(array $context = []): array
    {
        $results = [];

        foreach ($this->actions as $action) {
            if (is_string($action)) {
                $action = app($action);
            }

            if ($action instanceof ActionContract) {
                $results[] = $action->execute($context);
            }
        }

        return $results;
    }

    public function setMetadata(string $key, $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    public function getMetadata(string $key = null)
    {
        return $key ? ($this->metadata[$key] ?? null) : $this->metadata;
    }

    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'event' => $this->event,
            'label' => $this->label,
            'metadata' => $this->metadata
        ];
    }
}
