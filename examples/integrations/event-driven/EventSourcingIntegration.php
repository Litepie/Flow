<?php

namespace App\Examples\Integrations\EventDriven;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;
use Litepie\Flow\Events\WorkflowTransitioned;
use Litepie\Actions\BaseAction;
use Litepie\Actions\Contracts\ActionResult;

/**
 * Event Sourcing Integration with Litepie Flow
 * 
 * This example demonstrates how to integrate event sourcing patterns
 * with workflow transitions, including event stores, projections,
 * and event replay capabilities.
 */

// 1. Event Store Implementation
class WorkflowEventStore
{
    private string $connection;

    public function __construct(string $connection = 'default')
    {
        $this->connection = $connection;
    }

    public function store(WorkflowEvent $event): void
    {
        DB::connection($this->connection)->table('workflow_event_store')->insert([
            'event_id' => $event->getEventId(),
            'aggregate_type' => $event->getAggregateType(),
            'aggregate_id' => $event->getAggregateId(),
            'event_type' => $event->getEventType(),
            'event_data' => json_encode($event->getEventData()),
            'metadata' => json_encode($event->getMetadata()),
            'version' => $event->getVersion(),
            'occurred_at' => $event->getOccurredAt(),
            'recorded_at' => now(),
        ]);
    }

    public function getEventsForAggregate(string $aggregateType, $aggregateId, int $fromVersion = 0): array
    {
        $events = DB::connection($this->connection)
            ->table('workflow_event_store')
            ->where('aggregate_type', $aggregateType)
            ->where('aggregate_id', $aggregateId)
            ->where('version', '>', $fromVersion)
            ->orderBy('version')
            ->get();

        return $events->map(function ($eventData) {
            return WorkflowEvent::fromArray([
                'event_id' => $eventData->event_id,
                'aggregate_type' => $eventData->aggregate_type,
                'aggregate_id' => $eventData->aggregate_id,
                'event_type' => $eventData->event_type,
                'event_data' => json_decode($eventData->event_data, true),
                'metadata' => json_decode($eventData->metadata, true),
                'version' => $eventData->version,
                'occurred_at' => $eventData->occurred_at,
            ]);
        })->toArray();
    }

    public function getEventsSince(\DateTime $since): array
    {
        $events = DB::connection($this->connection)
            ->table('workflow_event_store')
            ->where('occurred_at', '>=', $since)
            ->orderBy('occurred_at')
            ->get();

        return $events->map(function ($eventData) {
            return WorkflowEvent::fromArray([
                'event_id' => $eventData->event_id,
                'aggregate_type' => $eventData->aggregate_type,
                'aggregate_id' => $eventData->aggregate_id,
                'event_type' => $eventData->event_type,
                'event_data' => json_decode($eventData->event_data, true),
                'metadata' => json_decode($eventData->metadata, true),
                'version' => $eventData->version,
                'occurred_at' => $eventData->occurred_at,
            ]);
        })->toArray();
    }

    public function getEventsByType(string $eventType, \DateTime $since = null): array
    {
        $query = DB::connection($this->connection)
            ->table('workflow_event_store')
            ->where('event_type', $eventType);

        if ($since) {
            $query->where('occurred_at', '>=', $since);
        }

        $events = $query->orderBy('occurred_at')->get();

        return $events->map(function ($eventData) {
            return WorkflowEvent::fromArray([
                'event_id' => $eventData->event_id,
                'aggregate_type' => $eventData->aggregate_type,
                'aggregate_id' => $eventData->aggregate_id,
                'event_type' => $eventData->event_type,
                'event_data' => json_decode($eventData->event_data, true),
                'metadata' => json_decode($eventData->metadata, true),
                'version' => $eventData->version,
                'occurred_at' => $eventData->occurred_at,
            ]);
        })->toArray();
    }
}

// 2. Workflow Event Class
class WorkflowEvent
{
    public function __construct(
        private string $eventId,
        private string $aggregateType,
        private $aggregateId,
        private string $eventType,
        private array $eventData,
        private array $metadata = [],
        private int $version = 1,
        private ?\DateTime $occurredAt = null
    ) {
        $this->occurredAt = $occurredAt ?: now();
    }

