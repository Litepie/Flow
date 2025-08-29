# State Machine Documentation

The Litepie Flow package includes a powerful state machine system for managing simple state transitions on model attributes. This is separate from but complementary to the workflow system.

## Table of Contents

- [Overview](#overview)
- [When to Use State Machines](#when-to-use-state-machines)
- [Quick Start](#quick-start)
- [Creating State Machines](#creating-state-machines)
- [Model Integration](#model-integration)
- [Available Methods](#available-methods)
- [State History](#state-history)
- [Events](#events)
- [Advanced Usage](#advanced-usage)
- [Examples](#examples)

## Overview

State machines in Litepie Flow provide a lightweight way to manage state transitions for individual model attributes. Unlike workflows, which handle complex business processes, state machines focus on simple, direct state management.

### Key Features

- ✅ Simple state transitions
- ✅ Automatic state history tracking
- ✅ Event firing on state changes
- ✅ Multiple state machines per model
- ✅ State validation and guards
- ✅ Human-readable state labels
- ✅ Query scopes for state history

## When to Use State Machines

Use state machines when you need:

- **Simple state tracking**: Order status, payment status, shipping status
- **Attribute-level transitions**: Individual field state management
- **Multiple independent states**: Different aspects of the same model
- **Lightweight state management**: Without complex business logic

Use workflows when you need:
- Complex business processes with multiple participants
- Multi-step approval workflows
- Advanced transition logic with actions and guards
- Process orchestration

## Quick Start

### 1. Create a State Machine

```php
<?php

namespace App\StateMachines;

use Litepie\Flow\StateMachine\AbstractStateMachine;

class OrderStatusStateMachine extends AbstractStateMachine
{
    public function transitions(): array
    {
        return [
            'process' => [
                'from' => 'pending',
                'to' => 'processing',
            ],
            'ship' => [
                'from' => 'processing', 
                'to' => 'shipped',
            ],
            'deliver' => [
                'from' => 'shipped',
                'to' => 'delivered',
            ],
            'cancel' => [
                'from' => ['pending', 'processing'],
                'to' => 'cancelled',
            ],
        ];
    }

    public function stateLabels(): array
    {
        return [
            'pending' => 'Pending Payment',
            'processing' => 'Being Processed',
            'shipped' => 'Shipped',
            'delivered' => 'Delivered',
            'cancelled' => 'Cancelled',
        ];
    }
}
```

### 2. Add to Model

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Litepie\Flow\Traits\HasStateMachine;

class Order extends Model
{
    use HasStateMachine;

    protected $fillable = ['customer_id', 'total', 'status'];

    protected $stateMachines = [
        'status' => \App\StateMachines\OrderStatusStateMachine::class,
    ];
}
```

### 3. Use in Code

```php
$order = Order::create([
    'customer_id' => 1,
    'total' => 99.99,
    'status' => 'pending',
]);

// Check current state
if ($order->stateMachine('status')->is('pending')) {
    // Process the order
}

// Get state label
echo $order->stateMachine('status')->getCurrentStateLabel(); // "Pending Payment"

// Check available states
$states = $order->stateMachine('status')->states();
// Collection: ['pending', 'processing', 'shipped', 'delivered', 'cancelled']
```

## Creating State Machines

### Basic Structure

```php
<?php

namespace App\StateMachines;

use Litepie\Flow\StateMachine\AbstractStateMachine;

class MyStateMachine extends AbstractStateMachine
{
    /**
     * Define transitions between states
     */
    public function transitions(): array
    {
        return [
            'transition_name' => [
                'from' => 'source_state',      // Single state
                'to' => 'target_state',
            ],
            'multi_source' => [
                'from' => ['state1', 'state2'], // Multiple source states
                'to' => 'target_state',
            ],
        ];
    }

    /**
     * Define human-readable state labels
     */
    public function stateLabels(): array
    {
        return [
            'state1' => 'Human Readable Label 1',
            'state2' => 'Human Readable Label 2',
        ];
    }

    /**
     * Define available actions (optional)
     */
    public function actions(): array
    {
        return [
            'approve' => [
                'label' => 'Approve Item',
                'icon' => 'check',
                'color' => 'green',
            ],
        ];
    }
}
```

### Advanced State Machine

```php
<?php

class PaymentStateMachine extends AbstractStateMachine
{
    // Disable history tracking if needed
    protected bool $recordHistory = false;

    public function transitions(): array
    {
        return [
            'authorize' => [
                'from' => 'pending',
                'to' => 'authorized',
            ],
            'capture' => [
                'from' => 'authorized',
                'to' => 'captured',
            ],
            'refund' => [
                'from' => ['captured', 'partial_refund'],
                'to' => 'refunded',
            ],
            'partial_refund' => [
                'from' => 'captured',
                'to' => 'partial_refund',
            ],
            'void' => [
                'from' => ['pending', 'authorized'],
                'to' => 'voided',
            ],
        ];
    }

    public function stateLabels(): array
    {
        return [
            'pending' => 'Payment Pending',
            'authorized' => 'Payment Authorized',
            'captured' => 'Payment Captured',
            'partial_refund' => 'Partially Refunded',
            'refunded' => 'Fully Refunded',
            'voided' => 'Payment Voided',
        ];
    }
}
```

## Model Integration

### Single State Machine

```php
class Order extends Model
{
    use HasStateMachine;

    protected $stateMachines = [
        'status' => OrderStatusStateMachine::class,
    ];
}
```

### Multiple State Machines

```php
class Order extends Model
{
    use HasStateMachine;

    protected $stateMachines = [
        'status' => OrderStatusStateMachine::class,
        'payment_status' => PaymentStatusStateMachine::class,
        'shipping_status' => ShippingStatusStateMachine::class,
    ];
}
```

### Usage with Multiple State Machines

```php
$order = new Order();

// Access different state machines
$orderStatus = $order->stateMachine('status');
$paymentStatus = $order->stateMachine('payment_status');
$shippingStatus = $order->stateMachine('shipping_status');

// Check states
$orderStatus->is('pending');
$paymentStatus->is('captured');
$shippingStatus->is('shipped');
```

## Available Methods

### State Checking

```php
$stateMachine = $order->stateMachine('status');

// Get current state
$current = $stateMachine->currentState(); // 'pending'

// Check if in specific state
$isPending = $stateMachine->is('pending'); // true/false

// Check if in any of multiple states
$inProgress = $stateMachine->in(['processing', 'shipped']); // true/false
```

### State Information

```php
// Get all possible states
$allStates = $stateMachine->states();
// Collection: ['pending', 'processing', 'shipped', 'delivered', 'cancelled']

// Get state label
$label = $stateMachine->getStateLabel('pending'); // 'Pending Payment'

// Get current state label
$currentLabel = $stateMachine->getCurrentStateLabel(); // 'Pending Payment'

// Get default state
$default = $stateMachine->getDefaultState(); // 'pending'
```

### Dynamic Methods

```php
// Access state machine via dynamic methods
$order->status(); // Returns StateWrapper for 'status' field
$order->paymentStatus(); // Returns StateWrapper for 'payment_status' field
$order->shippingStatus(); // Returns StateWrapper for 'shipping_status' field
```

## State History

State machines automatically track state changes in the `StateHistory` model.

### Querying State History

```php
// Get all state history for a model
$history = $order->stateHistory()->get();

// Get history for specific field
$statusHistory = $order->stateHistory()->forField('status')->get();

// Check if model was ever in a state
$wasShipped = $order->wasInState('shipped', 'status'); // true/false

// Count times in state
$timesPending = $order->timesInState('pending', 'status'); // integer

// Get timestamp when last in state
$lastShipped = $order->whenInState('shipped', 'status'); // Carbon instance or null
```

### Query Scopes

```php
// Find orders that were shipped
$shippedOrders = Order::whereWasState('shipped', 'status')->get();

// Find orders never cancelled
$neverCancelled = Order::whereNeverWasState('cancelled', 'status')->get();

// Find orders with status history
$withHistory = Order::whereHasStateHistory('status')->get();
```

## Events

State machines fire events when states change:

### Listening to Events

```php
// Listen to all state machine transitions
Event::listen('eloquent.state-machine.transitioned', function ($event) {
    $model = $event['model'];
    $field = $event['field'];
    $from = $event['from'];
    $to = $event['to'];
    
    Log::info("State changed from {$from} to {$to} for {$field}");
});

// Listen to specific model/field transitions
Event::listen('eloquent.state-machine.transitioned.App\Models\Order.status', function ($event) {
    $order = $event['model'];
    
    if ($event['to'] === 'shipped') {
        // Send shipping notification
        $order->customer->notify(new OrderShippedNotification($order));
    }
});
```

### Event Payload

```php
[
    'model' => $modelInstance,
    'field' => 'status',
    'from' => 'processing',
    'to' => 'shipped',
    'payload' => [], // Additional data passed during transition
    'state_machine' => 'App\StateMachines\OrderStatusStateMachine',
]
```

## Advanced Usage

### Conditional State Labels

```php
class DynamicStateMachine extends AbstractStateMachine
{
    public function stateLabels(): array
    {
        $labels = [
            'draft' => 'Draft',
            'published' => 'Published',
        ];
        
        // Add conditional labels based on model
        if ($this->getModel()->is_featured) {
            $labels['featured'] = 'Featured Content';
        }
        
        return $labels;
    }
}
```

### Custom Default State Logic

```php
class SmartDefaultStateMachine extends AbstractStateMachine
{
    public function getDefaultState(): string
    {
        // Custom logic for default state
        if ($this->getModel()->is_premium) {
            return 'premium_pending';
        }
        
        return 'standard_pending';
    }
}
```

### Disable History Tracking

```php
class NoHistoryStateMachine extends AbstractStateMachine
{
    protected bool $recordHistory = false;
    
    public function shouldRecordHistory(): bool
    {
        return false;
    }
}
```

## Examples

### E-commerce Order Management

```php
// Create order with multiple state machines
$order = Order::create([
    'customer_id' => 1,
    'total' => 199.99,
    'status' => 'pending',           // Order status
    'payment_status' => 'pending',   // Payment status
    'shipping_status' => 'pending',  // Shipping status
]);

// Process payment
if ($order->stateMachine('payment_status')->is('pending')) {
    // Payment processing logic...
    $order->payment_status = 'captured';
    $order->save();
}

// Start order processing
if ($order->stateMachine('status')->is('pending') && 
    $order->stateMachine('payment_status')->is('captured')) {
    $order->status = 'processing';
    $order->save();
}

// Ship order
if ($order->stateMachine('status')->is('processing')) {
    $order->status = 'shipped';
    $order->shipping_status = 'in_transit';
    $order->save();
}
```

### Document Management

```php
class DocumentStateMachine extends AbstractStateMachine
{
    public function transitions(): array
    {
        return [
            'submit' => ['from' => 'draft', 'to' => 'submitted'],
            'approve' => ['from' => 'submitted', 'to' => 'approved'],
            'reject' => ['from' => 'submitted', 'to' => 'rejected'],
            'publish' => ['from' => 'approved', 'to' => 'published'],
            'archive' => ['from' => ['published', 'rejected'], 'to' => 'archived'],
        ];
    }

    public function stateLabels(): array
    {
        return [
            'draft' => 'Draft Document',
            'submitted' => 'Submitted for Review',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'published' => 'Published',
            'archived' => 'Archived',
        ];
    }
}

// Usage
$document = Document::create(['title' => 'New Document', 'status' => 'draft']);

// Check if ready for submission
if ($document->stateMachine('status')->is('draft') && $document->isComplete()) {
    $document->status = 'submitted';
    $document->save();
}
```

### User Account Status

```php
class UserStatusStateMachine extends AbstractStateMachine
{
    public function transitions(): array
    {
        return [
            'activate' => ['from' => 'pending', 'to' => 'active'],
            'suspend' => ['from' => 'active', 'to' => 'suspended'],
            'reactivate' => ['from' => 'suspended', 'to' => 'active'],
            'deactivate' => ['from' => ['active', 'suspended'], 'to' => 'inactive'],
            'ban' => ['from' => ['active', 'suspended'], 'to' => 'banned'],
        ];
    }

    public function stateLabels(): array
    {
        return [
            'pending' => 'Account Pending',
            'active' => 'Active Account',
            'suspended' => 'Temporarily Suspended',
            'inactive' => 'Inactive Account',
            'banned' => 'Permanently Banned',
        ];
    }
}

// Usage
$user = User::create(['email' => 'user@example.com', 'status' => 'pending']);

// Activate after email verification
if ($user->hasVerifiedEmail() && $user->stateMachine('status')->is('pending')) {
    $user->status = 'active';
    $user->save();
}
```

## Best Practices

1. **Keep it Simple**: Use state machines for simple state tracking, workflows for complex processes
2. **Clear Naming**: Use descriptive state names and labels
3. **History Tracking**: Enable history tracking unless you have performance concerns
4. **Event Handling**: Use events for side effects like notifications
5. **Multiple Machines**: Use separate state machines for different aspects of your model
6. **Documentation**: Document your state transitions clearly
7. **Testing**: Test state transitions thoroughly

## Integration with Workflows

State machines work great alongside workflows:

```php
class Order extends Model implements Workflowable
{
    use HasWorkflow, HasStateMachine;

    // State machines for individual aspects
    protected $stateMachines = [
        'payment_status' => PaymentStatusStateMachine::class,
        'shipping_status' => ShippingStatusStateMachine::class,
    ];

    // Workflow for overall business process
    public function getWorkflowName(): string
    {
        return 'order_processing';
    }
}
```

This allows you to:
- Track individual state changes with state machines
- Orchestrate complex business processes with workflows
- Get the best of both approaches
