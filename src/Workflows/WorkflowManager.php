<?php

namespace Litepie\Flow\Workflows;

use Litepie\Flow\Contracts\WorkflowManagerContract;

class WorkflowManager implements WorkflowManagerContract
{
    protected array $workflows = [];

    public function create(string $name, array $config = []): Workflow
    {
        $workflow = new Workflow($name, $config['label'] ?? '');
        $this->register($name, $workflow);
        return $workflow;
    }

    public function get(string $name): Workflow
    {
        return $this->workflows[$name] ?? throw new \InvalidArgumentException("Workflow [$name] not found.");
    }

    public function has(string $name): bool
    {
        return isset($this->workflows[$name]);
    }

    public function all(): array
    {
        return $this->workflows;
    }

    public function register(string $name, Workflow $workflow): void
    {
        $this->workflows[$name] = $workflow;
    }
}
