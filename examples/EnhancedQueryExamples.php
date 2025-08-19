<?php

// Usage examples with the enhanced trait:

// Dynamic methods for different naming conventions
$order->status(); // snake_case
$order->orderStatus(); // camelCase  
$order->OrderStatus(); // StudlyCase

// Enhanced query builder macros
Order::whereHasStatus(function($query) {
    $query->to('paid')->where('created_at', '>', now()->subDays(30));
})->get();

Order::whereWasStatus('paid')->get();
Order::whereNeverWasStatus('cancelled')->get();

// Automatic state initialization
$order = Order::create([
    'customer_id' => 1,
    'total' => 99.99
    // status will be automatically set to default state
]);

// Enhanced state queries
$order->wasInState('paid'); // Check if ever paid
$order->timesInState('shipped'); // Count transitions to shipped
$order->whenInState('paid'); // When was it paid
