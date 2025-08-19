<?php

namespace Litepie\Flow\Tests\Feature;

use Litepie\Flow\Tests\TestCase;
use Examples\EnhancedOrderModel;

class StateMachineIntegrationTest extends TestCase
{
    public function test_can_transition_order_state()
    {
        $order = EnhancedOrderModel::create([
            'customer_id' => 1,
            'total' => 100,
            'state' => 'pending',
        ]);
        $order->stateMachine('state')->transitionTo('process_payment', [
            'payment_method' => 'credit_card',
            'amount' => 100,
        ]);
        $this->assertEquals('paid', $order->state);
    }
}
