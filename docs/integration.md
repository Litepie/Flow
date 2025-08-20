# Integration Guide

This comprehensive guide covers all aspects of integrating Litepie Flow into your Laravel application, from basic setup to advanced implementation patterns.

## Table of Contents

- [Installation & Setup](#installation--setup)
- [Package Architecture](#package-architecture)
- [Laravel Integration](#laravel-integration)
- [Model Integration](#model-integration)
- [Service Provider Configuration](#service-provider-configuration)
- [Database Integration](#database-integration)
- [Event System Integration](#event-system-integration)
- [Action Integration](#action-integration)
- [Testing Integration](#testing-integration)
- [API Integration](#api-integration)
- [Advanced Integration Patterns](#advanced-integration-patterns)
- [Performance Considerations](#performance-considerations)
- [Troubleshooting](#troubleshooting)

## Installation & Setup

### Prerequisites

- Laravel 10.x or 11.x
- PHP 8.1+
- MySQL 5.7+ / PostgreSQL 9.6+ / SQLite 3.8.8+

### Step-by-Step Installation

#### 1. Install the Package

```bash
composer require litepie/flow
```

> **Note**: This automatically installs the required `litepie/actions` dependency.

#### 2. Publish and Run Migrations

```bash
# Publish migrations
php artisan vendor:publish --tag="flow-migrations"

# Run migrations
php artisan migrate
```

#### 3. Publish Configuration (Optional)

```bash
php artisan vendor:publish --tag="flow-config"
```

#### 4. Verify Installation

```bash
php artisan list | grep flow
```

You should see the `flow:process-pending` command available.

## Package Architecture

### Core Components

The Litepie Flow package is built around several key components:

```
Litepie\Flow\
├── Contracts/           # Interfaces and contracts
├── Events/             # Workflow events
├── Exceptions/         # Custom exceptions
├── Facades/            # Laravel facades
├── Models/             # Eloquent models
├── States/             # State management
├── Transitions/        # Transition logic
├── Traits/             # Reusable traits
├── Workflows/          # Workflow definitions
└── Commands/           # Artisan commands
```

### Dependency Overview

```php
// External dependencies
"litepie/actions": "^1.0"        // Action pattern implementation
"illuminate/support": "^10.0|^11.0"
"illuminate/database": "^10.0|^11.0"
"illuminate/events": "^10.0|^11.0"
"illuminate/contracts": "^10.0|^11.0"
```

## Laravel Integration

### Service Provider Registration

The package automatically registers its service provider through Laravel's package discovery:

```php
// config/app.php (automatic via package discovery)
'providers' => [
    Litepie\Flow\FlowServiceProvider::class,
],

'aliases' => [
    'Flow' => Litepie\Flow\Facades\Flow::class,
],
```

### Configuration Integration

After publishing the config file, customize it for your application:

```php
// config/flow.php
return [
    'default_workflow' => env('FLOW_DEFAULT_WORKFLOW', null),
    
    'storage' => [
        'driver' => env('FLOW_STORAGE_DRIVER', 'database'),
        'table_prefix' => env('FLOW_TABLE_PREFIX', 'flow_'),
    ],
    
    'events' => [
        'enabled' => env('FLOW_EVENTS_ENABLED', true),
        'queue' => env('FLOW_EVENTS_QUEUE', null),
    ],
    
    'workflows' => [
        // Register your workflows here
        'order_processing' => [
            'class' => App\Workflows\OrderProcessingWorkflow::class,
            'method' => 'create',
        ],
        'user_registration' => [
            'class' => App\Workflows\UserRegistrationWorkflow::class,
            'method' => 'create',
        ],
    ],
];
```

### Environment Variables

Add these to your `.env` file:

```env
# Flow Configuration
FLOW_DEFAULT_WORKFLOW=order_processing
FLOW_STORAGE_DRIVER=database
FLOW_TABLE_PREFIX=flow_
FLOW_EVENTS_ENABLED=true
FLOW_EVENTS_QUEUE=default
```

## Model Integration

### Basic Model Setup

Transform your Eloquent models to support workflows:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Litepie\Flow\Traits\HasWorkflow;
use Litepie\Flow\Contracts\Workflowable;

class Order extends Model implements Workflowable
{
    use HasWorkflow;

    protected $fillable = [
        'customer_id',
        'total',
        'state',
        'priority'
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Required by Workflowable interface
    public function getWorkflowName(): string
    {
        return 'order_processing';
    }

    // Required by HasWorkflow trait
    protected function getWorkflowStateColumn(): string
    {
        return 'state';
    }

    // Relationships
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }
}
```

### Advanced Model Integration with State Machine

For more complex state management, use the `HasStateMachine` trait:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Litepie\Flow\Traits\HasWorkflow;
use Litepie\Flow\Traits\HasStateMachine;
use Litepie\Flow\Contracts\Workflowable;

class Order extends Model implements Workflowable
{
    use HasWorkflow, HasStateMachine;

    protected $fillable = [
        'customer_id',
        'total',
        'state',
        'payment_status',
        'shipping_status'
    ];

    // Define multiple state machines
    protected $stateMachines = [
        'state' => App\StateMachines\OrderStateMachine::class,
        'payment_status' => App\StateMachines\PaymentStateMachine::class,
        'shipping_status' => App\StateMachines\ShippingStateMachine::class,
    ];

    public function getWorkflowName(): string
    {
        return 'order_processing';
    }

    protected function getWorkflowStateColumn(): string
    {
        return 'state';
    }
}
```

### Model Migration Setup

Create migrations for your workflowable models:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->decimal('total', 10, 2);
            
            // Workflow state columns
            $table->string('state')->default('pending');
            $table->string('payment_status')->default('pending');
            $table->string('shipping_status')->default('not_shipped');
            
            // Additional fields
            $table->string('priority')->default('normal');
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            // Indexes for workflow queries
            $table->index(['state', 'created_at']);
            $table->index(['payment_status']);
            $table->index(['shipping_status']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('orders');
    }
};
```

## Service Provider Configuration

### Creating a Workflow Service Provider

Create a dedicated service provider for your workflows:

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Litepie\Flow\Facades\Flow;
use App\Workflows\OrderWorkflow;
use App\Workflows\UserRegistrationWorkflow;
use App\Workflows\DocumentApprovalWorkflow;

class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register workflow-related services
        $this->app->singleton('app.workflows', function ($app) {
            return new \App\Services\WorkflowService();
        });
    }

    public function boot(): void
    {
        // Register workflows
        $this->registerWorkflows();
        
        // Register event listeners
        $this->registerEventListeners();
        
        // Register custom guards and actions
        $this->registerWorkflowComponents();
    }

    protected function registerWorkflows(): void
    {
        // Single workflow registration
        Flow::register('order_processing', OrderWorkflow::create());
        
        // Bulk workflow registration
        Flow::registerMany([
            'user_registration' => UserRegistrationWorkflow::create(),
            'document_approval' => DocumentApprovalWorkflow::create([
                'max_approval_levels' => 3,
                'auto_approve_threshold' => 1000,
            ]),
        ]);
    }

    protected function registerEventListeners(): void
    {
        // Register global workflow event listeners
        $this->app['events']->listen(
            'workflow.*.transition.*',
            \App\Listeners\WorkflowAuditLogger::class
        );
        
        // Register specific workflow listeners
        $this->app['events']->listen(
            'workflow.order_processing.entered.shipped',
            \App\Listeners\SendShippingNotification::class
        );
    }

    protected function registerWorkflowComponents(): void
    {
        // Register custom guards
        $this->app->bind('order.payment.guard', function () {
            return new \App\Guards\PaymentCompletedGuard();
        });
        
        // Register custom actions
        $this->app->bind('order.payment.action', function () {
            return new \App\Actions\ProcessPaymentAction(
                app(\App\Services\PaymentService::class),
                app(\App\Services\InventoryService::class)
            );
        });
    }
}
```

Register your service provider in `config/app.php`:

```php
'providers' => [
    // Other providers...
    App\Providers\WorkflowServiceProvider::class,
],
```

## Database Integration

### Database Schema Overview

The package creates the following tables:

```sql
-- Workflow definitions
flow_workflows (id, name, label, metadata, timestamps)

-- State definitions  
flow_states (id, workflow_id, name, label, initial, final, metadata, timestamps)

-- Transition definitions
flow_transitions (id, workflow_id, from_state, to_state, event, label, metadata, timestamps)

-- Workflow execution history
flow_executions (id, workflow_name, state_from, state_to, model_type, model_id, context, timestamps)

-- State history tracking
flow_state_histories (id, model_type, model_id, field, from, to, transition, custom_properties, causer_type, causer_id, timestamps)

-- Scheduled/pending transitions
flow_pending_transitions (id, model_type, model_id, field, from, to, transition, custom_properties, causer_type, causer_id, apply_at, applied_at, timestamps)
```

### Custom Table Configuration

Customize table names and prefixes:

```php
// config/flow.php
'storage' => [
    'driver' => 'database',
    'table_prefix' => 'workflow_', // Changes flow_ to workflow_
    'connection' => 'mysql', // Use specific database connection
],
```

### Database Queries and Relationships

#### Querying Workflow History

```php
// Get workflow execution history for a model
$order = Order::find(1);
$executions = DB::table('flow_executions')
    ->where('model_type', Order::class)
    ->where('model_id', $order->id)
    ->orderBy('created_at')
    ->get();

// Get state history using relationships
$order->stateHistory()
    ->forField('state')
    ->orderBy('created_at', 'desc')
    ->get();
```

#### Advanced Workflow Queries

```php
// Find orders that transitioned today
$todaysTransitions = Order::whereHas('stateHistory', function ($query) {
    $query->where('created_at', '>=', today());
})->get();

// Find orders that were never in 'cancelled' state
$neverCancelled = Order::whereNeverWasStatus('cancelled')->get();

// Find orders that spent more than 2 days in 'processing'
$slowProcessing = Order::whereHas('stateHistory', function ($query) {
    $query->forField('state')
        ->where('to', 'processing')
        ->where('created_at', '<=', now()->subDays(2));
})->get();
```

### Database Performance Optimization

#### Indexing Strategy

```php
// Add indexes for common workflow queries
Schema::table('orders', function (Blueprint $table) {
    $table->index(['state', 'created_at']);
    $table->index(['state', 'updated_at']);
    $table->index(['customer_id', 'state']);
});

// Optimize state history table
Schema::table('flow_state_histories', function (Blueprint $table) {
    $table->index(['model_type', 'model_id', 'field']);
    $table->index(['created_at']);
    $table->index(['to', 'created_at']);
});
```

#### Query Optimization

```php
// Use eager loading for workflow relationships
$orders = Order::with([
    'stateHistory' => function ($query) {
        $query->forField('state')->latest()->limit(5);
    },
    'customer'
])->get();

// Batch processing for large datasets
Order::chunk(100, function ($orders) {
    foreach ($orders as $order) {
        if ($order->canTransitionTo('shipped')) {
            $order->transitionTo('shipped');
        }
    }
});
```

## Event System Integration

### Event Listeners Setup

Create event listeners for workflow transitions:

```php
<?php

namespace App\Listeners;

use Litepie\Flow\Events\WorkflowTransitioned;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class OrderWorkflowListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(WorkflowTransitioned $event): void
    {
        $order = $event->getSubject();
        $fromState = $event->getFromState();
        $toState = $event->getToState();
        $context = $event->getContext();

        // Handle different state transitions
        match ($toState) {
            'processing' => $this->handleProcessingState($order, $context),
            'shipped' => $this->handleShippedState($order, $context),
            'delivered' => $this->handleDeliveredState($order, $context),
            'cancelled' => $this->handleCancelledState($order, $context),
            default => $this->handleGenericTransition($order, $fromState, $toState),
        };
    }

    private function handleProcessingState($order, $context): void
    {
        // Send processing notification
        $order->customer->notify(new OrderProcessingNotification($order));
        
        // Create inventory reservation
        app(\App\Services\InventoryService::class)->reserve($order);
        
        // Log audit trail
        activity()
            ->performedOn($order)
            ->log('Order moved to processing state');
    }

    private function handleShippedState($order, $context): void
    {
        // Generate tracking number
        $trackingNumber = app(\App\Services\ShippingService::class)
            ->generateTrackingNumber($order);
            
        $order->update(['tracking_number' => $trackingNumber]);
        
        // Send shipping notification
        $order->customer->notify(new OrderShippedNotification($order));
    }
}
```

### Event Service Provider Configuration

```php
<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        // Workflow-specific events
        'workflow.order_processing.transition.process' => [
            \App\Listeners\ProcessOrderPayment::class,
        ],
        'workflow.order_processing.entered.shipped' => [
            \App\Listeners\SendShippingNotification::class,
            \App\Listeners\UpdateInventory::class,
        ],
        'workflow.order_processing.entered.cancelled' => [
            \App\Listeners\RefundPayment::class,
            \App\Listeners\ReleaseInventory::class,
        ],
        
        // Generic workflow events
        \Litepie\Flow\Events\WorkflowTransitioned::class => [
            \App\Listeners\OrderWorkflowListener::class,
            \App\Listeners\WorkflowAuditLogger::class,
        ],
        
        // Action events
        'workflow.action.failed' => [
            \App\Listeners\WorkflowActionFailureHandler::class,
        ],
    ];

    protected $subscribe = [
        \App\Listeners\WorkflowEventSubscriber::class,
    ];
}
```

### Event Subscriber Pattern

For complex event handling, use event subscribers:

```php
<?php

namespace App\Listeners;

use Illuminate\Events\Dispatcher;
use Litepie\Flow\Events\WorkflowTransitioned;

class WorkflowEventSubscriber
{
    public function subscribe(Dispatcher $events): void
    {
        // Subscribe to all workflow events
        $events->listen(
            'workflow.*',
            [static::class, 'handleWorkflowEvent']
        );
        
        // Subscribe to specific patterns
        $events->listen(
            'workflow.*.entered.*',
            [static::class, 'handleStateEntered']
        );
        
        $events->listen(
            'workflow.*.transition.*',
            [static::class, 'handleTransition']
        );
    }

    public function handleWorkflowEvent($eventName, $payload): void
    {
        // Parse event name
        $parts = explode('.', $eventName);
        $workflowName = $parts[1] ?? null;
        $eventType = $parts[2] ?? null;
        $stateName = $parts[3] ?? null;

        // Log all workflow events
        logger()->info('Workflow event occurred', [
            'event' => $eventName,
            'workflow' => $workflowName,
            'type' => $eventType,
            'state' => $stateName,
            'payload' => $payload,
        ]);
    }

    public function handleStateEntered($eventName, $payload): void
    {
        // Handle all state entry events
        [$subject, $fromState, $toState, $context] = $payload;
        
        // Send notifications for important states
        if (in_array($toState, ['shipped', 'delivered', 'cancelled'])) {
            $this->sendStateChangeNotification($subject, $toState);
        }
    }
}
```

## Action Integration

### Creating Workflow Actions

Actions integrate with the Litepie Actions package:

```php
<?php

namespace App\Actions\Order;

use Litepie\Actions\BaseAction;
use Litepie\Actions\Traits\ValidatesInput;
use Litepie\Actions\Contracts\ActionResult;
use App\Services\PaymentService;
use App\Services\InventoryService;
use App\Services\NotificationService;

class ProcessOrderAction extends BaseAction
{
    use ValidatesInput;

    protected string $name = 'process_order';

    public function __construct(
        private PaymentService $paymentService,
        private InventoryService $inventoryService,
        private NotificationService $notificationService
    ) {}

    public function execute(array $context = []): ActionResult
    {
        $validated = $this->validateContext($context);
        $order = $validated['order'];

        try {
            // Start database transaction
            return DB::transaction(function () use ($order, $validated) {
                
                // Process payment
                $paymentResult = $this->paymentService->charge(
                    $order->payment_method,
                    $order->total,
                    $validated['payment_details'] ?? []
                );

                if (!$paymentResult->isSuccessful()) {
                    return $this->failure([
                        'payment_error' => $paymentResult->getError(),
                        'error_code' => $paymentResult->getErrorCode(),
                    ], 'Payment processing failed');
                }

                // Reserve inventory
                $inventoryResult = $this->inventoryService->reserve(
                    $order->items->toArray()
                );

                if (!$inventoryResult->isSuccessful()) {
                    // Rollback payment
                    $this->paymentService->refund($paymentResult->getTransactionId());
                    
                    return $this->failure([
                        'inventory_error' => $inventoryResult->getError(),
                        'payment_refunded' => true,
                    ], 'Insufficient inventory');
                }

                // Update order
                $order->update([
                    'transaction_id' => $paymentResult->getTransactionId(),
                    'reservation_id' => $inventoryResult->getReservationId(),
                    'processed_at' => now(),
                ]);

                // Send confirmation
                $this->notificationService->sendOrderConfirmation($order);

                return $this->success([
                    'transaction_id' => $paymentResult->getTransactionId(),
                    'reservation_id' => $inventoryResult->getReservationId(),
                    'processed_at' => $order->processed_at,
                ], 'Order processed successfully');
                
            });
            
        } catch (\Exception $e) {
            logger()->error('Order processing failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->failure([
                'error' => $e->getMessage(),
                'order_id' => $order->id,
            ], 'Order processing failed due to system error');
        }
    }

    protected function rules(): array
    {
        return [
            'order' => 'required',
            'order.id' => 'required|integer',
            'order.total' => 'required|numeric|min:0.01',
            'order.payment_method' => 'required|string',
            'payment_details' => 'array',
            'retry_count' => 'integer|min:0|max:3',
        ];
    }

    protected function messages(): array
    {
        return [
            'order.total.min' => 'Order total must be at least $0.01',
            'retry_count.max' => 'Maximum retry attempts exceeded',
        ];
    }
}
```

### Conditional Actions

Create actions that execute based on conditions:

```php
<?php

namespace App\Actions\Order;

use Litepie\Actions\BaseAction;
use Litepie\Actions\Contracts\ActionResult;

class ConditionalShippingAction extends BaseAction
{
    public function execute(array $context = []): ActionResult
    {
        $order = $context['order'];
        $shippingMethod = $this->determineShippingMethod($order);
        
        return match($shippingMethod) {
            'express' => $this->processExpressShipping($order),
            'standard' => $this->processStandardShipping($order),
            'pickup' => $this->processPickupOrder($order),
            default => $this->failure(['method' => $shippingMethod], 'Invalid shipping method'),
        };
    }

    private function determineShippingMethod($order): string
    {
        if ($order->total > 500) {
            return 'express';
        }
        
        if ($order->customer->preferred_shipping === 'pickup') {
            return 'pickup';
        }
        
        return 'standard';
    }

    private function processExpressShipping($order): ActionResult
    {
        // Express shipping logic
        $trackingNumber = app(\App\Services\ExpressShippingService::class)
            ->createShipment($order);
            
        return $this->success([
            'shipping_method' => 'express',
            'tracking_number' => $trackingNumber,
            'estimated_delivery' => now()->addDay(),
        ]);
    }
}
```

### Action Middleware

Implement action middleware for cross-cutting concerns:

```php
<?php

namespace App\Actions\Middleware;

use Litepie\Actions\Contracts\ActionContract;
use Litepie\Actions\Contracts\ActionResult;
use Closure;

class AuditMiddleware
{
    public function handle(ActionContract $action, Closure $next, array $context = []): ActionResult
    {
        $startTime = microtime(true);
        
        // Log action start
        activity()
            ->withProperties([
                'action' => get_class($action),
                'context' => $this->sanitizeContext($context),
            ])
            ->log('Action started');

        // Execute action
        $result = $next($action, $context);
        
        $duration = microtime(true) - $startTime;
        
        // Log action completion
        activity()
            ->withProperties([
                'action' => get_class($action),
                'success' => $result->isSuccessful(),
                'duration' => $duration,
                'result' => $result->toArray(),
            ])
            ->log('Action completed');

        return $result;
    }

    private function sanitizeContext(array $context): array
    {
        // Remove sensitive data before logging
        unset($context['password'], $context['payment_details']);
        return $context;
    }
}
```

## Testing Integration

### Unit Testing Workflows

```php
<?php

namespace Tests\Unit\Workflows;

use Tests\TestCase;
use App\Models\Order;
use App\Workflows\OrderWorkflow;
use Litepie\Flow\Facades\Flow;

class OrderWorkflowTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        
        // Register workflow for testing
        Flow::register('order_processing', OrderWorkflow::create());
    }

    public function test_order_can_transition_from_pending_to_processing(): void
    {
        $order = Order::factory()->create(['state' => 'pending']);
        
        $this->assertTrue($order->canTransitionTo('processing'));
        
        $result = $order->transitionTo('processing', [
            'payment_method' => 'credit_card',
            'amount' => $order->total,
        ]);
        
        $this->assertTrue($result);
        $this->assertEquals('processing', $order->fresh()->state);
    }

    public function test_order_cannot_transition_to_invalid_state(): void
    {
        $order = Order::factory()->create(['state' => 'pending']);
        
        $this->assertFalse($order->canTransitionTo('delivered'));
        
        $result = $order->transitionTo('delivered');
        
        $this->assertFalse($result);
        $this->assertEquals('pending', $order->fresh()->state);
    }

    public function test_workflow_tracks_state_history(): void
    {
        $order = Order::factory()->create(['state' => 'pending']);
        
        $order->transitionTo('processing');
        $order->transitionTo('shipped');
        
        $history = $order->stateHistory()->forField('state')->get();
        
        $this->assertCount(2, $history);
        $this->assertEquals('pending', $history->first()->from);
        $this->assertEquals('processing', $history->first()->to);
    }
}
```

### Feature Testing with Workflows

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Order;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

class OrderProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_processing_workflow_integration(): void
    {
        Event::fake();
        
        $customer = Customer::factory()->create();
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'state' => 'pending',
            'total' => 99.99,
        ]);

        // Test API endpoint for processing order
        $response = $this->postJson("/api/orders/{$order->id}/process", [
            'payment_method' => 'credit_card',
            'card_token' => 'tok_test_123',
        ]);

        $response->assertStatus(200)
                ->assertJson(['status' => 'success']);

        // Verify state transition
        $this->assertEquals('processing', $order->fresh()->state);

        // Verify events were dispatched
        Event::assertDispatched('workflow.order_processing.transition.process');
    }

    public function test_failed_payment_keeps_order_in_pending_state(): void
    {
        $order = Order::factory()->create(['state' => 'pending']);

        // Mock payment service to fail
        $this->mock(\App\Services\PaymentService::class)
            ->shouldReceive('charge')
            ->andReturn(new \App\Services\PaymentResult(false, 'Insufficient funds'));

        $response = $this->postJson("/api/orders/{$order->id}/process", [
            'payment_method' => 'credit_card',
            'card_token' => 'tok_fail_123',
        ]);

        $response->assertStatus(422);
        $this->assertEquals('pending', $order->fresh()->state);
    }
}
```

### Testing Actions

```php
<?php

namespace Tests\Unit\Actions;

use Tests\TestCase;
use App\Actions\Order\ProcessOrderAction;
use App\Models\Order;
use App\Services\PaymentService;
use App\Services\InventoryService;
use Mockery;

class ProcessOrderActionTest extends TestCase
{
    public function test_successful_order_processing(): void
    {
        $paymentService = Mockery::mock(PaymentService::class);
        $inventoryService = Mockery::mock(InventoryService::class);
        
        $order = Order::factory()->create();
        
        $paymentService->shouldReceive('charge')
            ->once()
            ->andReturn(new \App\Services\PaymentResult(true, 'txn_123'));
            
        $inventoryService->shouldReceive('reserve')
            ->once()
            ->andReturn(new \App\Services\InventoryResult(true, 'res_456'));

        $action = new ProcessOrderAction($paymentService, $inventoryService, app());
        
        $result = $action->execute(['order' => $order]);
        
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals('Order processed successfully', $result->getMessage());
        $this->assertArrayHasKey('transaction_id', $result->getData());
    }

    public function test_inventory_failure_triggers_payment_rollback(): void
    {
        $paymentService = Mockery::mock(PaymentService::class);
        $inventoryService = Mockery::mock(InventoryService::class);
        
        $order = Order::factory()->create();
        
        $paymentService->shouldReceive('charge')
            ->once()
            ->andReturn(new \App\Services\PaymentResult(true, 'txn_123'));
            
        $inventoryService->shouldReceive('reserve')
            ->once()
            ->andReturn(new \App\Services\InventoryResult(false, 'Out of stock'));
            
        $paymentService->shouldReceive('refund')
            ->once()
            ->with('txn_123');

        $action = new ProcessOrderAction($paymentService, $inventoryService, app());
        
        $result = $action->execute(['order' => $order]);
        
        $this->assertFalse($result->isSuccessful());
        $this->assertEquals('Insufficient inventory', $result->getMessage());
    }
}
```

### Database Testing

```php
<?php

namespace Tests\Feature\Database;

use Tests\TestCase;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WorkflowDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_history_is_recorded(): void
    {
        $order = Order::factory()->create(['state' => 'pending']);
        
        $order->transitionTo('processing');
        
        $this->assertDatabaseHas('flow_state_histories', [
            'model_type' => Order::class,
            'model_id' => $order->id,
            'field' => 'state',
            'from' => 'pending',
            'to' => 'processing',
        ]);
    }

    public function test_pending_transitions_are_scheduled(): void
    {
        $order = Order::factory()->create(['state' => 'shipped']);
        
        $order->scheduleTransition('delivered', now()->addDays(3));
        
        $this->assertDatabaseHas('flow_pending_transitions', [
            'model_type' => Order::class,
            'model_id' => $order->id,
            'from' => 'shipped',
            'to' => 'delivered',
        ]);
    }
}
```

## API Integration

### RESTful API Endpoints

Create API endpoints for workflow operations:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class OrderWorkflowController extends Controller
{
    public function show(Order $order): JsonResponse
    {
        return response()->json([
            'current_state' => $order->getCurrentState()?->getName(),
            'available_transitions' => collect($order->getAvailableTransitions())
                ->map(fn($transition) => [
                    'from' => $transition->getFrom(),
                    'to' => $transition->getTo(),
                    'event' => $transition->getEvent(),
                    'label' => $transition->getLabel(),
                ])->values(),
            'history' => $order->stateHistory()
                ->forField('state')
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn($history) => [
                    'from' => $history->from,
                    'to' => $history->to,
                    'transition' => $history->transition,
                    'created_at' => $history->created_at,
                ]),
        ]);
    }

    public function transition(Request $request, Order $order): JsonResponse
    {
        $request->validate([
            'transition' => 'required|string',
            'context' => 'array',
        ]);

        $transition = $request->input('transition');
        $context = $request->input('context', []);

        if (!$order->canTransitionTo($transition)) {
            return response()->json([
                'error' => 'Invalid transition',
                'message' => "Cannot transition from {$order->getCurrentState()?->getName()} to {$transition}",
            ], 422);
        }

        try {
            $success = $order->transitionTo($transition, $context);

            if ($success) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Transition completed successfully',
                    'current_state' => $order->fresh()->getCurrentState()?->getName(),
                ]);
            } else {
                return response()->json([
                    'error' => 'Transition failed',
                    'message' => 'The transition could not be completed',
                ], 422);
            }
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Transition error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function history(Order $order): JsonResponse
    {
        $history = $order->stateHistory()
            ->forField('state')
            ->with('causer')
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => $history->items(),
            'pagination' => [
                'current_page' => $history->currentPage(),
                'last_page' => $history->lastPage(),
                'per_page' => $history->perPage(),
                'total' => $history->total(),
            ],
        ]);
    }
}
```

### API Routes

```php
<?php

// routes/api.php
use App\Http\Controllers\Api\OrderWorkflowController;

Route::prefix('orders/{order}')->group(function () {
    Route::get('workflow', [OrderWorkflowController::class, 'show']);
    Route::post('workflow/transition', [OrderWorkflowController::class, 'transition']);
    Route::get('workflow/history', [OrderWorkflowController::class, 'history']);
});

// Workflow management routes
Route::prefix('workflows')->group(function () {
    Route::get('/', [WorkflowController::class, 'index']);
    Route::get('{workflow}', [WorkflowController::class, 'show']);
    Route::get('{workflow}/states', [WorkflowController::class, 'states']);
    Route::get('{workflow}/transitions', [WorkflowController::class, 'transitions']);
});
```

### API Resources

Create API resources for consistent responses:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderWorkflowResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'state' => [
                'current' => $this->getCurrentState()?->getName(),
                'label' => $this->getCurrentState()?->getLabel(),
            ],
            'transitions' => [
                'available' => collect($this->getAvailableTransitions())
                    ->map(fn($transition) => [
                        'to' => $transition->getTo(),
                        'event' => $transition->getEvent(),
                        'label' => $transition->getLabel(),
                    ])->values(),
            ],
            'history' => StateHistoryResource::collection(
                $this->whenLoaded('stateHistory')
            ),
            'metadata' => [
                'created_at' => $this->created_at,
                'updated_at' => $this->updated_at,
                'workflow_name' => $this->getWorkflowName(),
            ],
        ];
    }
}
```

### Webhook Integration

Implement webhooks for workflow events:

```php
<?php

namespace App\Listeners;

use Litepie\Flow\Events\WorkflowTransitioned;
use Illuminate\Support\Facades\Http;

class WorkflowWebhookListener
{
    public function handle(WorkflowTransitioned $event): void
    {
        $webhookUrls = config('webhooks.workflow_transitions', []);
        
        $payload = [
            'event' => 'workflow.transitioned',
            'timestamp' => now()->toISOString(),
            'data' => [
                'workflow' => $event->getWorkflowName(),
                'entity' => [
                    'type' => get_class($event->getSubject()),
                    'id' => $event->getSubject()->getKey(),
                ],
                'transition' => [
                    'from' => $event->getFromState(),
                    'to' => $event->getToState(),
                ],
                'context' => $event->getContext(),
            ],
        ];

        foreach ($webhookUrls as $url) {
            $this->sendWebhook($url, $payload);
        }
    }

    private function sendWebhook(string $url, array $payload): void
    {
        try {
            Http::timeout(10)
                ->retry(3, 1000)
                ->post($url, $payload);
        } catch (\Exception $e) {
            logger()->error('Webhook delivery failed', [
                'url' => $url,
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);
        }
    }
}
```

## Advanced Integration Patterns

### Multi-Tenant Workflows

For multi-tenant applications:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Litepie\Flow\Traits\HasWorkflow;

class TenantOrder extends Model
{
    use HasWorkflow;

    public function getWorkflowName(): string
    {
        // Use tenant-specific workflow
        return "order_processing_{$this->tenant_id}";
    }

    protected function getWorkflowStateColumn(): string
    {
        return 'state';
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}

// Register tenant workflows dynamically
class TenantWorkflowProvider extends ServiceProvider
{
    public function boot(): void
    {
        Tenant::all()->each(function ($tenant) {
            Flow::register(
                "order_processing_{$tenant->id}",
                OrderWorkflow::createForTenant($tenant)
            );
        });
    }
}
```

### Nested Workflows

Implement complex workflows with sub-workflows:

```php
<?php

namespace App\Actions;

use Litepie\Actions\BaseAction;
use Litepie\Actions\Contracts\ActionResult;

class TriggerSubWorkflowAction extends BaseAction
{
    public function execute(array $context = []): ActionResult
    {
        $order = $context['order'];
        
        // Create payment sub-workflow
        $payment = $order->payments()->create([
            'amount' => $order->total,
            'state' => 'pending',
        ]);
        
        // Trigger payment workflow
        $paymentResult = $payment->transitionTo('process', [
            'payment_method' => $context['payment_method'],
        ]);
        
        if ($paymentResult) {
            return $this->success([
                'payment_id' => $payment->id,
                'sub_workflow' => 'payment_processing',
            ], 'Sub-workflow initiated');
        }
        
        return $this->failure([], 'Failed to initiate sub-workflow');
    }
}
```

### Conditional Workflow Registration

Register workflows based on environment or configuration:

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Litepie\Flow\Facades\Flow;

class ConditionalWorkflowProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Environment-specific workflows
        if (app()->environment('production')) {
            Flow::register('order_processing', ProductionOrderWorkflow::create());
        } else {
            Flow::register('order_processing', DevelopmentOrderWorkflow::create());
        }
        
        // Feature flag-based workflows
        if (config('features.advanced_fulfillment')) {
            Flow::register('fulfillment', AdvancedFulfillmentWorkflow::create());
        }
        
        // Dynamic workflow registration from database
        $this->registerDatabaseWorkflows();
    }

    private function registerDatabaseWorkflows(): void
    {
        \App\Models\WorkflowDefinition::active()->each(function ($definition) {
            Flow::register(
                $definition->name,
                $definition->createWorkflowInstance()
            );
        });
    }
}
```

### Workflow Middleware

Implement middleware for workflow operations:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class WorkflowAuthorizationMiddleware
{
    public function handle(Request $request, Closure $next, string $permission = null): mixed
    {
        $order = $request->route('order');
        $transition = $request->input('transition');
        
        // Check if user can perform this transition
        if (!$this->canPerformTransition($request->user(), $order, $transition)) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'You do not have permission to perform this transition',
            ], 403);
        }

        return $next($request);
    }

    private function canPerformTransition($user, $order, $transition): bool
    {
        // Implement your authorization logic
        return match($transition) {
            'cancel' => $user->can('cancel', $order),
            'process' => $user->can('process', $order),
            'ship' => $user->hasRole('warehouse_manager'),
            default => $user->can('update', $order),
        };
    }
}
```

## Performance Considerations

### Caching Strategies

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Litepie\Flow\Facades\Flow;

class CachedWorkflowService
{
    public function getWorkflow(string $name)
    {
        return Cache::remember(
            "workflow.{$name}",
            now()->addHours(1),
            fn() => Flow::get($name)
        );
    }

    public function getAvailableTransitions($model): array
    {
        $cacheKey = sprintf(
            'transitions.%s.%s.%s',
            get_class($model),
            $model->getKey(),
            $model->getCurrentState()?->getName()
        );

        return Cache::remember(
            $cacheKey,
            now()->addMinutes(15),
            fn() => $model->getAvailableTransitions()
        );
    }

    public function invalidateCache($model): void
    {
        $pattern = sprintf(
            'transitions.%s.%s.*',
            get_class($model),
            $model->getKey()
        );
        
        // Clear related cache entries
        Cache::flush(); // In production, use more targeted cache invalidation
    }
}
```

### Queue Integration

Use queues for long-running workflow operations:

```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessWorkflowTransition implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private $model,
        private string $transition,
        private array $context = []
    ) {}

    public function handle(): void
    {
        try {
            $this->model->transitionTo($this->transition, $this->context);
        } catch (\Exception $e) {
            // Handle failure and potentially retry
            logger()->error('Workflow transition failed', [
                'model' => get_class($this->model),
                'id' => $this->model->getKey(),
                'transition' => $this->transition,
                'error' => $e->getMessage(),
            ]);
            
            throw $e; // Re-throw to trigger job failure handling
        }
    }

    public function failed(\Throwable $exception): void
    {
        // Handle job failure
        // Could send notification, create audit log, etc.
    }
}

// Usage
ProcessWorkflowTransition::dispatch($order, 'process', $context)
    ->onQueue('workflow-transitions');
```

### Batch Processing

Process multiple workflow transitions efficiently:

```php
<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BatchWorkflowService
{
    public function bulkTransition(Collection $models, string $transition, array $context = []): array
    {
        $results = [];
        
        DB::transaction(function () use ($models, $transition, $context, &$results) {
            foreach ($models as $model) {
                try {
                    if ($model->canTransitionTo($transition)) {
                        $success = $model->transitionTo($transition, $context);
                        $results[$model->getKey()] = [
                            'success' => $success,
                            'new_state' => $model->getCurrentState()?->getName(),
                        ];
                    } else {
                        $results[$model->getKey()] = [
                            'success' => false,
                            'error' => 'Invalid transition',
                        ];
                    }
                } catch (\Exception $e) {
                    $results[$model->getKey()] = [
                        'success' => false,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        });
        
        return $results;
    }

    public function scheduleDelayedTransitions(Collection $models, string $transition, \Carbon\Carbon $when): void
    {
        foreach ($models as $model) {
            $model->scheduleTransition($transition, $when);
        }
    }
}
```

## Troubleshooting

### Common Issues and Solutions

#### 1. Workflow Not Found

**Error**: `WorkflowNotFoundException: Workflow 'order_processing' not found`

**Solution**:
```php
// Check if workflow is registered
if (!Flow::has('order_processing')) {
    // Register the workflow
    Flow::register('order_processing', OrderWorkflow::create());
}

// Verify service provider is loaded
// Check config/app.php providers array
```

#### 2. Invalid Transition

**Error**: `InvalidTransitionException: Cannot transition from 'pending' to 'delivered'`

**Solution**:
```php
// Check available transitions
$availableTransitions = $order->getAvailableTransitions();
dd($availableTransitions);

// Verify workflow definition
$workflow = Flow::get('order_processing');
dd($workflow->getTransitions());
```

#### 3. State Column Not Found

**Error**: `Column not found: 1054 Unknown column 'state'`

**Solution**:
```php
// Ensure your model has the state column
Schema::table('orders', function (Blueprint $table) {
    $table->string('state')->default('pending');
});

// Verify getWorkflowStateColumn() returns correct column name
protected function getWorkflowStateColumn(): string
{
    return 'state'; // Must match your database column
}
```

#### 4. Action Validation Fails

**Error**: Action validation errors

**Solution**:
```php
// Debug action validation
try {
    $action = new ProcessOrderAction();
    $result = $action->execute($context);
} catch (\Litepie\Actions\Exceptions\ValidationException $e) {
    dd($e->getErrors());
}

// Check action rules
protected function rules(): array
{
    return [
        'order' => 'required',
        'order.total' => 'required|numeric',
        // Add all required fields
    ];
}
```

### Debugging Tools

Create debugging tools for workflow troubleshooting:

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Litepie\Flow\Facades\Flow;

class DebugWorkflow extends Command
{
    protected $signature = 'workflow:debug {name} {--model-id=}';
    protected $description = 'Debug workflow configuration and state';

    public function handle(): void
    {
        $workflowName = $this->argument('name');
        
        if (!Flow::has($workflowName)) {
            $this->error("Workflow '{$workflowName}' not found");
            return;
        }
        
        $workflow = Flow::get($workflowName);
        
        $this->info("Workflow: {$workflow->getName()}");
        $this->info("States:");
        
        foreach ($workflow->getStates() as $state) {
            $this->line("  - {$state->getName()} ({$state->getLabel()})");
        }
        
        $this->info("Transitions:");
        
        foreach ($workflow->getTransitions() as $transition) {
            $this->line("  - {$transition->getFrom()} → {$transition->getTo()} ({$transition->getEvent()})");
        }
        
        if ($modelId = $this->option('model-id')) {
            $this->debugModelState($workflowName, $modelId);
        }
    }
    
    private function debugModelState(string $workflowName, int $modelId): void
    {
        // Implement model-specific debugging
        $this->info("\nModel State Debug:");
        // Add your model debugging logic here
    }
}
```

### Performance Monitoring

Monitor workflow performance:

```php
<?php

namespace App\Listeners;

use Litepie\Flow\Events\WorkflowTransitioned;
use Illuminate\Support\Facades\Cache;

class WorkflowPerformanceMonitor
{
    public function handle(WorkflowTransitioned $event): void
    {
        $key = "workflow.performance.{$event->getWorkflowName()}";
        
        $stats = Cache::get($key, [
            'total_transitions' => 0,
            'average_duration' => 0,
            'error_rate' => 0,
        ]);
        
        $stats['total_transitions']++;
        
        Cache::put($key, $stats, now()->addDay());
        
        // Log slow transitions
        if ($event->getDuration() > 5) { // 5 seconds
            logger()->warning('Slow workflow transition', [
                'workflow' => $event->getWorkflowName(),
                'transition' => $event->getTransition(),
                'duration' => $event->getDuration(),
            ]);
        }
    }
}
```

---

## Complete Integration Examples

The package includes comprehensive integration examples for various systems and use cases. Each integration category provides real-world examples, best practices, and configuration templates.

### 📦 Laravel Package Integrations
- **[Laravel Notification Integration](../examples/integrations/laravel-packages/NotificationIntegration.php)** - Send workflow notifications
- **[Spatie Activity Log Integration](../examples/integrations/laravel-packages/ActivityLogIntegration.php)** - Track workflow activities
- **[Laravel Permission Integration](../examples/integrations/laravel-packages/PermissionIntegration.php)** - Role-based workflow access

### 🌐 External API Integration
- **[REST API Integration](../examples/integrations/external-apis/RestApiIntegration.php)** - With retry logic and error handling

### 🗄️ Database Integration
- **[Multi-Database Operations](../examples/integrations/database/MultiDatabaseIntegration.php)** - Cross-database transactions

### ⚡ Queue Integration
- **[Advanced Queue Processing](../examples/integrations/queue/AdvancedQueueIntegration.php)** - Batch jobs and priorities

### 🎯 Event-Driven Integration
- **[Event Sourcing Integration](../examples/integrations/event-driven/EventSourcingIntegration.php)** - Event store patterns

### 🔗 Webhook Integration
- **[Outgoing Webhooks](../examples/integrations/webhooks/OutgoingWebhookIntegration.php)** - With signatures and verification
- **[Incoming Webhooks](../examples/integrations/webhooks/IncomingWebhookIntegration.php)** - Handler and verification
- **[Webhook Retry Logic](../examples/integrations/webhooks/WebhookRetryIntegration.php)** - Failure handling

### 🏗️ Microservices Integration
- **[Service Mesh Integration](../examples/integrations/microservices/ServiceMeshIntegration.php)** - Service discovery and load balancing

### 🏢 Enterprise Integration
- **[Enterprise Service Bus](../examples/integrations/enterprise/ESBIntegration.php)** - ESB pattern implementation and SAP integration

### 🔄 Advanced Integration Patterns
- **[Saga Pattern & Circuit Breakers](../examples/integrations/advanced-patterns/AdvancedPatternsIntegration.php)** - Distributed transactions, bulkhead pattern, and resilience patterns
- **[Composite State Machines](../examples/integrations/advanced-patterns/AdvancedPatternsIntegration.php)** - Multi-state machine coordination and temporal patterns
- **[Compensation Patterns](../examples/integrations/advanced-patterns/AdvancedPatternsIntegration.php)** - Error handling and recovery strategies

### ☁️ Cloud Platform Integration
- **[AWS Services Integration](../examples/integrations/cloud-platforms/CloudPlatformsIntegration.php)** - SQS, SNS, Lambda, S3, DynamoDB, EventBridge
- **[Google Cloud Platform](../examples/integrations/cloud-platforms/CloudPlatformsIntegration.php)** - Pub/Sub, Cloud Functions, Firestore, Cloud Storage
- **[Microsoft Azure](../examples/integrations/cloud-platforms/CloudPlatformsIntegration.php)** - Service Bus, Functions, Cosmos DB, Blob Storage
- **[Multi-Cloud Strategies](../examples/integrations/cloud-platforms/CloudPlatformsIntegration.php)** - Cloud-agnostic patterns and failover

### 🔐 Security Integration
- **[Authentication & Authorization](../examples/integrations/security/SecurityIntegration.php)** - Multi-factor authentication, session management
- **[OAuth2 & SAML Integration](../examples/integrations/security/SecurityIntegration.php)** - External identity provider integration
- **[Encryption & Data Protection](../examples/integrations/security/SecurityIntegration.php)** - End-to-end encryption and key management
- **[Audit Logging & Compliance](../examples/integrations/security/SecurityIntegration.php)** - GDPR, HIPAA, SOX compliance patterns
- **[Security Monitoring](../examples/integrations/security/SecurityIntegration.php)** - Real-time threat detection and response

### 📊 Monitoring and Analytics Integration
- **[Performance Monitoring](../examples/integrations/monitoring/MonitoringIntegration.php)** - APM integration with Datadog, New Relic, Prometheus
- **[Business Intelligence](../examples/integrations/monitoring/MonitoringIntegration.php)** - Workflow analytics and KPI tracking
- **[Real-time Alerting](../examples/integrations/monitoring/MonitoringIntegration.php)** - Multi-channel alert systems

### ⚡ Performance Optimization
- **[Database Optimization](../examples/integrations/performance/PerformanceIntegration.php)** - Query optimization, indexing strategies
- **[Caching Strategies](../examples/integrations/performance/PerformanceIntegration.php)** - Multi-level caching implementation
- **[Memory Management](../examples/integrations/performance/PerformanceIntegration.php)** - Memory optimization and leak prevention

### 🧪 Testing Integration
- **[Workflow Testing](../examples/integrations/testing/TestingIntegration.php)** - Unit, integration, and end-to-end testing
- **[Load Testing](../examples/integrations/testing/TestingIntegration.php)** - Performance and stress testing
- **[Security Testing](../examples/integrations/testing/TestingIntegration.php)** - Penetration testing and vulnerability assessment

### ⚙️ Configuration Management
- **[Environment Configuration](../examples/integrations/configuration/ConfigurationIntegration.php)** - Multi-environment setup and validation
- **[Feature Flags](../examples/integrations/configuration/ConfigurationIntegration.php)** - Dynamic feature management
- **[Secrets Management](../examples/integrations/configuration/ConfigurationIntegration.php)** - Secure credential handling

### 🚀 Deployment and DevOps Integration
- **[CI/CD Pipeline Integration](../examples/integrations/deployment/DeploymentIntegration.php)** - GitHub Actions, GitLab CI, Jenkins integration
- **[Docker Container Deployment](../examples/integrations/deployment/DeploymentIntegration.php)** - Container builds and registry management
- **[Blue-Green Deployment](../examples/integrations/deployment/DeploymentIntegration.php)** - Zero-downtime deployment strategies
- **[Canary Deployment](../examples/integrations/deployment/DeploymentIntegration.php)** - Gradual rollout with monitoring
- **[Kubernetes Orchestration](../examples/integrations/deployment/DeploymentIntegration.php)** - Container orchestration and scaling

### 🌟 Real-World Examples
- **[E-commerce Order Processing](../examples/integrations/real-world/ComprehensiveExamples.php)** - Complete order lifecycle with payments, inventory, shipping, and notifications
- **[Healthcare Patient Journey](../examples/integrations/real-world/ComprehensiveExamples.php)** - Patient registration, appointments, EHR, lab orders, and discharge
- **[Manufacturing Process Integration](../examples/integrations/real-world/ComprehensiveExamples.php)** - Production scheduling, quality control, and inventory management

---

This comprehensive integration guide covers all aspects of integrating Litepie Flow into your Laravel application. For additional help, refer to the other documentation files or check the package's GitHub repository for the latest updates and community discussions.
