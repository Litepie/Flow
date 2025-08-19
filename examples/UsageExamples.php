<?php

namespace Litepie\Flow\examples;

use App\Models\Order;

// Create a new order
$order = Order::create([
    'customer_id' => 1,
    'total' => 99.99,
    'state' => 'pending'
]);

// Check available transitions
$transitions = $order->getAvailableTransitions();

// Transition to the next state
if ($order->canTransitionTo('processing')) {
    $order->transitionTo('processing', [
        'amount' => $order->total,
        'payment_method' => 'credit_card',
        'order_id' => $order->id
    ]);
}

// Get current state
$currentState = $order->getCurrentState();
echo $currentState->getLabel(); // "Processing"
