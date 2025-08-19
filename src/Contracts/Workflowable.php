<?php

namespace Litepie\Flow\Contracts;

use Litepie\Flow\States\State;

interface Workflowable
{
    public function getCurrentState(): ?State;
    public function canTransitionTo(string $state): bool;
    public function transitionTo(string $state, array $context = []): bool;
    public function getAvailableTransitions(): array;
    public function getWorkflowName(): string;
}