    public static function fromWorkflowTransition(WorkflowTransitioned $transition): self
    {
        $subject = $transition->getSubject();
        
        return new self(
            eventId: (string) \Str::uuid(),
            aggregateType: get_class($subject),
            aggregateId: $subject->getKey(),
            eventType: 'workflow.transitioned',
            eventData: [
                'workflow_name' => $transition->getWorkflowName(),
                'from_state' => $transition->getFromState(),
                'to_state' => $transition->getToState(),
                'context' => $transition->getContext(),
            ],
            metadata: [
                'user_id' => auth()->id(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'timestamp' => now()->toISOString(),
            ]
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            eventId: $data['event_id'],
            aggregateType: $data['aggregate_type'],
            aggregateId: $data['aggregate_id'],
            eventType: $data['event_type'],
            eventData: $data['event_data'],
            metadata: $data['metadata'] ?? [],
            version: $data['version'] ?? 1,
            occurredAt: $data['occurred_at'] ? new \DateTime($data['occurred_at']) : null
        );
    }

    // Getters
    public function getEventId(): string { return $this->eventId; }
    public function getAggregateType(): string { return $this->aggregateType; }
    public function getAggregateId() { return $this->aggregateId; }
    public function getEventType(): string { return $this->eventType; }
    public function getEventData(): array { return $this->eventData; }
    public function getMetadata(): array { return $this->metadata; }
    public function getVersion(): int { return $this->version; }
    public function getOccurredAt(): \DateTime { return $this->occurredAt; }
}

// 3. Event-Sourced Aggregate
class OrderAggregate
{
    private $id;
    private string $state;
    private array $appliedEvents = [];
    private int $version = 0;
    private array $uncommittedEvents = [];

    public function __construct($id)
    {
        $this->id = $id;
        $this->state = 'pending';
    }

    public static function fromHistory(array $events): self
    {
        $aggregate = new self($events[0]->getAggregateId());
        
        foreach ($events as $event) {
            $aggregate->applyEvent($event);
        }
        
        return $aggregate;
    }

    public function processOrder(array $context = []): void
    {
        if ($this->state !== 'pending') {
            throw new \DomainException('Order can only be processed from pending state');
        }

        $event = new WorkflowEvent(
            eventId: (string) \Str::uuid(),
            aggregateType: 'Order',
            aggregateId: $this->id,
            eventType: 'order.processing_started',
            eventData: [
                'previous_state' => $this->state,
                'new_state' => 'processing',
                'context' => $context,
            ],
            version: $this->version + 1
        );

        $this->applyEvent($event);
        $this->uncommittedEvents[] = $event;
    }

    public function shipOrder(string $trackingNumber, array $context = []): void
    {
        if ($this->state !== 'processing') {
            throw new \DomainException('Order can only be shipped from processing state');
        }

        $event = new WorkflowEvent(
            eventId: (string) \Str::uuid(),
            aggregateType: 'Order',
            aggregateId: $this->id,
            eventType: 'order.shipped',
            eventData: [
                'previous_state' => $this->state,
                'new_state' => 'shipped',
                'tracking_number' => $trackingNumber,
                'context' => $context,
            ],
            version: $this->version + 1
        );

        $this->applyEvent($event);
        $this->uncommittedEvents[] = $event;
    }

    public function cancelOrder(string $reason, array $context = []): void
    {
        if (!in_array($this->state, ['pending', 'processing'])) {
            throw new \DomainException('Order cannot be cancelled from current state');
        }

        $event = new WorkflowEvent(
            eventId: (string) \Str::uuid(),
            aggregateType: 'Order',
            aggregateId: $this->id,
            eventType: 'order.cancelled',
            eventData: [
                'previous_state' => $this->state,
                'new_state' => 'cancelled',
                'cancellation_reason' => $reason,
                'context' => $context,
            ],
            version: $this->version + 1
        );

        $this->applyEvent($event);
        $this->uncommittedEvents[] = $event;
    }

