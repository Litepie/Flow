<?php

namespace Litepie\Flow\tests\Unit;

use PHPUnit\Framework\TestCase;
use Litepie\Flow\States\State;

class StateTest extends TestCase
{
    public function test_state_properties()
    {
        $state = new State('pending', 'Pending', true, false);
        $this->assertEquals('pending', $state->getName());
        $this->assertEquals('Pending', $state->getLabel());
        $this->assertTrue($state->isInitial());
        $this->assertFalse($state->isFinal());
    }
}
