<?php

namespace App\Examples\Integrations\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Litepie\Flow\Events\WorkflowTransitioned;
use Litepie\Actions\BaseAction;
use Litepie\Actions\Contracts\ActionResult;

/**
 * Advanced Queue Processing Integration with Litepie Flow
 * 
 * This example demonstrates how to integrate workflow transitions with
 * advanced queue processing, including batch jobs, priority queues,
 * and comprehensive failure handling.
 */

// 1. Priority-Based Workflow Job
class PriorityWorkflowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $maxExceptions = 3;
    public int $timeout = 300;
    public int $retryAfter = 60;

    public function __construct(
        private $model,
        private string $transition,
        private array $context = [],
        private string $priority = 'normal'
    ) {
        // Set queue based on priority
        $this->onQueue($this->getQueueForPriority($priority));
        
        // Set delay based on priority
        if ($priority === 'low') {
            $this->delay(now()->addMinutes(5));
        }
    }

    public function handle(): void
    {
        try {
            // Refresh model to get latest state
            $this->model->refresh();
            
            // Check if transition is still valid
            if (!$this->model->canTransitionTo($this->transition)) {
                $this->fail(new \Exception(
                    "Invalid transition {$this->transition} for current state"
                ));
                return;
            }

            // Perform the transition
            $success = $this->model->transitionTo($this->transition, $this->context);
            
            if (!$success) {
                throw new \Exception("Transition failed: {$this->transition}");
            }

            // Log successful processing
            logger()->info('Priority workflow job completed', [
                'model_type' => get_class($this->model),
                'model_id' => $this->model->getKey(),
                'transition' => $this->transition,
                'priority' => $this->priority,
                'attempts' => $this->attempts(),
            ]);

        } catch (\Exception $e) {
            logger()->error('Priority workflow job failed', [
                'model_type' => get_class($this->model),
                'model_id' => $this->model->getKey(),
                'transition' => $this->transition,
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        // Handle job failure
        logger()->critical('Priority workflow job permanently failed', [
            'model_type' => get_class($this->model),
            'model_id' => $this->model->getKey(),
            'transition' => $this->transition,
            'priority' => $this->priority,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Create failure record
        $failureRecord = [
            'model_type' => get_class($this->model),
            'model_id' => $this->model->getKey(),
            'transition' => $this->transition,
            'context' => $this->context,
            'error' => $exception->getMessage(),
            'failed_at' => now(),
        ];

        // Store in failed jobs table with additional metadata
        DB::table('workflow_failed_jobs')->insert($failureRecord);

        // Notify administrators for critical failures
        if ($this->priority === 'critical') {
            $this->notifyAdministrators($exception);
        }
    }

    private function getQueueForPriority(string $priority): string
    {
        return match($priority) {
            'critical' => 'workflow-critical',
            'high' => 'workflow-high',
            'normal' => 'workflow-normal',
            'low' => 'workflow-low',
            default => 'workflow-normal'
        };
    }

    private function notifyAdministrators(\Throwable $exception): void
    {
        // Implementation would depend on your notification system
        // Could send Slack message, email, or push notification
    }

    public function retryUntil(): \DateTime
    {
        // Retry for up to 1 hour for critical jobs, 30 minutes for others
        $retryDuration = $this->priority === 'critical' ? 60 : 30;
        return now()->addMinutes($retryDuration);
    }
}

// 2. Batch Workflow Processing Job
class BatchWorkflowProcessingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 600; // 10 minutes for batch processing

    public function __construct(
        private string $modelClass,
        private array $modelIds,
        private string $transition,
        private array $batchContext = []
    ) {
        $this->onQueue('workflow-batch');
    }

    public function handle(): void
    {
        $batchId = $this->batchContext['batch_id'] ?? uniqid('batch_');
        $totalItems = count($this->modelIds);
        $processed = 0;
        $failed = 0;
        $results = [];

        logger()->info('Starting batch workflow processing', [
            'batch_id' => $batchId,
            'model_class' => $this->modelClass,
            'total_items' => $totalItems,
            'transition' => $this->transition,
        ]);

        foreach ($this->modelIds as $modelId) {
            try {
                $model = $this->modelClass::find($modelId);
                
                if (!$model) {
                    $results[] = [
                        'id' => $modelId,
                        'success' => false,
                        'error' => 'Model not found',
                    ];
                    $failed++;
                    continue;
                }

                if (!$model->canTransitionTo($this->transition)) {
                    $results[] = [
                        'id' => $modelId,
                        'success' => false,
                        'error' => 'Invalid transition for current state',
                        'current_state' => $model->getCurrentState()?->getName(),
                    ];
                    $failed++;
                    continue;
                }

                $success = $model->transitionTo($this->transition, $this->batchContext);
                
                $results[] = [
                    'id' => $modelId,
                    'success' => $success,
                    'new_state' => $model->fresh()->getCurrentState()?->getName(),
                ];

                if ($success) {
                    $processed++;
                } else {
                    $failed++;
                }

                // Update progress every 10 items
                if (($processed + $failed) % 10 === 0) {
                    $this->updateBatchProgress($batchId, $processed, $failed, $totalItems);
                }

            } catch (\Exception $e) {
                $results[] = [
                    'id' => $modelId,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
                $failed++;

                logger()->warning('Batch item processing failed', [
                    'batch_id' => $batchId,
                    'model_id' => $modelId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Final progress update
        $this->updateBatchProgress($batchId, $processed, $failed, $totalItems);

        // Store batch results
        $this->storeBatchResults($batchId, $results);

        logger()->info('Batch workflow processing completed', [
            'batch_id' => $batchId,
            'total_items' => $totalItems,
            'processed' => $processed,
            'failed' => $failed,
            'success_rate' => round(($processed / $totalItems) * 100, 2) . '%',
        ]);
    }

    private function updateBatchProgress(string $batchId, int $processed, int $failed, int $total): void
    {
        $progress = [
            'batch_id' => $batchId,
            'total' => $total,
            'processed' => $processed,
            'failed' => $failed,
            'percentage' => round((($processed + $failed) / $total) * 100, 2),
            'updated_at' => now(),
        ];

        // Store progress in cache for real-time updates
        cache()->put("batch_progress:{$batchId}", $progress, now()->addHour());

        // Also store in database for persistence
        DB::table('batch_job_progress')->updateOrInsert(
            ['batch_id' => $batchId],
            $progress
        );
    }

    private function storeBatchResults(string $batchId, array $results): void
    {
        DB::table('batch_job_results')->insert([
            'batch_id' => $batchId,
            'results' => json_encode($results),
            'created_at' => now(),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $batchId = $this->batchContext['batch_id'] ?? 'unknown';
        
        logger()->critical('Batch workflow processing failed', [
            'batch_id' => $batchId,
            'model_class' => $this->modelClass,
            'total_items' => count($this->modelIds),
            'error' => $exception->getMessage(),
        ]);

        // Mark batch as failed
        DB::table('batch_job_progress')->updateOrInsert(
            ['batch_id' => $batchId],
            [
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'failed_at' => now(),
            ]
        );
    }
}

// 3. Queue-Based Workflow Action
class QueuedWorkflowAction extends BaseAction
{
    public function execute(array $context = []): ActionResult
    {
        $model = $context['model'];
        $transition = $context['transition'];
        $priority = $context['priority'] ?? 'normal';
        $delay = $context['delay'] ?? null;

        try {
            // Validate before queuing
            if (!$model->canTransitionTo($transition)) {
                return $this->failure([
                    'current_state' => $model->getCurrentState()?->getName(),
                    'attempted_transition' => $transition,
                ], 'Invalid transition for current state');
            }

            // Create and dispatch job
            $job = new PriorityWorkflowJob($model, $transition, $context, $priority);
            
            if ($delay) {
                $job->delay($delay);
            }

            $jobId = dispatch($job);

            // Store job reference for tracking
            $this->storeJobReference($model, $transition, $jobId, $context);

            return $this->success([
                'job_id' => $jobId,
                'priority' => $priority,
                'queued_at' => now(),
                'delay' => $delay,
            ], 'Workflow transition queued successfully');

        } catch (\Exception $e) {
            return $this->failure([
                'error' => $e->getMessage(),
                'model_type' => get_class($model),
                'model_id' => $model->getKey(),
            ], 'Failed to queue workflow transition');
        }
    }

    private function storeJobReference($model, string $transition, $jobId, array $context): void
    {
        DB::table('queued_workflow_jobs')->insert([
            'job_id' => $jobId,
            'model_type' => get_class($model),
            'model_id' => $model->getKey(),
            'transition' => $transition,
            'context' => json_encode($context),
            'queued_at' => now(),
        ]);
    }

    protected function rules(): array
    {
        return [
            'model' => 'required',
            'transition' => 'required|string',
            'priority' => 'string|in:critical,high,normal,low',
            'delay' => 'nullable|integer|min:0',
        ];
    }
}

// 4. Workflow Queue Manager
class WorkflowQueueManager
{
    public function queueTransition($model, string $transition, array $options = []): string
    {
        $priority = $options['priority'] ?? $this->determinePriority($model, $transition);
        $delay = $options['delay'] ?? null;
        $context = $options['context'] ?? [];

        $job = new PriorityWorkflowJob($model, $transition, $context, $priority);
        
        if ($delay) {
            $job->delay($delay);
        }

        return dispatch($job);
    }

    public function queueBatchTransitions(string $modelClass, array $modelIds, string $transition, array $options = []): string
    {
        $batchId = $options['batch_id'] ?? uniqid('batch_');
        $batchSize = $options['batch_size'] ?? 100;
        $context = $options['context'] ?? [];
        $context['batch_id'] = $batchId;

        // Split into smaller chunks if needed
        $chunks = array_chunk($modelIds, $batchSize);
        $jobIds = [];

        foreach ($chunks as $index => $chunk) {
            $chunkContext = $context;
            $chunkContext['chunk_index'] = $index;
            $chunkContext['total_chunks'] = count($chunks);

            $job = new BatchWorkflowProcessingJob($modelClass, $chunk, $transition, $chunkContext);
            $jobIds[] = dispatch($job);
        }

        // Store batch metadata
        DB::table('workflow_batch_jobs')->insert([
            'batch_id' => $batchId,
            'model_class' => $modelClass,
            'transition' => $transition,
            'total_items' => count($modelIds),
            'total_chunks' => count($chunks),
            'job_ids' => json_encode($jobIds),
            'created_at' => now(),
        ]);

        return $batchId;
    }

    public function getBatchProgress(string $batchId): ?array
    {
        return cache()->get("batch_progress:{$batchId}") 
            ?: DB::table('batch_job_progress')->where('batch_id', $batchId)->first();
    }

    public function cancelBatch(string $batchId): bool
    {
        try {
            // Get job IDs from batch
            $batch = DB::table('workflow_batch_jobs')->where('batch_id', $batchId)->first();
            
            if (!$batch) {
                return false;
            }

            $jobIds = json_decode($batch->job_ids, true);

            // Cancel queued jobs
            foreach ($jobIds as $jobId) {
                Queue::deleteReserved('workflow-batch', $jobId);
            }

            // Mark batch as cancelled
            DB::table('batch_job_progress')->updateOrInsert(
                ['batch_id' => $batchId],
                [
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                ]
            );

            return true;

        } catch (\Exception $e) {
            logger()->error('Failed to cancel batch', [
                'batch_id' => $batchId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function determinePriority($model, string $transition): string
    {
        // Business logic to determine priority
        if (method_exists($model, 'getWorkflowPriority')) {
            return $model->getWorkflowPriority($transition);
        }

        // Default priority based on model type and transition
        return match(get_class($model)) {
            'App\\Models\\Order' => $this->getOrderPriority($model, $transition),
            'App\\Models\\Payment' => 'critical',
            'App\\Models\\User' => 'normal',
            default => 'normal'
        };
    }

    private function getOrderPriority($order, string $transition): string
    {
        // High-value orders get higher priority
        if ($order->total > 10000) {
            return 'high';
        }

        // Critical transitions for any order
        if (in_array($transition, ['cancel', 'refund'])) {
            return 'high';
        }

        return 'normal';
    }
}

// 5. Queue Health Monitor
class QueueHealthMonitor
{
    private array $queues = [
        'workflow-critical',
        'workflow-high', 
        'workflow-normal',
        'workflow-low',
        'workflow-batch'
    ];

    public function getQueueHealth(): array
    {
        $health = [];

        foreach ($this->queues as $queue) {
            $health[$queue] = $this->getQueueMetrics($queue);
        }

        return $health;
    }

    private function getQueueMetrics(string $queue): array
    {
        try {
            // Get Redis connection for queue metrics
            $redis = Redis::connection();
            
            $waiting = $redis->llen("queues:{$queue}");
            $delayed = $redis->zcard("queues:{$queue}:delayed");
            $reserved = $redis->llen("queues:{$queue}:reserved");
            $failed = $redis->llen("queues:{$queue}:failed");

            $totalJobs = $waiting + $delayed + $reserved;
            
            return [
                'waiting' => $waiting,
                'delayed' => $delayed,
                'reserved' => $reserved,
                'failed' => $failed,
                'total' => $totalJobs,
                'healthy' => $this->isQueueHealthy($waiting, $failed, $totalJobs),
                'last_checked' => now(),
            ];

        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
                'healthy' => false,
                'last_checked' => now(),
            ];
        }
    }

    private function isQueueHealthy(int $waiting, int $failed, int $total): bool
    {
        // Queue is unhealthy if:
        // - Too many jobs waiting (> 1000)
        // - High failure rate (> 10%)
        // - No processing activity for too long
        
        if ($waiting > 1000) {
            return false;
        }

        if ($total > 0 && ($failed / $total) > 0.1) {
            return false;
        }

        return true;
    }

    public function getFailedJobsStats(): array
    {
        return [
            'total_failed' => DB::table('workflow_failed_jobs')->count(),
            'failed_last_hour' => DB::table('workflow_failed_jobs')
                ->where('failed_at', '>=', now()->subHour())
                ->count(),
            'failed_by_transition' => DB::table('workflow_failed_jobs')
                ->select('transition', DB::raw('count(*) as count'))
                ->groupBy('transition')
                ->orderByDesc('count')
                ->limit(10)
                ->get(),
            'failed_by_model' => DB::table('workflow_failed_jobs')
                ->select('model_type', DB::raw('count(*) as count'))
                ->groupBy('model_type')
                ->orderByDesc('count')
                ->limit(10)
                ->get(),
        ];
    }
}

// 6. Workflow Event Listener for Queue Operations
class QueueWorkflowListener
{
    private WorkflowQueueManager $queueManager;

    public function __construct(WorkflowQueueManager $queueManager)
    {
        $this->queueManager = $queueManager;
    }

    public function handle(WorkflowTransitioned $event): void
    {
        $subject = $event->getSubject();
        $toState = $event->getToState();
        $context = $event->getContext();

        // Queue follow-up actions based on state
        $this->queueFollowUpActions($subject, $toState, $context);

        // Queue notifications
        $this->queueNotifications($subject, $toState, $context);

        // Queue analytics updates
        $this->queueAnalyticsUpdates($subject, $toState, $context);
    }

    private function queueFollowUpActions($subject, string $toState, array $context): void
    {
        $followUpActions = $this->getFollowUpActions($subject, $toState);

        foreach ($followUpActions as $action) {
            $job = new FollowUpActionJob($subject, $action, $context);
            dispatch($job)->onQueue('workflow-followup');
        }
    }

    private function queueNotifications($subject, string $toState, array $context): void
    {
        $notifications = $this->getNotificationsForState($subject, $toState);

        foreach ($notifications as $notification) {
            $job = new WorkflowNotificationJob($subject, $notification, $context);
            dispatch($job)->onQueue('notifications');
        }
    }

    private function queueAnalyticsUpdates($subject, string $toState, array $context): void
    {
        $job = new WorkflowAnalyticsJob($subject, $toState, $context);
        dispatch($job)->onQueue('analytics');
    }

    private function getFollowUpActions($subject, string $toState): array
    {
        // Return array of follow-up actions based on state
        return match($toState) {
            'processing' => ['validate_payment', 'reserve_inventory'],
            'shipped' => ['update_tracking', 'send_shipping_notification'],
            'delivered' => ['send_feedback_request', 'update_customer_metrics'],
            'cancelled' => ['process_refund', 'release_inventory'],
            default => []
        };
    }

    private function getNotificationsForState($subject, string $toState): array
    {
        // Return array of notifications to send
        return match($toState) {
            'processing' => ['order_processing_started'],
            'shipped' => ['order_shipped', 'tracking_available'],
            'delivered' => ['order_delivered', 'feedback_request'],
            'cancelled' => ['order_cancelled', 'refund_processing'],
            default => []
        };
    }
}

// 7. Usage Examples
class QueueIntegrationUsageExamples
{
    private WorkflowQueueManager $queueManager;

    public function __construct(WorkflowQueueManager $queueManager)
    {
        $this->queueManager = $queueManager;
    }

    public function queueSingleTransition($order): void
    {
        $jobId = $this->queueManager->queueTransition($order, 'process', [
            'priority' => 'high',
            'context' => ['payment_method' => 'credit_card'],
        ]);

        logger()->info('Order transition queued', [
            'order_id' => $order->id,
            'job_id' => $jobId,
        ]);
    }

    public function queueBatchProcessing(): void
    {
        $orderIds = Order::where('status', 'pending')
            ->where('created_at', '<=', now()->subHours(24))
            ->pluck('id')
            ->toArray();

        $batchId = $this->queueManager->queueBatchTransitions(
            Order::class,
            $orderIds,
            'auto_process',
            [
                'batch_size' => 50,
                'context' => ['auto_processing' => true],
            ]
        );

        logger()->info('Batch processing queued', [
            'batch_id' => $batchId,
            'total_orders' => count($orderIds),
        ]);
    }

    public function monitorBatchProgress(string $batchId): void
    {
        $progress = $this->queueManager->getBatchProgress($batchId);

        if ($progress) {
            echo "Batch Progress: {$progress['percentage']}% " .
                 "({$progress['processed']}/{$progress['total']} processed, " .
                 "{$progress['failed']} failed)\n";
        }
    }
}
