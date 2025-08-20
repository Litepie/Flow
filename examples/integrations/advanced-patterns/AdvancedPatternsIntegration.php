<?php

namespace App\Examples\Integrations\AdvancedPatterns;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Litepie\Flow\Events\WorkflowTransitioned;
use Litepie\Flow\Facades\Flow;
use Litepie\Actions\BaseAction;
use Litepie\Actions\Contracts\ActionResult;
use Carbon\Carbon;

/**
 * Advanced Workflow Integration Patterns
 * 
 * This file demonstrates sophisticated integration patterns including:
 * - Saga Pattern for distributed transactions
 * - Event Sourcing with CQRS
 * - Circuit Breaker Pattern
 * - Bulkhead Pattern
 * - State Machine Composition
 * - Workflow Orchestration vs Choreography
 * - Temporal Patterns
 * - Compensation Patterns
 */

// 1. Saga Pattern Implementation
class SagaPatternIntegration
{
    private array $steps;
    private array $compensations;

    public function __construct()
    {
        $this->steps = [];
        $this->compensations = [];
    }

    public function createOrderSaga($order): SagaTransaction
    {
        return SagaTransaction::create('order_processing_saga')
            ->addStep(new ValidateInventoryStep($order))
            ->addStep(new ProcessPaymentStep($order))
            ->addStep(new CreateShipmentStep($order))
            ->addStep(new SendNotificationStep($order))
            ->withCompensation(new ReleaseInventoryCompensation())
            ->withCompensation(new RefundPaymentCompensation())
            ->withCompensation(new CancelShipmentCompensation())
            ->onFailure([$this, 'handleSagaFailure']);
    }

