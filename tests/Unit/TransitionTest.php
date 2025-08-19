<?php

namespace Litepie\Flow\tests\Unit;

use PHPUnit\Framework\TestCase;
use Litepie\Flow\Transitions\Transition;

class TransitionTest extends TestCase
{
    public function test_transition_properties()
    {
        $transition = new Transition('pending', 'processing', 'process');
        $this->assertEquals('pending', $transition->getFrom());
        $this->assertEquals('processing', $transition->getTo());
        $this->assertEquals('process', $transition->getEvent());
    }
}
