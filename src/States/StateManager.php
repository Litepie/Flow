<?php

namespace Litepie\Flow\States;

use Litepie\Flow\Contracts\StateManagerContract;

class StateManager implements StateManagerContract
{
    protected array $states = [];

    public function create(string $name, array $config = []): State
    {
        $state = new State(
            $name,
            $config['label'] ?? '',
            $config['initial'] ?? false,
            $config['final'] ?? false
        );
        $this->register($name, $state);
        return $state;
    }

    public function get(string $name): State
    {
        return $this->states[$name] ?? throw new \InvalidArgumentException("State [$name] not found.");
    }

    public function has(string $name): bool
    {
        return isset($this->states[$name]);
    }

    public function all(): array
    {
        return $this->states;
    }

    public function register(string $name, State $state): void
    {
        $this->states[$name] = $state;
    }
}