    private function applyEvent(WorkflowEvent $event): void
    {
        match($event->getEventType()) {
            'order.processing_started' => $this->applyOrderProcessingStarted($event),
            'order.shipped' => $this->applyOrderShipped($event),
            'order.cancelled' => $this->applyOrderCancelled($event),
            default => null
        };

        $this->version = $event->getVersion();
        $this->appliedEvents[] = $event;
    }

    private function applyOrderProcessingStarted(WorkflowEvent $event): void
    {
        $data = $event->getEventData();
        $this->state = $data['new_state'];
    }

    private function applyOrderShipped(WorkflowEvent $event): void
    {
        $data = $event->getEventData();
        $this->state = $data['new_state'];
    }

    private function applyOrderCancelled(WorkflowEvent $event): void
    {
        $data = $event->getEventData();
        $this->state = $data['new_state'];
    }

    public function getUncommittedEvents(): array
    {
        return $this->uncommittedEvents;
    }

    public function markEventsAsCommitted(): void
    {
        $this->uncommittedEvents = [];
    }

    public function getId() { return $this->id; }
    public function getState(): string { return $this->state; }
    public function getVersion(): int { return $this->version; }
}

// 4. Event-Sourced Repository
class EventSourcedOrderRepository
{
    public function __construct(
        private WorkflowEventStore $eventStore
    ) {}

    public function save(OrderAggregate $aggregate): void
    {
        $uncommittedEvents = $aggregate->getUncommittedEvents();
        
        foreach ($uncommittedEvents as $event) {
            $this->eventStore->store($event);
        }
        
        $aggregate->markEventsAsCommitted();
    }

    public function findById($id): ?OrderAggregate
    {
        $events = $this->eventStore->getEventsForAggregate('Order', $id);
        
        if (empty($events)) {
            return null;
        }
        
        return OrderAggregate::fromHistory($events);
    }

    public function findByIds(array $ids): array
    {
        $aggregates = [];
        
        foreach ($ids as $id) {
            $aggregate = $this->findById($id);
            if ($aggregate) {
                $aggregates[$id] = $aggregate;
            }
        }
        
        return $aggregates;
    }
}

// 5. Projection Builder
class OrderProjectionBuilder
{
    private string $connection;

    public function __construct(string $connection = 'default')
    {
        $this->connection = $connection;
    }

    public function buildProjection(array $events): void
    {
        foreach ($events as $event) {
            $this->handleEvent($event);
        }
    }

    private function handleEvent(WorkflowEvent $event): void
    {
        match($event->getEventType()) {
            'order.processing_started' => $this->handleOrderProcessingStarted($event),
            'order.shipped' => $this->handleOrderShipped($event),
            'order.cancelled' => $this->handleOrderCancelled($event),
            default => null
        };
    }

    private function handleOrderProcessingStarted(WorkflowEvent $event): void
    {
        $data = $event->getEventData();
        
        DB::connection($this->connection)->table('order_projections')->updateOrInsert(
            ['order_id' => $event->getAggregateId()],
            [
                'state' => $data['new_state'],
                'processing_started_at' => $event->getOccurredAt(),
                'updated_at' => now(),
            ]
        );

        // Update daily processing metrics
        DB::connection($this->connection)->table('daily_order_metrics')
            ->updateOrInsert(
                ['date' => $event->getOccurredAt()->format('Y-m-d')],
                [
                    'orders_processing' => DB::raw('orders_processing + 1'),
                    'updated_at' => now(),
                ]
            );
    }

    private function handleOrderShipped(WorkflowEvent $event): void
    {
        $data = $event->getEventData();
        
        DB::connection($this->connection)->table('order_projections')->updateOrInsert(
            ['order_id' => $event->getAggregateId()],
            [
                'state' => $data['new_state'],
                'tracking_number' => $data['tracking_number'],
                'shipped_at' => $event->getOccurredAt(),
                'updated_at' => now(),
            ]
        );

        // Update shipping metrics
        DB::connection($this->connection)->table('shipping_metrics')
            ->updateOrInsert(
                ['date' => $event->getOccurredAt()->format('Y-m-d')],
                [
                    'orders_shipped' => DB::raw('orders_shipped + 1'),
                    'updated_at' => now(),
                ]
            );
    }

