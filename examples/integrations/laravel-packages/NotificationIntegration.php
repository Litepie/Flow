<?php

namespace App\Examples\Integrations\LaravelPackages;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Litepie\Flow\Events\WorkflowTransitioned;
use Litepie\Flow\Traits\HasWorkflow;
use App\Models\User;

/**
 * Laravel Notification Integration with Litepie Flow
 * 
 * This example demonstrates how to integrate Laravel's notification system
 * with workflow transitions to send various types of notifications.
 */

// 1. Workflow State Change Notifications
class OrderStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private $order,
        private string $fromState,
        private string $toState,
        private array $context = []
    ) {}

    public function via($notifiable): array
    {
        $channels = ['database'];

        // Send email for important states
        if (in_array($this->toState, ['shipped', 'delivered', 'cancelled'])) {
            $channels[] = 'mail';
        }

        // Send SMS for urgent notifications
        if ($this->toState === 'cancelled' && $this->order->total > 1000) {
            $channels[] = 'nexmo';
        }

        // Send Slack notification for internal team
        if (in_array($this->toState, ['processing', 'shipped'])) {
            $channels[] = 'slack';
        }

        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Order #{$this->order->id} Status Update")
            ->greeting("Hello {$notifiable->name}!")
            ->line("Your order status has been updated.")
            ->line("Status changed from: {$this->fromState}")
            ->line("Status changed to: {$this->toState}")
            ->when($this->toState === 'shipped', function ($message) {
                return $message
                    ->line("Tracking Number: {$this->order->tracking_number}")
                    ->action('Track Your Order', url("/orders/{$this->order->id}/track"));
            })
            ->when($this->toState === 'cancelled', function ($message) {
                return $message
                    ->line('We apologize for any inconvenience.')
                    ->line('A refund will be processed within 3-5 business days.');
            })
            ->line('Thank you for your business!');
    }

    public function toSlack($notifiable): SlackMessage
    {
        $color = match($this->toState) {
            'processing' => 'warning',
            'shipped' => 'good',
            'delivered' => 'good',
            'cancelled' => 'danger',
            default => '#439FE0'
        };

        return (new SlackMessage)
            ->success()
            ->to('#order-updates')
            ->content("Order #{$this->order->id} status updated")
            ->attachment(function ($attachment) use ($color) {
                $attachment->title("Order Status: {$this->toState}")
                    ->color($color)
                    ->fields([
                        'Customer' => $this->order->customer->name,
                        'Amount' => '$' . number_format($this->order->total, 2),
                        'Previous State' => $this->fromState,
                        'New State' => $this->toState,
                        'Updated At' => now()->format('M j, Y g:i A'),
                    ]);
            });
    }

    public function toArray($notifiable): array
    {
        return [
            'order_id' => $this->order->id,
            'order_number' => $this->order->number,
            'from_state' => $this->fromState,
            'to_state' => $this->toState,
            'context' => $this->context,
            'message' => "Order #{$this->order->id} status changed from {$this->fromState} to {$this->toState}",
        ];
    }
}

// 2. Workflow Event Listener for Notifications
class WorkflowNotificationListener
{
    public function handle(WorkflowTransitioned $event): void
    {
        $subject = $event->getSubject();
        $fromState = $event->getFromState();
        $toState = $event->getToState();
        $context = $event->getContext();

        // Send notifications based on model type
        if ($subject instanceof \App\Models\Order) {
            $this->handleOrderNotifications($subject, $fromState, $toState, $context);
        } elseif ($subject instanceof \App\Models\User) {
            $this->handleUserNotifications($subject, $fromState, $toState, $context);
        } elseif ($subject instanceof \App\Models\Ticket) {
            $this->handleTicketNotifications($subject, $fromState, $toState, $context);
        }
    }

    private function handleOrderNotifications($order, $fromState, $toState, $context): void
    {
        // Notify customer
        $order->customer->notify(new OrderStatusNotification(
            $order,
            $fromState,
            $toState,
            $context
        ));

        // Notify internal team for specific states
        if ($toState === 'processing') {
            $this->notifyFulfillmentTeam($order, $context);
        } elseif ($toState === 'shipped') {
            $this->notifyCustomerService($order, $context);
        } elseif ($toState === 'cancelled') {
            $this->notifyAccountingTeam($order, $context);
        }
    }

    private function notifyFulfillmentTeam($order, $context): void
    {
        $fulfillmentUsers = User::role('fulfillment')->get();
        
        foreach ($fulfillmentUsers as $user) {
            $user->notify(new OrderReadyForFulfillmentNotification($order, $context));
        }
    }

