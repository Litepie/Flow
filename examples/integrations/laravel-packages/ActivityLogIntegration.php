<?php

namespace App\Examples\Integrations\LaravelPackages;

use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\ActivityLogger;
use Litepie\Flow\Events\WorkflowTransitioned;
use Litepie\Flow\Traits\HasWorkflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

/**
 * Spatie Activity Log Integration with Litepie Flow
 * 
 * This example demonstrates how to integrate Spatie's Activity Log package
 * with workflow transitions to create comprehensive audit trails.
 */

// 1. Model with Activity Logging and Workflow
class Order extends Model
{
    use HasWorkflow, LogsActivity;

    protected $fillable = [
        'customer_id',
        'total',
        'state',
        'notes',
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'array',
        'total' => 'decimal:2'
    ];

    public function getWorkflowName(): string
    {
        return 'order_processing';
    }

    protected function getWorkflowStateColumn(): string
    {
        return 'state';
    }

    // Activity Log Configuration
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'customer_id',
                'total',
                'state',
                'notes',
                'metadata'
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => $this->getActivityDescription($eventName))
            ->useLogName('order_workflow');
    }

    private function getActivityDescription(string $eventName): string
    {
        return match($eventName) {
            'created' => 'Order was created',
            'updated' => 'Order was updated',
            'deleted' => 'Order was deleted',
            'workflow_transition' => 'Order workflow state changed',
            default => "Order {$eventName}"
        };
    }

    // Custom activity logging for workflow transitions
    public function logWorkflowTransition(string $fromState, string $toState, array $context = []): void
    {
        activity('order_workflow')
            ->performedOn($this)
            ->causedBy(auth()->user())
            ->withProperties([
                'from_state' => $fromState,
                'to_state' => $toState,
                'context' => $context,
                'order_data' => [
                    'id' => $this->id,
                    'total' => $this->total,
                    'customer_id' => $this->customer_id,
                ],
                'timestamp' => now()->toISOString(),
            ])
            ->log("Order state changed from {$fromState} to {$toState}");
    }

    // Custom activity for specific workflow events
    public function logPaymentProcessed(array $paymentDetails): void
    {
        activity('order_payment')
            ->performedOn($this)
            ->causedBy(auth()->user())
            ->withProperties([
                'payment_method' => $paymentDetails['method'] ?? 'unknown',
                'amount' => $paymentDetails['amount'] ?? $this->total,
                'transaction_id' => $paymentDetails['transaction_id'] ?? null,
                'processor' => $paymentDetails['processor'] ?? 'default',
            ])
            ->log('Payment processed for order');
    }

    public function logShippingUpdated(array $shippingDetails): void
    {
        activity('order_shipping')
            ->performedOn($this)
            ->causedBy(auth()->user())
            ->withProperties([
                'shipping_method' => $shippingDetails['method'] ?? 'standard',
                'tracking_number' => $shippingDetails['tracking_number'] ?? null,
                'carrier' => $shippingDetails['carrier'] ?? 'default',
                'estimated_delivery' => $shippingDetails['estimated_delivery'] ?? null,
            ])
            ->log('Shipping information updated for order');
    }
}

// 2. Workflow Activity Logger Service
class WorkflowActivityLogger
{
    public function __construct(
        private ActivityLogger $activityLogger
    ) {}

    public function logTransition($subject, string $fromState, string $toState, array $context = []): void
    {
        $properties = [
            'workflow_name' => method_exists($subject, 'getWorkflowName') ? $subject->getWorkflowName() : 'unknown',
            'from_state' => $fromState,
            'to_state' => $toState,
            'context' => $this->sanitizeContext($context),
            'subject_type' => get_class($subject),
            'subject_id' => $subject->getKey(),
        ];

        // Add subject-specific properties
        if (method_exists($subject, 'getActivityProperties')) {
            $properties = array_merge($properties, $subject->getActivityProperties());
        }

        $this->activityLogger
            ->performedOn($subject)
            ->causedBy($this->getCauser($context))
            ->withProperties($properties)
            ->useLog($this->getLogName($subject))
            ->log($this->getLogDescription($subject, $fromState, $toState));
    }

