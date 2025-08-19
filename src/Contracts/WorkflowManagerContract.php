<?php

namespace Litepie\Flow\Contracts;

use Litepie\Flow\Workflows\Workflow;

interface WorkflowManagerContract
{
    public function create(string $name, array $config = []): Workflow;
    public function get(string $name): Workflow;
    public function has(string $name): bool;
    public function all(): array;
    public function register(string $name, Workflow $workflow): void;
}