    private function handleOrderCancelled(WorkflowEvent $event): void
    {
        $data = $event->getEventData();
        
        DB::connection($this->connection)->table('order_projections')->updateOrInsert(
            ['order_id' => $event->getAggregateId()],
            [
                'state' => $data['new_state'],
                'cancelled_at' => $event->getOccurredAt(),
                'cancellation_reason' => $data['cancellation_reason'],
                'updated_at' => now(),
            ]
        );

        // Update cancellation metrics
        DB::connection($this->connection)->table('cancellation_metrics')
            ->updateOrInsert(
                [
                    'date' => $event->getOccurredAt()->format('Y-m-d'),
                    'reason' => $data['cancellation_reason'],
                ],
                [
                    'count' => DB::raw('count + 1'),
                    'updated_at' => now(),
                ]
            );
    }
}

// 6. Event Sourcing Action
class EventSourcingWorkflowAction extends BaseAction
{
    public function __construct(
        private WorkflowEventStore $eventStore,
        private EventSourcedOrderRepository $repository,
        private OrderProjectionBuilder $projectionBuilder
    ) {}

    public function execute(array $context = []): ActionResult
    {
        $orderId = $context['order_id'];
        $operation = $context['operation'];
        $operationContext = $context['operation_context'] ?? [];

        try {
            // Load aggregate from event store
            $aggregate = $this->repository->findById($orderId);
            
            if (!$aggregate) {
                return $this->failure([
                    'order_id' => $orderId,
                ], 'Order not found in event store');
            }

            // Perform domain operation
            match($operation) {
                'process' => $aggregate->processOrder($operationContext),
                'ship' => $aggregate->shipOrder(
                    $operationContext['tracking_number'] ?? '',
                    $operationContext
                ),
                'cancel' => $aggregate->cancelOrder(
                    $operationContext['reason'] ?? 'Unknown reason',
                    $operationContext
                ),
                default => throw new \InvalidArgumentException("Unknown operation: {$operation}")
            };

            // Save events
            $this->repository->save($aggregate);

            // Update projections
            $uncommittedEvents = $aggregate->getUncommittedEvents();
            $this->projectionBuilder->buildProjection($uncommittedEvents);

            return $this->success([
                'order_id' => $orderId,
                'operation' => $operation,
                'new_state' => $aggregate->getState(),
                'version' => $aggregate->getVersion(),
                'events_generated' => count($uncommittedEvents),
            ], 'Event sourcing operation completed successfully');

        } catch (\DomainException $e) {
            return $this->failure([
                'order_id' => $orderId,
                'operation' => $operation,
                'domain_error' => $e->getMessage(),
            ], 'Domain rule violation');

        } catch (\Exception $e) {
            return $this->failure([
                'order_id' => $orderId,
                'operation' => $operation,
                'error' => $e->getMessage(),
            ], 'Event sourcing operation failed');
        }
    }

    protected function rules(): array
    {
        return [
            'order_id' => 'required',
            'operation' => 'required|string|in:process,ship,cancel',
            'operation_context' => 'array',
        ];
    }
}

// 7. Event Replay Service
class EventReplayService
{
    public function __construct(
        private WorkflowEventStore $eventStore,
        private OrderProjectionBuilder $projectionBuilder
    ) {}

    public function replayEvents(\DateTime $fromDate, \DateTime $toDate = null): array
    {
        $toDate = $toDate ?: now();
        
        $events = $this->eventStore->getEventsSince($fromDate);
        $filteredEvents = array_filter($events, function ($event) use ($toDate) {
            return $event->getOccurredAt() <= $toDate;
        });

        // Clear existing projections for the replay period
        $this->clearProjections($fromDate, $toDate);

        // Rebuild projections
        $this->projectionBuilder->buildProjection($filteredEvents);

        return [
            'events_replayed' => count($filteredEvents),
            'from_date' => $fromDate->format('Y-m-d H:i:s'),
            'to_date' => $toDate->format('Y-m-d H:i:s'),
            'replay_completed_at' => now(),
        ];
    }