    public function logAction($subject, string $actionName, array $context = [], bool $success = true): void
    {
        $properties = [
            'action_name' => $actionName,
            'success' => $success,
            'context' => $this->sanitizeContext($context),
            'subject_type' => get_class($subject),
            'subject_id' => $subject->getKey(),
        ];

        if (!$success && isset($context['error'])) {
            $properties['error'] = $context['error'];
        }

        $this->activityLogger
            ->performedOn($subject)
            ->causedBy($this->getCauser($context))
            ->withProperties($properties)
            ->useLog($this->getLogName($subject) . '_actions')
            ->log($this->getActionDescription($actionName, $success));
    }

    public function logGuardCheck($subject, string $guardName, bool $passed, array $context = []): void
    {
        $properties = [
            'guard_name' => $guardName,
            'passed' => $passed,
            'context' => $this->sanitizeContext($context),
            'subject_type' => get_class($subject),
            'subject_id' => $subject->getKey(),
        ];

        if (!$passed && isset($context['reason'])) {
            $properties['failure_reason'] = $context['reason'];
        }

        $this->activityLogger
            ->performedOn($subject)
            ->causedBy($this->getCauser($context))
            ->withProperties($properties)
            ->useLog($this->getLogName($subject) . '_guards')
            ->log($this->getGuardDescription($guardName, $passed));
    }

    private function sanitizeContext(array $context): array
    {
        // Remove sensitive information before logging
        $sanitized = $context;
        
        $sensitiveKeys = [
            'password',
            'password_confirmation',
            'token',
            'api_key',
            'secret',
            'credit_card',
            'ssn',
            'bank_account'
        ];

        foreach ($sensitiveKeys as $key) {
            if (isset($sanitized[$key])) {
                $sanitized[$key] = '[REDACTED]';
            }
        }

        return $sanitized;
    }

    private function getCauser(array $context)
    {
        if (isset($context['causer'])) {
            return $context['causer'];
        }

        if (auth()->check()) {
            return auth()->user();
        }

        return null;
    }

    private function getLogName($subject): string
    {
        $className = class_basename($subject);
        return strtolower($className) . '_workflow';
    }

    private function getLogDescription($subject, string $fromState, string $toState): string
    {
        $className = class_basename($subject);
        return "{$className} workflow state changed from {$fromState} to {$toState}";
    }

    private function getActionDescription(string $actionName, bool $success): string
    {
        $status = $success ? 'completed successfully' : 'failed';
        return "Workflow action '{$actionName}' {$status}";
    }

    private function getGuardDescription(string $guardName, bool $passed): string
    {
        $status = $passed ? 'passed' : 'failed';
        return "Workflow guard '{$guardName}' {$status}";
    }
}

// 3. Event Listener for Workflow Transitions
class WorkflowActivityListener
{
    public function __construct(
        private WorkflowActivityLogger $activityLogger
    ) {}

    public function handle(WorkflowTransitioned $event): void
    {
        $subject = $event->getSubject();
        $fromState = $event->getFromState();
        $toState = $event->getToState();
        $context = $event->getContext();

        // Log the transition
        $this->activityLogger->logTransition($subject, $fromState, $toState, $context);

        // Log additional context if available
        if (isset($context['action_results'])) {
            foreach ($context['action_results'] as $actionName => $result) {
                $this->activityLogger->logAction(
                    $subject,
                    $actionName,
                    $result,
                    $result['success'] ?? true
                );
            }
        }

        if (isset($context['guard_results'])) {
            foreach ($context['guard_results'] as $guardName => $result) {
                $this->activityLogger->logGuardCheck(
                    $subject,
                    $guardName,
                    $result['passed'] ?? true,
                    $result
                );
            }
        }
    }
}

// 4. Activity Log Analytics Service
class WorkflowActivityAnalytics
{
    public function getWorkflowStatistics(string $workflowName, $startDate = null, $endDate = null): array
    {
        $query = Activity::where('log_name', 'LIKE', '%workflow%')
            ->where('properties->workflow_name', $workflowName);

        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }

