<?php

namespace Litepie\Flow\StateMachine;

class StateWrapper
{
    protected $state;
    public function __construct($state)
    {
        $this->state = $state;
    }
    public function getState()
    {
        return $this->state;
    }
}