    public function replayEventsForAggregate(string $aggregateType, $aggregateId): array
    {
        $events = $this->eventStore->getEventsForAggregate($aggregateType, $aggregateId);

        // Clear existing projection for this aggregate
        $this->clearProjectionForAggregate($aggregateId);

        // Rebuild projection
        $this->projectionBuilder->buildProjection($events);

        return [
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'events_replayed' => count($events),
            'replay_completed_at' => now(),
        ];
    }

    private function clearProjections(\DateTime $fromDate, \DateTime $toDate): void
    {
        DB::table('order_projections')
            ->whereBetween('updated_at', [$fromDate, $toDate])
            ->delete();

        DB::table('daily_order_metrics')
            ->whereBetween('date', [$fromDate->format('Y-m-d'), $toDate->format('Y-m-d')])
            ->delete();

        DB::table('shipping_metrics')
            ->whereBetween('date', [$fromDate->format('Y-m-d'), $toDate->format('Y-m-d')])
            ->delete();

        DB::table('cancellation_metrics')
            ->whereBetween('date', [$fromDate->format('Y-m-d'), $toDate->format('Y-m-d')])
            ->delete();
    }

    private function clearProjectionForAggregate($aggregateId): void
    {
        DB::table('order_projections')->where('order_id', $aggregateId)->delete();
    }
}

// 8. Event Listener for Event Sourcing
class EventSourcingWorkflowListener
{
    public function __construct(
        private WorkflowEventStore $eventStore,
        private OrderProjectionBuilder $projectionBuilder
    ) {}

    public function handle(WorkflowTransitioned $event): void
    {
        // Convert workflow transition to domain event
        $workflowEvent = WorkflowEvent::fromWorkflowTransition($event);
        
        // Store in event store
        $this->eventStore->store($workflowEvent);
        
        // Update projections
        $this->projectionBuilder->buildProjection([$workflowEvent]);
        
        // Log event sourcing activity
        logger()->info('Workflow event stored in event store', [
            'event_id' => $workflowEvent->getEventId(),
            'aggregate_type' => $workflowEvent->getAggregateType(),
            'aggregate_id' => $workflowEvent->getAggregateId(),
            'event_type' => $workflowEvent->getEventType(),
        ]);
    }
}

// 9. Usage Examples
class EventSourcingUsageExamples
{
    public function __construct(
        private EventSourcedOrderRepository $repository,
        private EventSourcingWorkflowAction $action,
        private EventReplayService $replayService
    ) {}

    public function processOrderWithEventSourcing($orderId): void
    {
        $result = $this->action->execute([
            'order_id' => $orderId,
            'operation' => 'process',
            'operation_context' => [
                'payment_method' => 'credit_card',
                'processor' => auth()->user()->name,
            ],
        ]);

        if ($result->isSuccessful()) {
            logger()->info('Order processed via event sourcing', $result->getData());
        }
    }

    public function replayLastWeekEvents(): void
    {
        $result = $this->replayService->replayEvents(
            now()->subWeek(),
            now()
        );

        logger()->info('Event replay completed', $result);
    }

    public function rebuildOrderProjection($orderId): void
    {
        $result = $this->replayService->replayEventsForAggregate('Order', $orderId);
        
        logger()->info('Order projection rebuilt', $result);
    }

    public function getOrderHistory($orderId): array
    {
        $aggregate = $this->repository->findById($orderId);
        
        if (!$aggregate) {
            return [];
        }

        return [
            'order_id' => $aggregate->getId(),
            'current_state' => $aggregate->getState(),
            'version' => $aggregate->getVersion(),
            'event_count' => count($aggregate->getAppliedEvents()),
        ];
    }
}