        $activities = $query->get();

        return [
            'total_transitions' => $activities->count(),
            'transitions_by_state' => $this->getTransitionsByState($activities),
            'most_active_users' => $this->getMostActiveUsers($activities),
            'transition_frequency' => $this->getTransitionFrequency($activities),
            'average_time_in_states' => $this->getAverageTimeInStates($activities),
        ];
    }

    public function getFailedActions(string $workflowName, $startDate = null, $endDate = null): array
    {
        $query = Activity::where('log_name', 'LIKE', '%actions%')
            ->where('properties->success', false);

        if ($workflowName) {
            $query->where('properties->workflow_name', $workflowName);
        }

        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }

        return $query->with(['causer', 'subject'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($activity) {
                return [
                    'id' => $activity->id,
                    'action_name' => $activity->properties['action_name'] ?? 'unknown',
                    'error' => $activity->properties['error'] ?? 'No error details',
                    'subject_type' => $activity->properties['subject_type'] ?? 'unknown',
                    'subject_id' => $activity->properties['subject_id'] ?? null,
                    'causer' => $activity->causer ? $activity->causer->name : 'System',
                    'created_at' => $activity->created_at,
                ];
            })
            ->toArray();
    }

    public function getUserWorkflowActivity($userId, $startDate = null, $endDate = null): array
    {
        $query = Activity::where('causer_id', $userId)
            ->where('log_name', 'LIKE', '%workflow%');

        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }

        $activities = $query->orderBy('created_at', 'desc')->get();

        return [
            'total_actions' => $activities->count(),
            'workflows_involved' => $activities->pluck('properties.workflow_name')->unique()->values(),
            'recent_activities' => $activities->take(20)->map(function ($activity) {
                return [
                    'description' => $activity->description,
                    'properties' => $activity->properties,
                    'created_at' => $activity->created_at,
                ];
            }),
        ];
    }

    private function getTransitionsByState(collection $activities): array
    {
        return $activities->groupBy('properties.to_state')
            ->map(fn($group) => $group->count())
            ->sortDesc()
            ->toArray();
    }

    private function getMostActiveUsers(collection $activities): array
    {
        return $activities->whereNotNull('causer_id')
            ->groupBy('causer_id')
            ->map(fn($group) => $group->count())
            ->sortDesc()
            ->take(10)
            ->toArray();
    }

    private function getTransitionFrequency(collection $activities): array
    {
        return $activities->groupBy(function ($activity) {
            return $activity->created_at->format('Y-m-d');
        })->map(fn($group) => $group->count())->toArray();
    }

    private function getAverageTimeInStates(collection $activities): array
    {
        // This would require more complex logic to calculate time spent in each state
        // Based on the sequence of state transitions
        return [];
    }
}

// 5. Activity Log Report Generator
class WorkflowActivityReportGenerator
{
    public function __construct(
        private WorkflowActivityAnalytics $analytics
    ) {}

    public function generateWorkflowReport(string $workflowName, array $options = []): array
    {
        $startDate = $options['start_date'] ?? now()->subMonth();
        $endDate = $options['end_date'] ?? now();

        $statistics = $this->analytics->getWorkflowStatistics($workflowName, $startDate, $endDate);
        $failedActions = $this->analytics->getFailedActions($workflowName, $startDate, $endDate);

        return [
            'workflow_name' => $workflowName,
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
            'statistics' => $statistics,
            'failed_actions' => $failedActions,
            'recommendations' => $this->generateRecommendations($statistics, $failedActions),
        ];
    }

    public function generateUserActivityReport($userId, array $options = []): array
    {
        $startDate = $options['start_date'] ?? now()->subMonth();
        $endDate = $options['end_date'] ?? now();

        $activity = $this->analytics->getUserWorkflowActivity($userId, $startDate, $endDate);

        return [
            'user_id' => $userId,
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
            'activity' => $activity,
        ];
    }