    public function executeSaga(SagaTransaction $saga): SagaResult
    {
        DB::beginTransaction();
        
        try {
            $sagaLog = $this->createSagaLog($saga);
            
            foreach ($saga->getSteps() as $step) {
                $result = $this->executeStep($step, $sagaLog);
                
                if (!$result->isSuccessful()) {
                    // Execute compensations in reverse order
                    $this->executeCompensations($saga, $sagaLog);
                    DB::rollBack();
                    
                    return SagaResult::failed([
                        'failed_step' => get_class($step),
                        'error' => $result->getError(),
                        'compensations_executed' => true,
                    ]);
                }
                
                $this->logStepCompletion($sagaLog, $step, $result);
            }
            
            DB::commit();
            $this->completeSagaLog($sagaLog);
            
            return SagaResult::success([
                'saga_id' => $sagaLog->id,
                'steps_completed' => count($saga->getSteps()),
                'execution_time' => $sagaLog->getExecutionTime(),
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleSagaException($saga, $e);
            throw $e;
        }
    }

    private function executeStep(SagaStep $step, $sagaLog): StepResult
    {
        $startTime = microtime(true);
        
        try {
            $result = $step->execute();
            $duration = microtime(true) - $startTime;
            
            DB::table('saga_step_logs')->insert([
                'saga_log_id' => $sagaLog->id,
                'step_class' => get_class($step),
                'status' => $result->isSuccessful() ? 'completed' : 'failed',
                'result' => json_encode($result->toArray()),
                'duration' => $duration,
                'executed_at' => now(),
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            $duration = microtime(true) - $startTime;
            
            DB::table('saga_step_logs')->insert([
                'saga_log_id' => $sagaLog->id,
                'step_class' => get_class($step),
                'status' => 'error',
                'error' => $e->getMessage(),
                'duration' => $duration,
                'executed_at' => now(),
            ]);
            
            return StepResult::failed($e->getMessage());
        }
    }

    private function executeCompensations(SagaTransaction $saga, $sagaLog): void
    {
        $compensations = array_reverse($saga->getCompensations());
        
        foreach ($compensations as $compensation) {
            try {
                $compensation->execute();
                
                DB::table('saga_compensation_logs')->insert([
                    'saga_log_id' => $sagaLog->id,
                    'compensation_class' => get_class($compensation),
                    'status' => 'completed',
                    'executed_at' => now(),
                ]);
                
            } catch (\Exception $e) {
                Log::error('Saga compensation failed', [
                    'saga_id' => $sagaLog->id,
                    'compensation' => get_class($compensation),
                    'error' => $e->getMessage(),
                ]);
                
                DB::table('saga_compensation_logs')->insert([
                    'saga_log_id' => $sagaLog->id,
                    'compensation_class' => get_class($compensation),
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'executed_at' => now(),
                ]);
            }
        }
    }

    private function createSagaLog(SagaTransaction $saga): object
    {
        return DB::table('saga_logs')->insertGetId([
            'saga_name' => $saga->getName(),
            'status' => 'executing',
            'started_at' => now(),
        ]);
    }
}

// 2. Circuit Breaker Pattern
class CircuitBreakerIntegration
{
    private array $circuitBreakers = [];

    public function getCircuitBreaker(string $service): CircuitBreaker
    {
        if (!isset($this->circuitBreakers[$service])) {
            $this->circuitBreakers[$service] = new CircuitBreaker($service, [
                'failure_threshold' => 5,
                'timeout' => 60, // seconds
                'reset_timeout' => 300, // 5 minutes
            ]);
        }

        return $this->circuitBreakers[$service];
    }

    public function callWithCircuitBreaker(string $service, callable $operation, array $fallback = null)
    {
        $circuitBreaker = $this->getCircuitBreaker($service);

        if ($circuitBreaker->isOpen()) {
            Log::warning('Circuit breaker is open', ['service' => $service]);
            return $fallback ? $fallback() : throw new ServiceUnavailableException("Service {$service} is unavailable");
        }

        try {
            $result = $operation();
            $circuitBreaker->recordSuccess();
            return $result;
            
        } catch (\Exception $e) {
            $circuitBreaker->recordFailure();
            
            if ($circuitBreaker->shouldOpenCircuit()) {
                $circuitBreaker->openCircuit();
                Log::error('Circuit breaker opened', [
                    'service' => $service,
                    'error' => $e->getMessage(),
                ]);
            }
            
            if ($fallback) {
                Log::info('Executing fallback for service', ['service' => $service]);
                return $fallback();
            }
            
            throw $e;
        }
    }
}

class CircuitBreaker
{
    private string $service;
    private array $config;
    private string $cacheKey;

    public function __construct(string $service, array $config)
    {
        $this->service = $service;
        $this->config = $config;
        $this->cacheKey = "circuit_breaker:{$service}";
    }

    public function isOpen(): bool
    {
        $state = Cache::get($this->cacheKey, ['state' => 'closed']);
        
        if ($state['state'] === 'open') {
            // Check if timeout has passed
            if (isset($state['opened_at']) && 
                Carbon::parse($state['opened_at'])->addSeconds($this->config['reset_timeout'])->isPast()) {
                $this->halfOpenCircuit();
                return false;
            }
            return true;
        }
        
        return false;
    }

    public function recordSuccess(): void
    {
        $state = Cache::get($this->cacheKey, ['failures' => 0, 'state' => 'closed']);
        
        Cache::put($this->cacheKey, [
            'failures' => 0,
            'state' => 'closed',
            'last_success' => now()->toISOString(),
        ], now()->addHours(1));
    }

    public function recordFailure(): void
    {
        $state = Cache::get($this->cacheKey, ['failures' => 0, 'state' => 'closed']);
        $state['failures'] = ($state['failures'] ?? 0) + 1;
        $state['last_failure'] = now()->toISOString();
        
        Cache::put($this->cacheKey, $state, now()->addHours(1));
    }

    public function shouldOpenCircuit(): bool
    {
        $state = Cache::get($this->cacheKey, ['failures' => 0]);
        return ($state['failures'] ?? 0) >= $this->config['failure_threshold'];
    }

    public function openCircuit(): void
    {
        Cache::put($this->cacheKey, [
            'state' => 'open',
            'opened_at' => now()->toISOString(),
            'failures' => 0,
        ], now()->addMinutes($this->config['reset_timeout']));
    }

    private function halfOpenCircuit(): void
    {
        Cache::put($this->cacheKey, [
            'state' => 'half-open',
            'failures' => 0,
        ], now()->addHours(1));
    }
}

// 3. Bulkhead Pattern
class BulkheadPatternIntegration
{
    private array $resourcePools;

    public function __construct()
    {
        $this->resourcePools = [
            'critical' => new ResourcePool('critical', 10),
            'normal' => new ResourcePool('normal', 20),
            'background' => new ResourcePool('background', 5),
        ];
    }

    public function executeWithBulkhead(string $priority, callable $operation)
    {
        $pool = $this->resourcePools[$priority] ?? $this->resourcePools['normal'];
        
        return $pool->execute($operation);
    }

    public function processWorkflowWithBulkhead(WorkflowTransitioned $event): void
    {
        $subject = $event->getSubject();
        $priority = $this->determinePriority($subject);
        
        $this->executeWithBulkhead($priority, function () use ($event) {
            $this->processWorkflowTransition($event);
        });
    }

    private function determinePriority($subject): string
    {
        return match(get_class($subject)) {
            'App\\Models\\CriticalOrder' => 'critical',
            'App\\Models\\BackgroundJob' => 'background',
            default => 'normal',
        };
    }

    private function processWorkflowTransition(WorkflowTransitioned $event): void
    {
        // Process the workflow transition
        $subject = $event->getSubject();
        $toState = $event->getToState();
        
        // Simulate processing time
        usleep(rand(100000, 500000)); // 0.1 to 0.5 seconds
        
        Log::info('Workflow transition processed in bulkhead', [
            'subject_id' => $subject->getKey(),
            'to_state' => $toState,
            'processed_at' => now(),
        ]);
    }
}

class ResourcePool
{
    private string $name;
    private int $maxConcurrent;
    private int $currentUsage;
    private array $waitingQueue;

    public function __construct(string $name, int $maxConcurrent)
    {
        $this->name = $name;
        $this->maxConcurrent = $maxConcurrent;
        $this->currentUsage = 0;
        $this->waitingQueue = [];
    }

    public function execute(callable $operation)
    {
        if ($this->currentUsage >= $this->maxConcurrent) {
            throw new ResourceExhaustedException("Resource pool '{$this->name}' is at capacity");
        }

        $this->currentUsage++;
        
        try {
            $result = $operation();
            return $result;
        } finally {
            $this->currentUsage--;
        }
    }
}

// 4. State Machine Composition
class CompositeStateMachineIntegration
{
    private array $stateMachines;

    public function __construct()
    {
        $this->stateMachines = [];
    }

    public function createCompositeOrder($orderData): CompositeOrder
    {
        $order = new CompositeOrder($orderData);
        
        // Initialize multiple state machines
        $order->initializeStateMachine('order_status', OrderStatusStateMachine::class);
        $order->initializeStateMachine('payment_status', PaymentStatusStateMachine::class);
        $order->initializeStateMachine('fulfillment_status', FulfillmentStatusStateMachine::class);
        
        return $order;
    }

    public function handleCompositeTransition(CompositeOrder $order, array $transitions): bool
    {
        DB::beginTransaction();
        
        try {
            $results = [];
            
            foreach ($transitions as $machine => $transition) {
                if ($order->canTransitionStateMachine($machine, $transition['to'])) {
                    $result = $order->transitionStateMachine($machine, $transition['to'], $transition['context'] ?? []);
                    $results[$machine] = $result;
                } else {
                    DB::rollBack();
                    return false;
                }
            }
            
            // Check for composite state rules
            if (!$this->validateCompositeState($order)) {
                DB::rollBack();
                return false;
            }
            
            DB::commit();
            
            // Trigger composite events
            $this->triggerCompositeEvents($order, $results);
            
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Composite state transition failed', [
                'order_id' => $order->id,
                'transitions' => $transitions,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    private function validateCompositeState(CompositeOrder $order): bool
    {
        $orderStatus = $order->getStateMachineState('order_status');
        $paymentStatus = $order->getStateMachineState('payment_status');
        $fulfillmentStatus = $order->getStateMachineState('fulfillment_status');
        
        // Define composite state rules
        $rules = [
            // Cannot ship without payment
            ['order_status' => 'shipped', 'payment_status' => 'pending'] => false,
            // Cannot complete without shipment
            ['order_status' => 'completed', 'fulfillment_status' => 'pending'] => false,
            // Cannot refund without payment
            ['payment_status' => 'refunded', 'payment_status' => 'pending'] => false,
        ];
        
        foreach ($rules as $condition => $allowed) {
            if ($this->matchesCondition($order, $condition) && !$allowed) {
                return false;
            }
        }
        
        return true;
    }

    private function matchesCondition(CompositeOrder $order, array $condition): bool
    {
        foreach ($condition as $machine => $state) {
            if ($order->getStateMachineState($machine) !== $state) {
                return false;
            }
        }
        
        return true;
    }

    private function triggerCompositeEvents(CompositeOrder $order, array $results): void
    {
        Event::dispatch('composite.state.changed', [
            'order' => $order,
            'transitions' => $results,
            'timestamp' => now(),
        ]);
    }
}

// 5. Temporal Patterns
class TemporalPatternIntegration
{
    public function scheduleDelayedTransition($model, string $toState, Carbon $when, array $context = []): void
    {
        DB::table('delayed_transitions')->insert([
            'model_type' => get_class($model),
            'model_id' => $model->getKey(),
            'from_state' => $model->getCurrentState()?->getName(),
            'to_state' => $toState,
            'context' => json_encode($context),
            'scheduled_at' => $when,
            'created_at' => now(),
        ]);
    }

    public function scheduleRecurringTransition($model, string $event, string $schedule, array $context = []): void
    {
        DB::table('recurring_transitions')->insert([
            'model_type' => get_class($model),
            'model_id' => $model->getKey(),
            'event' => $event,
            'schedule' => $schedule, // Cron expression
            'context' => json_encode($context),
            'next_run' => $this->calculateNextRun($schedule),
            'created_at' => now(),
        ]);
    }

    public function processTemporalTransitions(): void
    {
        // Process delayed transitions
        $delayedTransitions = DB::table('delayed_transitions')
            ->where('scheduled_at', '<=', now())
            ->whereNull('executed_at')
            ->get();

        foreach ($delayedTransitions as $transition) {
            $this->executeDelayedTransition($transition);
        }

        // Process recurring transitions
        $recurringTransitions = DB::table('recurring_transitions')
            ->where('next_run', '<=', now())
            ->where('active', true)
            ->get();

        foreach ($recurringTransitions as $transition) {
            $this->executeRecurringTransition($transition);
        }
    }

    private function executeDelayedTransition($transition): void
    {
        try {
            $modelClass = $transition->model_type;
            $model = $modelClass::find($transition->model_id);
            
            if ($model && $model->canTransitionTo($transition->to_state)) {
                $context = json_decode($transition->context, true) ?? [];
                $success = $model->transitionTo($transition->to_state, $context);
                
                DB::table('delayed_transitions')
                    ->where('id', $transition->id)
                    ->update([
                        'executed_at' => now(),
                        'success' => $success,
                    ]);
            }
            
        } catch (\Exception $e) {
            Log::error('Delayed transition failed', [
                'transition_id' => $transition->id,
                'error' => $e->getMessage(),
            ]);
            
            DB::table('delayed_transitions')
                ->where('id', $transition->id)
                ->update([
                    'executed_at' => now(),
                    'success' => false,
                    'error' => $e->getMessage(),
                ]);
        }
    }

    private function executeRecurringTransition($transition): void
    {
        try {
            $modelClass = $transition->model_type;
            $model = $modelClass::find($transition->model_id);
            
            if ($model) {
                $context = json_decode($transition->context, true) ?? [];
                Event::dispatch($transition->event, [$model, $context]);
                
                // Schedule next run
                $nextRun = $this->calculateNextRun($transition->schedule);
                
                DB::table('recurring_transitions')
                    ->where('id', $transition->id)
                    ->update([
                        'last_run' => now(),
                        'next_run' => $nextRun,
                        'run_count' => DB::raw('run_count + 1'),
                    ]);
            }
            
        } catch (\Exception $e) {
            Log::error('Recurring transition failed', [
                'transition_id' => $transition->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function calculateNextRun(string $schedule): Carbon
    {
        // Simple cron parser - in production use a proper cron library
        $cron = \Cron\CronExpression::factory($schedule);
        return Carbon::instance($cron->getNextRunDate());
    }
}

// 6. Compensation Patterns
class CompensationPatternIntegration
{
    private array $compensationHandlers;

    public function __construct()
    {
        $this->compensationHandlers = [
            'payment_processed' => PaymentCompensationHandler::class,
            'inventory_reserved' => InventoryCompensationHandler::class,
            'shipment_created' => ShipmentCompensationHandler::class,
            'notification_sent' => NotificationCompensationHandler::class,
        ];
    }

    public function registerCompensation($model, string $action, array $compensationData): void
    {
        DB::table('compensations')->insert([
            'model_type' => get_class($model),
            'model_id' => $model->getKey(),
            'action' => $action,
            'compensation_data' => json_encode($compensationData),
            'created_at' => now(),
        ]);
    }

    public function executeCompensation($model, string $reason = null): CompensationResult
    {
        $compensations = DB::table('compensations')
            ->where('model_type', get_class($model))
            ->where('model_id', $model->getKey())
            ->whereNull('executed_at')
            ->orderBy('created_at', 'desc')
            ->get();

        $results = [];
        $totalExecuted = 0;
        $totalFailed = 0;

        foreach ($compensations as $compensation) {
            try {
                $handler = $this->getCompensationHandler($compensation->action);
                $compensationData = json_decode($compensation->compensation_data, true);
                
                $result = $handler->execute($model, $compensationData, $reason);
                
                DB::table('compensations')
                    ->where('id', $compensation->id)
                    ->update([
                        'executed_at' => now(),
                        'success' => $result->isSuccessful(),
                        'result' => json_encode($result->toArray()),
                    ]);

                $results[] = [
                    'action' => $compensation->action,
                    'success' => $result->isSuccessful(),
                    'message' => $result->getMessage(),
                ];

                if ($result->isSuccessful()) {
                    $totalExecuted++;
                } else {
                    $totalFailed++;
                }

            } catch (\Exception $e) {
                Log::error('Compensation execution failed', [
                    'compensation_id' => $compensation->id,
                    'action' => $compensation->action,
                    'error' => $e->getMessage(),
                ]);

                DB::table('compensations')
                    ->where('id', $compensation->id)
                    ->update([
                        'executed_at' => now(),
                        'success' => false,
                        'error' => $e->getMessage(),
                    ]);

                $results[] = [
                    'action' => $compensation->action,
                    'success' => false,
                    'message' => $e->getMessage(),
                ];

                $totalFailed++;
            }
        }

        return new CompensationResult([
            'total_compensations' => count($compensations),
            'executed' => $totalExecuted,
            'failed' => $totalFailed,
            'results' => $results,
        ]);
    }

    private function getCompensationHandler(string $action): CompensationHandler
    {
        $handlerClass = $this->compensationHandlers[$action] ?? GenericCompensationHandler::class;
        return new $handlerClass();
    }
}

// 7. Workflow Orchestration vs Choreography
class OrchestrationVsChoreographyIntegration
{
    // Orchestration Pattern - Central coordinator
    public function createOrderOrchestrator($order): OrderOrchestrator
    {
        return new OrderOrchestrator($order, [
            new ValidateOrderStep(),
            new ProcessPaymentStep(),
            new ReserveInventoryStep(),
            new CreateShipmentStep(),
            new SendConfirmationStep(),
        ]);
    }

    // Choreography Pattern - Event-driven coordination
    public function setupOrderChoreography(): void
    {
        // Each service listens to events and decides its own actions
        Event::listen('order.created', OrderValidationService::class . '@handle');
        Event::listen('order.validated', PaymentService::class . '@handle');
        Event::listen('payment.processed', InventoryService::class . '@handle');
        Event::listen('inventory.reserved', ShippingService::class . '@handle');
        Event::listen('shipment.created', NotificationService::class . '@handle');
    }

    public function processOrderWithOrchestration($order): OrchestrationResult
    {
        $orchestrator = $this->createOrderOrchestrator($order);
        return $orchestrator->execute();
    }

    public function processOrderWithChoreography($order): void
    {
        // Simply emit the initial event - choreography handles the rest
        Event::dispatch('order.created', [$order]);
    }
}

// 8. Advanced Error Handling and Recovery
class ErrorHandlingPatternIntegration
{
    public function createResilientWorkflow($model): ResilientWorkflowProcessor
    {
        return ResilientWorkflowProcessor::create($model)
            ->withRetryPolicy(new ExponentialBackoffRetryPolicy(3, 1000))
            ->withCircuitBreaker(new CircuitBreaker('workflow_processor', [
                'failure_threshold' => 5,
                'timeout' => 60,
            ]))
            ->withFallback([$this, 'workflowFallback'])
            ->withDeadLetterQueue('failed_workflows');
    }

    public function workflowFallback($model, \Exception $exception): void
    {
        Log::critical('Workflow processing failed - executing fallback', [
            'model' => get_class($model),
            'model_id' => $model->getKey(),
            'error' => $exception->getMessage(),
        ]);

        // Send to manual processing queue
        ManualProcessingJob::dispatch($model, $exception)->onQueue('manual_review');
    }
}

// Usage Examples
class AdvancedPatternsUsageExamples
{
    public function demonstrateSagaPattern(): void
    {
        $order = new Order(['total' => 99.99]);
        
        $sagaIntegration = new SagaPatternIntegration();
        $saga = $sagaIntegration->createOrderSaga($order);
        $result = $sagaIntegration->executeSaga($saga);
        
        if ($result->isSuccessful()) {
            echo "Saga completed successfully\n";
        } else {
            echo "Saga failed with compensations\n";
        }
    }

    public function demonstrateCircuitBreaker(): void
    {
        $circuitBreaker = new CircuitBreakerIntegration();
        
        $result = $circuitBreaker->callWithCircuitBreaker('payment_service', function () {
            // Simulate external service call
            if (rand(1, 10) > 7) {
                throw new \Exception('Service unavailable');
            }
            return ['success' => true];
        }, function () {
            // Fallback response
            return ['success' => false, 'fallback' => true];
        });
        
        echo "Service call result: " . json_encode($result) . "\n";
    }

    public function demonstrateCompositeStateMachine(): void
    {
        $composite = new CompositeStateMachineIntegration();
        $order = $composite->createCompositeOrder(['total' => 99.99]);
        
        // Attempt multiple state transitions simultaneously
        $success = $composite->handleCompositeTransition($order, [
            'order_status' => ['to' => 'processing', 'context' => []],
            'payment_status' => ['to' => 'completed', 'context' => ['transaction_id' => '123']],
        ]);
        
        echo $success ? "Composite transition successful\n" : "Composite transition failed\n";
    }

    public function demonstrateTemporalPatterns(): void
    {
        $temporal = new TemporalPatternIntegration();
        $order = new Order(['id' => 1]);
        
        // Schedule delayed transition
        $temporal->scheduleDelayedTransition($order, 'auto_cancel', now()->addDays(7));
        
        // Schedule recurring transition
        $temporal->scheduleRecurringTransition($order, 'reminder.check', '0 9 * * *'); // Daily at 9 AM
        
        echo "Temporal transitions scheduled\n";
    }

    public function demonstrateCompensationPattern(): void
    {
        $compensation = new CompensationPatternIntegration();
        $order = new Order(['id' => 1]);
        
        // Register compensations
        $compensation->registerCompensation($order, 'payment_processed', [
            'transaction_id' => '123',
            'amount' => 99.99,
        ]);
        
        $compensation->registerCompensation($order, 'inventory_reserved', [
            'reservation_id' => '456',
            'items' => [['sku' => 'ABC123', 'quantity' => 2]],
        ]);
        
        // Execute compensations (e.g., on order cancellation)
        $result = $compensation->executeCompensation($order, 'Order cancelled by customer');
        
        echo "Compensations executed: {$result->getExecutedCount()}\n";
    }
}