    private function notifyCustomerService($order, $context): void
    {
        $csUsers = User::role('customer_service')->get();
        
        foreach ($csUsers as $user) {
            $user->notify(new OrderShippedNotification($order, $context));
        }
    }

    private function notifyAccountingTeam($order, $context): void
    {
        $accountingUsers = User::role('accounting')->get();
        
        foreach ($accountingUsers as $user) {
            $user->notify(new OrderCancelledNotification($order, $context));
        }
    }
}

// 3. Conditional Notifications Based on Workflow State
class ConditionalWorkflowNotification extends Notification
{
    use Queueable;

    public function __construct(
        private $subject,
        private string $workflowName,
        private string $state,
        private array $conditions = []
    ) {}

    public function via($notifiable): array
    {
        $channels = [];

        // Add channels based on conditions
        if ($this->shouldSendEmail()) {
            $channels[] = 'mail';
        }

        if ($this->shouldSendSMS()) {
            $channels[] = 'nexmo';
        }

        if ($this->shouldSendPush()) {
            $channels[] = 'fcm'; // Firebase Cloud Messaging
        }

        if ($this->shouldSendSlack()) {
            $channels[] = 'slack';
        }

        // Always store in database for audit trail
        $channels[] = 'database';

        return $channels;
    }

    private function shouldSendEmail(): bool
    {
        return $this->conditions['email'] ?? true;
    }

    private function shouldSendSMS(): bool
    {
        // Send SMS for high-priority notifications
        return ($this->conditions['priority'] ?? 'normal') === 'high' 
            && ($this->conditions['sms'] ?? false);
    }

    private function shouldSendPush(): bool
    {
        // Send push notifications for mobile app users
        return $this->conditions['push'] ?? false;
    }

    private function shouldSendSlack(): bool
    {
        // Send Slack notifications for internal workflows
        return in_array($this->workflowName, ['user_approval', 'document_review', 'task_assignment']);
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->getEmailSubject())
            ->line($this->getEmailContent())
            ->action('View Details', $this->getActionUrl())
            ->line('Thank you!');
    }

    private function getEmailSubject(): string
    {
        return match($this->workflowName) {
            'order_processing' => "Order Update - {$this->state}",
            'user_approval' => "Approval Required - {$this->state}",
            'document_review' => "Document Review - {$this->state}",
            default => "Workflow Update - {$this->state}"
        };
    }

    private function getEmailContent(): string
    {
        return match($this->workflowName) {
            'order_processing' => "Your order has been updated to: {$this->state}",
            'user_approval' => "A user approval request requires your attention: {$this->state}",
            'document_review' => "A document is ready for review: {$this->state}",
            default => "Workflow state changed to: {$this->state}"
        };
    }

    private function getActionUrl(): string
    {
        $modelClass = get_class($this->subject);
        $modelId = $this->subject->getKey();
        
        return match($this->workflowName) {
            'order_processing' => url("/orders/{$modelId}"),
            'user_approval' => url("/approvals/{$modelId}"),
            'document_review' => url("/documents/{$modelId}"),
            default => url("/workflows/{$this->workflowName}/{$modelId}")
        };
    }

    public function toArray($notifiable): array
    {
        return [
            'workflow_name' => $this->workflowName,
            'subject_type' => get_class($this->subject),
            'subject_id' => $this->subject->getKey(),
            'state' => $this->state,
            'conditions' => $this->conditions,
            'message' => $this->getEmailContent(),
        ];
    }
}

// 4. Notification Channels for Different Workflow Types
class WorkflowNotificationChannelManager
{
    private array $channelMappings = [
        'order_processing' => [
            'pending' => ['database'],
            'processing' => ['database', 'mail'],
            'shipped' => ['database', 'mail', 'sms'],
            'delivered' => ['database', 'mail'],
            'cancelled' => ['database', 'mail', 'slack'],
        ],
        'user_approval' => [
            'pending_approval' => ['database', 'mail', 'slack'],
            'approved' => ['database', 'mail'],
            'rejected' => ['database', 'mail', 'slack'],
        ],
        'document_review' => [
            'pending_review' => ['database', 'mail'],
            'under_review' => ['database'],
            'approved' => ['database', 'mail'],
            'rejected' => ['database', 'mail'],
        ],
    ];

    public function getChannelsForWorkflowState(string $workflowName, string $state): array
    {
        return $this->channelMappings[$workflowName][$state] ?? ['database'];
    }