    private function generateRecommendations(array $statistics, array $failedActions): array
    {
        $recommendations = [];

        // Check for high failure rates
        if (count($failedActions) > 10) {
            $recommendations[] = 'High number of failed actions detected. Consider reviewing workflow logic.';
        }

        // Check for bottlenecks
        $transitions = $statistics['transitions_by_state'] ?? [];
        $maxTransitions = max($transitions);
        $bottleneckStates = array_filter($transitions, fn($count) => $count > $maxTransitions * 0.8);

        if (!empty($bottleneckStates)) {
            $recommendations[] = 'Potential bottleneck states detected: ' . implode(', ', array_keys($bottleneckStates));
        }

        return $recommendations;
    }
}

// 6. Service Provider for Activity Log Integration
class ActivityLogIntegrationServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WorkflowActivityLogger::class);
        $this->app->singleton(WorkflowActivityAnalytics::class);
        $this->app->singleton(WorkflowActivityReportGenerator::class);
    }

    public function boot(): void
    {
        // Register event listener
        Event::listen(
            WorkflowTransitioned::class,
            WorkflowActivityListener::class
        );

        // Register activity log configurations
        $this->registerActivityLogConfigurations();
    }

    private function registerActivityLogConfigurations(): void
    {
        // Custom activity log configurations for different model types
        config([
            'activitylog.subject_returns_soft_deleted_models' => true,
            'activitylog.default_log_name' => 'workflow',
            'activitylog.database_connection' => config('database.default'),
        ]);
    }
}

// 7. Usage Examples
class ActivityLogUsageExamples
{
    public function __construct(
        private WorkflowActivityLogger $activityLogger,
        private WorkflowActivityAnalytics $analytics,
        private WorkflowActivityReportGenerator $reportGenerator
    ) {}

    public function logOrderTransition(): void
    {
        $order = Order::find(1);
        
        // Manually log a transition
        $this->activityLogger->logTransition(
            $order,
            'pending',
            'processing',
            [
                'causer' => auth()->user(),
                'payment_method' => 'credit_card',
                'notes' => 'Payment processed successfully'
            ]
        );
    }

    public function logFailedAction(): void
    {
        $order = Order::find(1);
        
        // Log a failed action
        $this->activityLogger->logAction(
            $order,
            'process_payment',
            [
                'error' => 'Insufficient funds',
                'payment_method' => 'credit_card',
                'amount' => 99.99
            ],
            false
        );
    }

    public function generateReports(): void
    {
        // Generate workflow statistics
        $statistics = $this->analytics->getWorkflowStatistics('order_processing');
        
        // Generate failed actions report
        $failedActions = $this->analytics->getFailedActions('order_processing', now()->subWeek());
        
        // Generate user activity report
        $userActivity = $this->analytics->getUserWorkflowActivity(1, now()->subMonth());
        
        // Generate comprehensive report
        $report = $this->reportGenerator->generateWorkflowReport('order_processing', [
            'start_date' => now()->subMonth(),
            'end_date' => now(),
        ]);
    }
}

// 8. Custom Activity Log Model (Optional)
class WorkflowActivity extends Activity
{
    protected $table = 'activity_log';

    // Custom scopes for workflow activities
    public function scopeWorkflowTransitions($query)
    {
        return $query->where('log_name', 'LIKE', '%workflow%')
                    ->where('description', 'LIKE', '%state changed%');
    }

    public function scopeWorkflowActions($query)
    {
        return $query->where('log_name', 'LIKE', '%actions%');
    }

    public function scopeFailedActions($query)
    {
        return $query->workflowActions()
                    ->where('properties->success', false);
    }

    // Accessor for formatted properties
    public function getFormattedPropertiesAttribute(): string
    {
        if (!$this->properties) {
            return 'No additional details';
        }

        $formatted = [];
        foreach ($this->properties as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value);
            }
            $formatted[] = ucfirst(str_replace('_', ' ', $key)) . ': ' . $value;
        }

        return implode(', ', $formatted);
    }
}
