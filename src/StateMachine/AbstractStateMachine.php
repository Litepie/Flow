<?php
namespace Litepie\Flow\StateMachine;

abstract class AbstractStateMachine
{
    abstract public function transitions(): array;
    
    public function actions(): array
    {
        return [];
    }
    public function stateLabels(): array
    {
        return [];
    }
    /**
     * Get default state for this state machine
     */
    public function getDefaultState(): string
    {
        // Look for initial state in transitions
        foreach ($this->getTransitions() as $config) {
            $from = $config['from'];
            if (is_string($from) && $from !== '*') {
                return $from;
            }
            if (is_array($from) && ! empty($from)) {
                return $from[0];
            }
        }

        // Fallback to first state in labels
        $labels = $this->stateLabels();
        if (! empty($labels)) {
            return array_key_first($labels);
        }

        throw new \Exception('No default state found for state machine');
    }

    /**
     * Check if this state machine should record history
     */
    public function shouldRecordHistory(): bool
    {
        return property_exists($this, 'recordHistory') ? $this->recordHistory : true;
    }
}