    public function shouldNotify(string $workflowName, string $state, $notifiable): bool
    {
        // Check user preferences
        if (method_exists($notifiable, 'getNotificationPreferences')) {
            $preferences = $notifiable->getNotificationPreferences();
            
            if (!($preferences[$workflowName][$state] ?? true)) {
                return false;
            }
        }

        // Check business hours for non-urgent notifications
        if (!$this->isUrgentNotification($workflowName, $state)) {
            return $this->isBusinessHours();
        }

        return true;
    }

    private function isUrgentNotification(string $workflowName, string $state): bool
    {
        $urgentCombinations = [
            'order_processing' => ['cancelled'],
            'user_approval' => ['pending_approval'],
            'document_review' => ['rejected'],
        ];

        return in_array($state, $urgentCombinations[$workflowName] ?? []);
    }

    private function isBusinessHours(): bool
    {
        $now = now();
        $businessStart = $now->copy()->setTime(9, 0);
        $businessEnd = $now->copy()->setTime(17, 0);

        return $now->between($businessStart, $businessEnd) && $now->isWeekday();
    }
}

// 5. Bulk Notification for Multiple Workflow Transitions
class BulkWorkflowNotification extends Notification
{
    use Queueable;

    public function __construct(
        private array $transitions,
        private string $summary
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Workflow Summary Report')
            ->greeting("Hello {$notifiable->name}!")
            ->line($this->summary)
            ->line('Recent workflow transitions:');

        foreach ($this->transitions as $transition) {
            $message->line("• {$transition['subject_type']} #{$transition['subject_id']}: {$transition['from_state']} → {$transition['to_state']}");
        }

        return $message
            ->action('View Dashboard', url('/dashboard'))
            ->line('Thank you for using our application!');
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'bulk_workflow_summary',
            'summary' => $this->summary,
            'transitions_count' => count($this->transitions),
            'transitions' => $this->transitions,
        ];
    }
}

// 6. Service Provider Registration
class NotificationIntegrationServiceProvider
{
    public function boot(): void
    {
        // Register workflow notification listener
        Event::listen(
            WorkflowTransitioned::class,
            WorkflowNotificationListener::class
        );

        // Register notification channel manager
        $this->app->singleton(WorkflowNotificationChannelManager::class);

        // Extend notification with workflow-specific methods
        Notification::macro('forWorkflow', function (string $workflowName, string $state) {
            $this->workflowName = $workflowName;
            $this->workflowState = $state;
            return $this;
        });
    }
}

// 7. Configuration Example
/*
// config/notifications.php
return [
    'workflow_notifications' => [
        'enabled' => env('WORKFLOW_NOTIFICATIONS_ENABLED', true),
        'queue' => env('WORKFLOW_NOTIFICATIONS_QUEUE', 'default'),
        
        'channels' => [
            'order_processing' => [
                'pending' => ['database'],
                'processing' => ['database', 'mail'],
                'shipped' => ['database', 'mail', 'sms'],
                'delivered' => ['database', 'mail'],
                'cancelled' => ['database', 'mail', 'slack'],
            ],
        ],
        
        'rate_limiting' => [
            'enabled' => true,
            'max_per_hour' => 100,
            'max_per_day' => 1000,
        ],
        
        'templates' => [
            'order_processing' => [
                'shipped' => 'emails.order.shipped',
                'delivered' => 'emails.order.delivered',
                'cancelled' => 'emails.order.cancelled',
            ],
        ],
    ],
];
*/

// 8. Usage Examples
class NotificationUsageExamples
{
    public function sendOrderStatusNotification($order, $fromState, $toState): void
    {
        $order->customer->notify(new OrderStatusNotification(
            $order,
            $fromState,
            $toState,
            ['priority' => 'high', 'sms' => true]
        ));
    }

    public function sendConditionalNotification($subject, $workflowName, $state): void
    {
        $user = User::find(1);
        
        $user->notify(new ConditionalWorkflowNotification(
            $subject,
            $workflowName,
            $state,
            [
                'email' => true,
                'sms' => false,
                'push' => true,
                'priority' => 'normal'
            ]
        ));
    }

    public function sendBulkNotificationSummary(array $transitions): void
    {
        $adminUsers = User::role('admin')->get();
        
        foreach ($adminUsers as $admin) {
            $admin->notify(new BulkWorkflowNotification(
                $transitions,
                'Daily workflow summary report'
            ));
        }
    }
}
