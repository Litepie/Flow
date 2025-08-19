<?php

namespace Litepie\Flow\Traits;

use Litepie\Flow\Contracts\Workflowable;

trait HasWorkflow
{
    public function getCurrentState(): ?\Litepie\Flow\States\State
    {
        $workflow = $this->getWorkflow();
        $stateName = $this->{$this->getWorkflowStateColumn()};
        return $workflow->getStates()[$stateName] ?? null;
    }

    public function canTransitionTo(string $state): bool
    {
        $workflow = $this->getWorkflow();
        $current = $this->getCurrentState();
        foreach ($workflow->getTransitions() as $transition) {
            if ($transition->getFrom() === $current->getName() && $transition->getTo() === $state) {
                return $transition->canTransition(['model' => $this]);
            }
        }
        return false;
    }

    public function transitionTo(string $state, array $context = []): bool
    {
        $workflow = $this->getWorkflow();
        $current = $this->getCurrentState();
        foreach ($workflow->getTransitions() as $transition) {
            if ($transition->getFrom() === $current->getName() && $transition->getTo() === $state) {
                if ($transition->canTransition(['model' => $this] + $context)) {
                    $transition->executeActions(['model' => $this] + $context);
                    $this->{$this->getWorkflowStateColumn()} = $state;
                    $this->save();
                    event(new \Litepie\Flow\Events\WorkflowTransitioned($this, $current->getName(), $state, $context));
                    return true;
                }
            }
        }
        return false;
    }

    public function getAvailableTransitions(): array
    {
        $workflow = $this->getWorkflow();
        $current = $this->getCurrentState();
        $available = [];
        foreach ($workflow->getTransitions() as $transition) {
            if ($transition->getFrom() === $current->getName()) {
                $available[] = $transition;
            }
        }
        return $available;
    }

    public function getWorkflow(): \Litepie\Flow\Workflows\Workflow
    {
        return app('flow.workflows')->get($this->getWorkflowName());
    }

    abstract public function getWorkflowName(): string;
    abstract protected function getWorkflowStateColumn(): string;
}
