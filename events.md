# Events

This guide covers the comprehensive event system in Litepie Flow, which provides hooks into every aspect of the workflow lifecycle for monitoring, logging, and custom business logic.

## Table of Contents

- [Overview](#overview)
- [Event Types](#event-types)
- [Event Listeners](#event-listeners)
- [Event Subscribers](#event-subscribers)
- [Custom Events](#custom-events)
- [Event Data](#event-data)
- [Best Practices](#best-practices)
- [Examples](#examples)

## Overview

Litepie Flow dispatches events at every stage of the workflow process, allowing you to:

- **Monitor**: Track workflow progress and performance
- **Log**: Record detailed audit trails
- **Integrate**: Connect with external systems
- **Validate**: Add custom validation logic
- **Transform**: Modify data during transitions
- **Notify**: Send notifications to stakeholders

## Event Types

### Core Workflow Events

#### Guard Events
Fired before a transition to check if it should be allowed:

```php
use Litepie\Flow\Events\WorkflowGuardEvent;

// Event name pattern: workflow.{workflow_name}.guard.{transition_name}
// Example: workflow.order_processing.guard.process_payment
```

#### Leave Events  
Fired when leaving a state:

```php
use Litepie\Flow\Events\WorkflowLeaveEvent;

// Event name pattern: workflow.{workflow_name}.leave.{state_name}
// Example: workflow.order_processing.leave.pending
```

#### Transition Events
Fired during the transition process:

```php
use Litepie\Flow\Events\WorkflowTransitionEvent;

// Event name pattern: workflow.{workflow_name}.transition.{transition_name}
// Example: workflow.order_processing.transition.process_payment
```

#### Enter Events
Fired when entering a state:

```php
use Litepie\Flow\Events\WorkflowEnterEvent;

// Event name pattern: workflow.{workflow_name}.enter.{state_name}
// Example: workflow.order_processing.enter.processing
```

#### Entered Events
Fired after successfully entering a state:

```php
use Litepie\Flow\Events\WorkflowEnteredEvent;

// Event name pattern: workflow.{workflow_name}.entered.{state_name}
// Example: workflow.order_processing.entered.processing
```

### Action Events

#### Action Starting
Fired before an action begins execution:

```php
use Litepie\Flow\Events\ActionStartingEvent;

// Event name: workflow.action.starting
```

#### Action Completed
Fired when an action completes successfully:

```php
use Litepie\Flow\Events\ActionCompletedEvent;

// Event name: workflow.action.completed
```

#### Action Failed
Fired when an action fails:

```php
use Litepie\Flow\Events\ActionFailedEvent;

// Event name: workflow.action.failed
```

### Workflow Lifecycle Events

#### Workflow Started
Fired when a workflow is first initiated:

```php
use Litepie\Flow\Events\WorkflowStartedEvent;

// Event name: workflow.{workflow_name}.started
```

#### Workflow Completed
Fired when a workflow reaches a final state:

```php
use Litepie\Flow\Events\WorkflowCompletedEvent;

// Event name: workflow.{workflow_name}.completed
```

#### Workflow Failed
Fired when a workflow encounters an unrecoverable error:

```php
use Litepie\Flow\Events\WorkflowFailedEvent;

// Event name: workflow.{workflow_name}.failed
```

## Event Listeners

### Creating Event Listeners

```php
<?php

namespace App\Listeners;

use Litepie\Flow\Events\WorkflowTransitionEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class OrderTransitionListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(WorkflowTransitionEvent $event): void
    {
        $entity = $event->getEntity();
        $transition = $event->getTransition();
        $context = $event->getContext();

        // Log the transition
        logger()->info('Order transitioned', [
            'order_id' => $entity->id,
            'from_state' => $transition->getFromState(),
            'to_state' => $transition->getToState(),
            'transition_name' => $transition->getName(),
            'user_id' => auth()->id(),
            'context' => $context
        ]);

        // Send notifications based on transition
        match($transition->getName()) {
            'process_payment' => $this->notifyPaymentProcessing($entity),
            'ship_order' => $this->notifyOrderShipped($entity),
            'deliver_order' => $this->notifyOrderDelivered($entity),
            default => null
        };
    }

    private function notifyPaymentProcessing($order): void
    {
        // Send payment processing notification
        $order->customer->notify(new PaymentProcessingNotification($order));
    }

    private function notifyOrderShipped($order): void
    {
        // Send shipping notification with tracking info
        $order->customer->notify(new OrderShippedNotification($order));
    }
}
```

### Registering Event Listeners

In your `EventServiceProvider`:

```php
<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Litepie\Flow\Events\WorkflowTransitionEvent;
use App\Listeners\OrderTransitionListener;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        // Specific workflow events
        'workflow.order_processing.transition.process_payment' => [
            PaymentProcessingListener::class,
        ],
        'workflow.order_processing.entered.shipped' => [
            ShippingNotificationListener::class,
        ],
        
        // Generic workflow events
        WorkflowTransitionEvent::class => [
            OrderTransitionListener::class,
            AuditLogListener::class,
        ],
        
        // Action events
        'workflow.action.failed' => [
            ActionFailureListener::class,
        ],
    ];
}
```

### Conditional Event Listeners

```php
class ConditionalOrderListener
{
    public function handle(WorkflowTransitionEvent $event): void
    {
        $order = $event->getEntity();
        
        // Only handle high-value orders
        if ($order->total < 1000) {
            return;
        }
        
        // Different handling based on customer type
        if ($order->customer->isVip()) {
            $this->handleVipOrder($event);
        } else {
            $this->handleStandardOrder($event);
        }
    }

    private function handleVipOrder(WorkflowTransitionEvent $event): void
    {
        // VIP-specific handling
        $order = $event->getEntity();
        
        // Notify account manager
        $order->customer->accountManager->notify(
            new VipOrderTransitionNotification($order, $event->getTransition())
        );
        
        // Expedite processing if applicable
        if ($event->getTransition()->getName() === 'process_payment') {
            ExpediteOrderJob::dispatch($order)->onQueue('vip');
        }
    }
}
```

## Event Subscribers

Event subscribers allow you to listen to multiple events in a single class:

```php
<?php

namespace App\Listeners;

use Illuminate\Events\Dispatcher;
use Litepie\Flow\Events\WorkflowGuardEvent;
use Litepie\Flow\Events\WorkflowTransitionEvent;
use Litepie\Flow\Events\WorkflowEnteredEvent;

class OrderWorkflowSubscriber
{
    public function subscribe(Dispatcher $events): void
    {
        // Guard events
        $events->listen(
            'workflow.order_processing.guard.*',
            [static::class, 'handleGuard']
        );

        // Transition events
        $events->listen(
            'workflow.order_processing.transition.*',
            [static::class, 'handleTransition']
        );

        // State entry events
        $events->listen(
            'workflow.order_processing.entered.*',
            [static::class, 'handleStateEntered']
        );

        // Completion events
        $events->listen(
            'workflow.order_processing.completed',
            [static::class, 'handleWorkflowCompleted']
        );
    }

    public function handleGuard(WorkflowGuardEvent $event): void
    {
        $order = $event->getEntity();
        $transition = $event->getTransition();

        // Custom business logic validation
        if ($transition->getName() === 'process_payment') {
            if (!$order->hasValidPaymentMethod()) {
                $event->block('Invalid payment method');
                return;
            }

            if ($order->total > $order->customer->getCreditLimit()) {
                $event->block('Order exceeds customer credit limit');
                return;
            }
        }

        if ($transition->getName() === 'ship_order') {
            if (!$order->hasShippingAddress()) {
                $event->block('Shipping address required');
                return;
            }

            if (!$this->inventoryService->isAvailable($order->items)) {
                $event->block('Items not available in inventory');
                return;
            }
        }
    }

    public function handleTransition(WorkflowTransitionEvent $event): void
    {
        $order = $event->getEntity();
        $transition = $event->getTransition();
        $context = $event->getContext();

        // Log transition with detailed context
        activity()
            ->performedOn($order)
            ->withProperties([
                'workflow' => $event->getWorkflow()->getName(),
                'transition' => $transition->getName(),
                'from_state' => $transition->getFromState(),
                'to_state' => $transition->getToState(),
                'context' => $context,
                'user_id' => auth()->id(),
                'ip_address' => request()->ip()
            ])
            ->log('workflow_transition');

        // Update external systems
        match($transition->getName()) {
            'process_payment' => $this->updateAccountingSystem($order),
            'ship_order' => $this->updateInventorySystem($order),
            'deliver_order' => $this->updateCustomerPortal($order),
            default => null
        };
    }

    public function handleStateEntered(WorkflowEnteredEvent $event): void
    {
        $order = $event->getEntity();
        $state = $event->getState();

        // State-specific actions
        match($state->getName()) {
            'processing' => $this->handleProcessingState($order),
            'shipped' => $this->handleShippedState($order),
            'delivered' => $this->handleDeliveredState($order),
            default => null
        };

        // Update order timestamps
        $order->update([
            $state->getName() . '_at' => now()
        ]);
    }

    public function handleWorkflowCompleted(WorkflowCompletedEvent $event): void
    {
        $order = $event->getEntity();

        // Final order processing
        $order->update([
            'completed_at' => now(),
            'final_state' => $event->getFinalState()->getName()
        ]);

        // Generate reports
        GenerateOrderReportJob::dispatch($order);

        // Customer satisfaction survey
        if ($event->getFinalState()->getName() === 'delivered') {
            SendSatisfactionSurveyJob::dispatch($order)
                ->delay(now()->addDays(3));
        }

        // Archive order data
        ArchiveOrderDataJob::dispatch($order)
            ->delay(now()->addDays(30));
    }

    private function handleProcessingState($order): void
    {
        // Notify warehouse
        $order->warehouse->notify(new NewOrderNotification($order));
        
        // Create picking list
        CreatePickingListJob::dispatch($order);
        
        // Reserve inventory
        $this->inventoryService->reserve($order->items);
    }

    private function handleShippedState($order): void
    {
        // Generate tracking number
        $trackingNumber = $this->shippingService->generateTrackingNumber($order);
        $order->update(['tracking_number' => $trackingNumber]);
        
        // Send tracking info to customer
        $order->customer->notify(new TrackingInfoNotification($order));
        
        // Update inventory
        $this->inventoryService->confirm($order->items);
    }
}
```

### Registering Event Subscribers

```php
// In EventServiceProvider
protected $subscribe = [
    OrderWorkflowSubscriber::class,
    DocumentWorkflowSubscriber::class,
    UserRegistrationWorkflowSubscriber::class,
];
```

## Custom Events

### Creating Custom Events

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderPriorityChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public $order,
        public string $oldPriority,
        public string $newPriority,
        public array $context = []
    ) {}
}

class WorkflowDeadlineApproaching
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public $entity,
        public string $workflowName,
        public string $currentState,
        public \Carbon\Carbon $deadline,
        public int $hoursRemaining
    ) {}
}

class WorkflowStuck
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public $entity,
        public string $workflowName,
        public string $stuckState,
        public int $daysStuck,
        public array $metadata = []
    ) {}
}
```

### Dispatching Custom Events

```php
class CustomEventAction extends BaseAction
{
    public function execute(array $context = []): ActionResult
    {
        $order = $context['order'];
        $newPriority = $context['priority'];
        
        $oldPriority = $order->priority;
        $order->update(['priority' => $newPriority]);
        
        // Dispatch custom event
        event(new OrderPriorityChanged($order, $oldPriority, $newPriority, $context));
        
        return $this->success([
            'order_id' => $order->id,
            'old_priority' => $oldPriority,
            'new_priority' => $newPriority
        ], 'Order priority updated');
    }
}
```

### Custom Event Listeners

```php
class OrderPriorityListener
{
    public function handle(OrderPriorityChanged $event): void
    {
        $order = $event->order;
        
        // High priority orders get expedited processing
        if ($event->newPriority === 'high') {
            $this->expediteOrder($order);
        }
        
        // Notify stakeholders of priority changes
        $this->notifyStakeholders($order, $event->oldPriority, $event->newPriority);
        
        // Update SLA calculations
        $this->updateSLA($order, $event->newPriority);
    }

    private function expediteOrder($order): void
    {
        // Move to high priority queue
        ProcessOrderJob::dispatch($order)->onQueue('high-priority');
        
        // Notify warehouse manager
        $order->warehouse->manager->notify(
            new HighPriorityOrderNotification($order)
        );
    }
}

class DeadlineMonitoringListener
{
    public function handle(WorkflowDeadlineApproaching $event): void
    {
        $entity = $event->entity;
        
        // Send escalation notifications
        $this->sendEscalationNotifications($entity, $event->hoursRemaining);
        
        // Auto-assign if unassigned
        if (!$entity->assigned_user_id) {
            $this->autoAssign($entity);
        }
        
        // Log deadline warning
        logger()->warning('Workflow deadline approaching', [
            'entity_type' => get_class($entity),
            'entity_id' => $entity->id,
            'workflow' => $event->workflowName,
            'current_state' => $event->currentState,
            'deadline' => $event->deadline,
            'hours_remaining' => $event->hoursRemaining
        ]);
    }
}
```

## Event Data

### Accessing Event Data

All workflow events provide access to comprehensive data:

```php
class DetailedEventListener
{
    public function handle(WorkflowTransitionEvent $event): void
    {
        // Core event data
        $entity = $event->getEntity();
        $workflow = $event->getWorkflow();
        $transition = $event->getTransition();
        $context = $event->getContext();
        
        // Workflow information
        $workflowName = $workflow->getName();
        $workflowLabel = $workflow->getLabel();
        $workflowMetadata = $workflow->getMetadata();
        
        // Transition information
        $transitionName = $transition->getName();
        $fromState = $transition->getFromState();
        $toState = $transition->getToState();
        $transitionMetadata = $transition->getMetadata();
        
        // Context information
        $userId = $context['user_id'] ?? null;
        $ipAddress = $context['ip_address'] ?? null;
        $userAgent = $context['user_agent'] ?? null;
        $requestId = $context['request_id'] ?? null;
        
        // Additional metadata
        $timestamp = $event->getTimestamp();
        $eventId = $event->getId();
        
        // Create comprehensive audit log
        AuditLog::create([
            'event_id' => $eventId,
            'event_type' => 'workflow_transition',
            'entity_type' => get_class($entity),
            'entity_id' => $entity->id,
            'workflow_name' => $workflowName,
            'transition_name' => $transitionName,
            'from_state' => $fromState,
            'to_state' => $toState,
            'context' => $context,
            'metadata' => [
                'workflow_metadata' => $workflowMetadata,
                'transition_metadata' => $transitionMetadata,
            ],
            'user_id' => $userId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'timestamp' => $timestamp,
        ]);
    }
}
```

### Event Context Enhancement

```php
class ContextEnhancementListener
{
    public function handle(WorkflowTransitionEvent $event): void
    {
        $context = $event->getContext();
        
        // Enhance context with additional data
        $enhancedContext = array_merge($context, [
            'server_name' => gethostname(),
            'application_version' => config('app.version'),
            'environment' => app()->environment(),
            'session_id' => session()->getId(),
            'request_duration' => microtime(true) - LARAVEL_START,
            'memory_usage' => memory_get_peak_usage(true),
            'database_queries' => DB::getQueryLog(),
        ]);
        
        // Store enhanced context for debugging
        if (app()->environment('local', 'staging')) {
            cache()->put(
                "workflow_debug_{$event->getId()}",
                $enhancedContext,
                now()->addHours(24)
            );
        }
    }
}
```

## Best Practices

### Performance Considerations

```php
class PerformantEventListener implements ShouldQueue
{
    use InteractsWithQueue;

    // Use queues for heavy operations
    public $queue = 'workflow-events';
    
    // Set timeout for long-running operations
    public $timeout = 300;
    
    // Retry failed jobs
    public $tries = 3;
    
    public function handle(WorkflowTransitionEvent $event): void
    {
        // Only process relevant events
        if (!$this->shouldProcess($event)) {
            return;
        }
        
        // Batch process multiple entities
        if ($this->shouldBatch($event)) {
            $this->addToBatch($event);
            return;
        }
        
        // Process individual event
        $this->processEvent($event);
    }
    
    private function shouldProcess(WorkflowTransitionEvent $event): bool
    {
        // Filter based on workflow type
        return in_array($event->getWorkflow()->getName(), [
            'order_processing',
            'document_approval'
        ]);
    }
    
    private function shouldBatch(WorkflowTransitionEvent $event): bool
    {
        // Batch certain types of events
        return $event->getTransition()->getName() === 'bulk_update';
    }
}
```

### Error Handling

```php
class RobustEventListener
{
    public function handle(WorkflowTransitionEvent $event): void
    {
        try {
            $this->processEvent($event);
        } catch (\Exception $e) {
            // Log error but don't fail the workflow
            logger()->error('Event listener failed', [
                'event_id' => $event->getId(),
                'listener' => static::class,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Report to error tracking service
            report($e);
            
            // Optionally retry or send to dead letter queue
            if ($this->shouldRetry($e)) {
                throw $e; // Let queue retry
            }
        }
    }
    
    private function shouldRetry(\Exception $e): bool
    {
        // Retry on temporary failures
        return $e instanceof ConnectionException ||
               $e instanceof TimeoutException;
    }
}
```

### Event Testing

```php
class EventTestCase extends TestCase
{
    public function test_order_transition_event_is_dispatched()
    {
        Event::fake([WorkflowTransitionEvent::class]);
        
        $order = Order::factory()->create(['state' => 'pending']);
        
        // Trigger workflow transition
        $order->transitionTo('processing');
        
        // Assert event was dispatched
        Event::assertDispatched(WorkflowTransitionEvent::class, function ($event) use ($order) {
            return $event->getEntity()->id === $order->id &&
                   $event->getTransition()->getName() === 'process';
        });
    }
    
    public function test_custom_event_listener_functionality()
    {
        $order = Order::factory()->create();
        $event = new OrderPriorityChanged($order, 'normal', 'high');
        
        $listener = new OrderPriorityListener();
        $listener->handle($event);
        
        // Assert expected side effects
        $this->assertDatabaseHas('jobs', [
            'queue' => 'high-priority'
        ]);
    }
}
```

## Examples

### Real-time Monitoring Dashboard

```php
class WorkflowMonitoringSubscriber
{
    public function subscribe(Dispatcher $events): void
    {
        // Monitor all workflow events
        $events->listen('workflow.*', [static::class, 'handleWorkflowEvent']);
        
        // Monitor action events
        $events->listen('workflow.action.*', [static::class, 'handleActionEvent']);
    }
    
    public function handleWorkflowEvent($event): void
    {
        $metrics = [
            'event_type' => $this->getEventType($event),
            'workflow_name' => $event->getWorkflow()->getName(),
            'entity_type' => get_class($event->getEntity()),
            'timestamp' => now(),
            'duration' => $this->calculateDuration($event),
        ];
        
        // Send to real-time monitoring
        broadcast(new WorkflowMetricsUpdated($metrics));
        
        // Store in time-series database
        InfluxDB::write('workflow_events', $metrics);
        
        // Update dashboard counters
        $this->updateDashboardCounters($event);
    }
    
    private function updateDashboardCounters($event): void
    {
        $workflowName = $event->getWorkflow()->getName();
        
        // Increment event counters
        Redis::incr("workflow_events:{$workflowName}:total");
        Redis::incr("workflow_events:{$workflowName}:today:" . now()->format('Y-m-d'));
        
        // Track state distribution
        if (method_exists($event, 'getToState')) {
            $state = $event->getToState();
            Redis::incr("workflow_states:{$workflowName}:{$state}");
        }
        
        // Track performance metrics
        $duration = $this->calculateDuration($event);
        if ($duration > 0) {
            Redis::lpush("workflow_durations:{$workflowName}", $duration);
            Redis::ltrim("workflow_durations:{$workflowName}", 0, 999); // Keep last 1000
        }
    }
}
```

### SLA Monitoring

```php
class SLAMonitoringListener
{
    public function handle(WorkflowEnteredEvent $event): void
    {
        $entity = $event->getEntity();
        $state = $event->getState();
        $workflow = $event->getWorkflow();
        
        // Check if state has SLA requirements
        $slaConfig = $this->getSLAConfig($workflow->getName(), $state->getName());
        
        if (!$slaConfig) {
            return;
        }
        
        // Calculate deadline
        $deadline = now()->add($slaConfig['duration']);
        
        // Store SLA tracking
        SLATracking::create([
            'entity_type' => get_class($entity),
            'entity_id' => $entity->id,
            'workflow_name' => $workflow->getName(),
            'state_name' => $state->getName(),
            'entered_at' => now(),
            'deadline' => $deadline,
            'warning_threshold' => $deadline->sub($slaConfig['warning_before']),
            'status' => 'active'
        ]);
        
        // Schedule deadline warnings
        $this->scheduleDeadlineWarnings($entity, $workflow, $state, $deadline, $slaConfig);
    }
    
    private function scheduleDeadlineWarnings($entity, $workflow, $state, $deadline, $slaConfig): void
    {
        // Warning at 80% of time
        $warningTime = now()->addSeconds(
            ($deadline->timestamp - now()->timestamp) * 0.8
        );
        
        SendSLAWarningJob::dispatch($entity, $workflow, $state, '80%')
            ->delay($warningTime);
        
        // Final warning at 95%
        $finalWarningTime = now()->addSeconds(
            ($deadline->timestamp - now()->timestamp) * 0.95
        );
        
        SendSLAWarningJob::dispatch($entity, $workflow, $state, '95%')
            ->delay($finalWarningTime);
        
        // Breach notification
        SendSLABreachJob::dispatch($entity, $workflow, $state)
            ->delay($deadline);
    }
}
```

### Workflow Analytics

```php
class WorkflowAnalyticsSubscriber
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen('workflow.*.transition.*', [static::class, 'recordTransition']);
        $events->listen('workflow.*.completed', [static::class, 'recordCompletion']);
        $events->listen('workflow.*.failed', [static::class, 'recordFailure']);
    }
    
    public function recordTransition(WorkflowTransitionEvent $event): void
    {
        $analytics = [
            'workflow_name' => $event->getWorkflow()->getName(),
            'transition_name' => $event->getTransition()->getName(),
            'from_state' => $event->getTransition()->getFromState(),
            'to_state' => $event->getTransition()->getToState(),
            'entity_type' => get_class($event->getEntity()),
            'user_id' => auth()->id(),
            'timestamp' => now(),
            'day_of_week' => now()->dayOfWeek,
            'hour_of_day' => now()->hour,
        ];
        
        // Store in analytics database
        WorkflowAnalytics::create($analytics);
        
        // Send to external analytics service
        Analytics::track('workflow_transition', $analytics);
    }
    
    public function recordCompletion(WorkflowCompletedEvent $event): void
    {
        $entity = $event->getEntity();
        $startTime = $entity->workflow_started_at ?? $entity->created_at;
        $duration = now()->diffInSeconds($startTime);
        
        WorkflowCompletion::create([
            'workflow_name' => $event->getWorkflow()->getName(),
            'entity_type' => get_class($entity),
            'entity_id' => $entity->id,
            'final_state' => $event->getFinalState()->getName(),
            'duration_seconds' => $duration,
            'total_transitions' => $entity->workflow_transitions_count ?? 0,
            'completed_at' => now(),
        ]);
        
        // Update workflow performance metrics
        $this->updatePerformanceMetrics($event->getWorkflow()->getName(), $duration);
    }
}
```

This comprehensive events documentation provides everything needed to leverage the powerful event system in Litepie Flow for monitoring, integration, and extending workflow functionality.