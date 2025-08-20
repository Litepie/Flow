# States & Transitions

This guide covers the fundamental building blocks of workflows: states and transitions.

## Table of Contents

- [States](#states)
- [Transitions](#transitions)
- [Guards](#guards)
- [Conditions](#conditions)
- [Metadata](#metadata)
- [Advanced Patterns](#advanced-patterns)
- [Examples](#examples)

## States

States represent the different stages that an entity can be in during its lifecycle. Each state has properties that define its behavior and characteristics.

### Basic State Creation

```php
use Litepie\Flow\States\State;

// Basic state
$state = new State('pending', 'Pending');

// State with all options
$state = new State(
    name: 'pending',           // Unique identifier
    label: 'Pending Review',   // Human-readable name
    isInitial: true,           // Is this the starting state?
    isFinal: false,            // Is this an end state?
    metadata: [                // Additional data
        'color' => '#yellow',
        'icon' => 'clock',
        'description' => 'Waiting for review'
    ]
);
```

### State Types

#### Initial States
States where entities begin their workflow journey:

```php
$draft = new State('draft', 'Draft', true); // isInitial = true
```

#### Final States
States where entities complete their workflow:

```php
$completed = new State('completed', 'Completed', false, true); // isFinal = true
$cancelled = new State('cancelled', 'Cancelled', false, true);
```

#### Intermediate States
Regular states in the workflow process:

```php
$processing = new State('processing', 'Processing'); // Neither initial nor final
```

### State Properties

```php
class StateExample
{
    public function createStates()
    {
        // State with metadata
        $review = new State('review', 'Under Review', false, false, [
            'assignable_roles' => ['editor', 'admin'],
            'estimated_duration' => '2 days',
            'auto_advance' => false,
            'notifications' => [
                'entry' => ['email', 'slack'],
                'exit' => ['email']
            ]
        ]);

        // State with callbacks
        $processing = new State('processing', 'Processing');
        $processing->onEntry(function ($entity, $context) {
            Log::info("Entity {$entity->id} entered processing state");
        });
        
        $processing->onExit(function ($entity, $context) {
            Log::info("Entity {$entity->id} left processing state");
        });

        return [$review, $processing];
    }
}
```

### State Validation

```php
use Litepie\Flow\States\State;
use Litepie\Flow\Contracts\StateValidator;

class CustomStateValidator implements StateValidator
{
    public function validate(State $state, $entity, array $context = []): bool
    {
        // Custom validation logic
        if ($state->getName() === 'processing') {
            return $entity->hasRequiredFields();
        }
        
        return true;
    }
}

// Apply validator to state
$state = new State('processing', 'Processing');
$state->setValidator(new CustomStateValidator());
```

## Transitions

Transitions define how entities move between states and what actions are performed during the movement.

### Basic Transition Creation

```php
use Litepie\Flow\Transitions\Transition;

// Simple transition
$transition = new Transition('pending', 'approved', 'approve');

// Transition with metadata
$transition = new Transition(
    from: 'pending',
    to: 'approved', 
    name: 'approve',
    metadata: [
        'label' => 'Approve Request',
        'permission' => 'approve_requests',
        'confirmation_required' => true
    ]
);
```

### Transition Actions

Actions are executed when a transition occurs:

```php
use App\Actions\SendNotificationAction;
use App\Actions\UpdateStatusAction;

$transition = new Transition('pending', 'approved', 'approve');

// Add single action
$transition->addAction(new SendNotificationAction());

// Add multiple actions
$transition->addActions([
    new UpdateStatusAction(),
    new SendNotificationAction(),
    new LogTransitionAction()
]);

// Actions are executed in the order they are added
```

### Pre and Post Transition Hooks

```php
$transition = new Transition('draft', 'review', 'submit');

// Execute before the transition
$transition->beforeTransition(function ($entity, $context) {
    $entity->submitted_at = now();
    $entity->save();
});

// Execute after the transition
$transition->afterTransition(function ($entity, $context) {
    event(new DocumentSubmittedEvent($entity));
});
```

### Transition Validation

```php
$transition = new Transition('pending', 'approved', 'approve');

// Add validation
$transition->validate(function ($entity, $context) {
    if (!$entity->hasAllRequiredDocuments()) {
        throw new TransitionException('All required documents must be uploaded');
    }
    
    if (!$context['approver_id']) {
        throw new TransitionException('Approver must be specified');
    }
    
    return true;
});
```

## Guards

Guards are conditions that must be met before a transition can occur. They provide fine-grained control over when transitions are allowed.

### Creating Guards

```php
use Litepie\Flow\Guards\Guard;
use Litepie\Flow\Contracts\GuardInterface;

class DocumentCompleteGuard implements GuardInterface
{
    public function check($entity, array $context = []): bool
    {
        return $entity->title && 
               $entity->content && 
               $entity->author_id;
    }

    public function getMessage(): string
    {
        return 'Document must have title, content, and author before submission';
    }
}

// Apply guard to transition
$transition = new Transition('draft', 'review', 'submit');
$transition->addGuard(new DocumentCompleteGuard());
```

### Role-based Guards

```php
class RoleGuard implements GuardInterface
{
    public function __construct(
        private array $allowedRoles
    ) {}

    public function check($entity, array $context = []): bool
    {
        $user = auth()->user();
        return $user && $user->hasAnyRole($this->allowedRoles);
    }

    public function getMessage(): string
    {
        return 'Insufficient permissions for this transition';
    }
}

// Usage
$transition->addGuard(new RoleGuard(['editor', 'admin']));
```

### Time-based Guards

```php
class TimeBasedGuard implements GuardInterface
{
    public function check($entity, array $context = []): bool
    {
        $businessHours = [9, 17]; // 9 AM to 5 PM
        $currentHour = now()->hour;
        
        return $currentHour >= $businessHours[0] && 
               $currentHour < $businessHours[1];
    }

    public function getMessage(): string
    {
        return 'This transition is only allowed during business hours (9 AM - 5 PM)';
    }
}
```

### Conditional Guards

```php
class ConditionalGuard implements GuardInterface
{
    public function __construct(
        private \Closure $condition,
        private string $message = 'Condition not met'
    ) {}

    public function check($entity, array $context = []): bool
    {
        return call_user_func($this->condition, $entity, $context);
    }

    public function getMessage(): string
    {
        return $this->message;
    }
}

// Usage with closure
$transition->addGuard(new ConditionalGuard(
    function ($entity, $context) {
        return $entity->amount <= 1000 || $context['manager_approval'];
    },
    'Amounts over $1000 require manager approval'
));
```

## Conditions

Conditions are simpler than guards and return boolean values to control transition availability.

### Basic Conditions

```php
$transition = new Transition('pending', 'processing', 'process');

// Simple condition
$transition->addCondition(function ($entity, $context) {
    return $entity->payment_status === 'paid';
});

// Multiple conditions (all must be true)
$transition->addConditions([
    fn($entity, $context) => $entity->payment_status === 'paid',
    fn($entity, $context) => $entity->inventory_reserved === true,
    fn($entity, $context) => !$entity->is_cancelled
]);
```

### Named Conditions

```php
class PaymentConditions
{
    public static function isPaid(): \Closure
    {
        return fn($entity, $context) => $entity->payment_status === 'paid';
    }

    public static function hasValidPaymentMethod(): \Closure
    {
        return fn($entity, $context) => $entity->payment_method && 
                                       $entity->payment_method->isValid();
    }

    public static function sufficientFunds(): \Closure
    {
        return fn($entity, $context) => $entity->payment_method
                                              ->getBalance() >= $entity->total;
    }
}

// Usage
$transition->addConditions([
    PaymentConditions::isPaid(),
    PaymentConditions::hasValidPaymentMethod(),
    PaymentConditions::sufficientFunds()
]);
```

## Metadata

Both states and transitions can carry metadata for additional functionality.

### State Metadata

```php
$state = new State('review', 'Under Review', false, false, [
    'ui' => [
        'color' => '#blue',
        'icon' => 'eye',
        'badge_class' => 'badge-info'
    ],
    'permissions' => [
        'view' => ['author', 'editor', 'admin'],
        'edit' => ['editor', 'admin']
    ],
    'notifications' => [
        'entry' => [
            'email' => ['editor@example.com'],
            'slack' => ['#editorial-channel']
        ]
    ],
    'automation' => [
        'auto_advance_after' => '7 days',
        'reminder_after' => '3 days'
    ]
]);
```

### Transition Metadata

```php
$transition = new Transition('pending', 'approved', 'approve', [
    'ui' => [
        'label' => 'Approve Request',
        'button_class' => 'btn-success',
        'confirmation' => 'Are you sure you want to approve this request?'
    ],
    'audit' => [
        'log_level' => 'info',
        'include_context' => true,
        'notify_stakeholders' => true
    ],
    'business' => [
        'approval_level' => 2,
        'required_signatures' => 1,
        'delegation_allowed' => true
    ]
]);
```

## Advanced Patterns

### Parallel States

Handle entities that can be in multiple states simultaneously:

```php
class ParallelStatesWorkflow
{
    public static function create(): Workflow
    {
        $workflow = new Workflow('parallel_review', 'Parallel Review Process');

        // Content review states
        $contentDraft = new State('content_draft', 'Content Draft', true);
        $contentReview = new State('content_review', 'Content Under Review');
        $contentApproved = new State('content_approved', 'Content Approved');

        // Legal review states  
        $legalDraft = new State('legal_draft', 'Legal Draft', true);
        $legalReview = new State('legal_review', 'Legal Under Review');
        $legalApproved = new State('legal_approved', 'Legal Approved');

        // Final state (requires both approvals)
        $published = new State('published', 'Published', false, true);

        // Parallel transitions
        $contentTransition = new Transition(
            ['content_draft', 'legal_draft'], 
            ['content_review', 'legal_draft'], 
            'submit_content'
        );

        $legalTransition = new Transition(
            ['content_draft', 'legal_draft'], 
            ['content_draft', 'legal_review'], 
            'submit_legal'
        );

        // Merge transition (requires both approvals)
        $publishTransition = new Transition(
            ['content_approved', 'legal_approved'],
            'published',
            'publish'
        );

        return $workflow;
    }
}
```

### Conditional Transitions

Create transitions that are only available under certain conditions:

```php
class ConditionalTransitions
{
    public static function createOrderWorkflow(): Workflow
    {
        $workflow = new Workflow('conditional_order', 'Conditional Order Processing');

        $pending = new State('pending', 'Pending', true);
        $standardProcessing = new State('standard_processing', 'Standard Processing');
        $expressProcessing = new State('express_processing', 'Express Processing');
        $vipProcessing = new State('vip_processing', 'VIP Processing');

        // Standard transition (default)
        $standardTransition = new Transition('pending', 'standard_processing', 'process_standard');
        $standardTransition->addCondition(fn($entity) => $entity->total < 100);

        // Express transition (medium orders)
        $expressTransition = new Transition('pending', 'express_processing', 'process_express');
        $expressTransition->addCondition(fn($entity) => 
            $entity->total >= 100 && $entity->total < 500
        );

        // VIP transition (high-value orders)
        $vipTransition = new Transition('pending', 'vip_processing', 'process_vip');
        $vipTransition->addCondition(fn($entity) => 
            $entity->total >= 500 || $entity->customer->isVip()
        );

        $workflow->addStates([$pending, $standardProcessing, $expressProcessing, $vipProcessing]);
        $workflow->addTransitions([$standardTransition, $expressTransition, $vipTransition]);

        return $workflow;
    }
}
```

### State Hierarchies

Create nested or hierarchical states:

```php
class HierarchicalStates
{
    public static function createTicketWorkflow(): Workflow
    {
        $workflow = new Workflow('hierarchical_ticket', 'Hierarchical Ticket System');

        // Main states
        $open = new State('open', 'Open', true);
        $inProgress = new State('in_progress', 'In Progress');
        $resolved = new State('resolved', 'Resolved', false, true);

        // Sub-states for "In Progress"
        $investigating = new State('investigating', 'Investigating', false, false, [
            'parent' => 'in_progress'
        ]);
        $awaitingCustomer = new State('awaiting_customer', 'Awaiting Customer', false, false, [
            'parent' => 'in_progress'
        ]);
        $awaitingInternal = new State('awaiting_internal', 'Awaiting Internal', false, false, [
            'parent' => 'in_progress'
        ]);

        // Transitions between sub-states
        $startInvestigation = new Transition('open', 'investigating', 'start_investigation');
        $needCustomerInfo = new Transition('investigating', 'awaiting_customer', 'request_customer_info');
        $needInternalInfo = new Transition('investigating', 'awaiting_internal', 'request_internal_info');
        $continueInvestigation = new Transition('awaiting_customer', 'investigating', 'continue_investigation');
        $resolve = new Transition('investigating', 'resolved', 'resolve');

        return $workflow;
    }
}
```

## Examples

### E-commerce Order States & Transitions

```php
class EcommerceStatesTransitions
{
    public static function createOrderWorkflow(): Workflow
    {
        $workflow = new Workflow('ecommerce_order', 'E-commerce Order');

        // States with rich metadata
        $cart = new State('cart', 'Shopping Cart', true, false, [
            'ui' => ['color' => '#gray', 'icon' => 'shopping-cart'],
            'timeouts' => ['abandon_after' => '24 hours']
        ]);

        $pending = new State('pending_payment', 'Pending Payment', false, false, [
            'ui' => ['color' => '#yellow', 'icon' => 'credit-card'],
            'timeouts' => ['payment_timeout' => '30 minutes'],
            'reminders' => ['send_after' => '15 minutes']
        ]);

        $paid = new State('paid', 'Payment Confirmed', false, false, [
            'ui' => ['color' => '#green', 'icon' => 'check-circle'],
            'automation' => ['auto_advance_to' => 'processing']
        ]);

        $processing = new State('processing', 'Order Processing', false, false, [
            'ui' => ['color' => '#blue', 'icon' => 'cog'],
            'sla' => ['processing_time' => '2 business days']
        ]);

        $shipped = new State('shipped', 'Shipped', false, false, [
            'ui' => ['color' => '#purple', 'icon' => 'truck'],
            'tracking' => ['tracking_required' => true]
        ]);

        $delivered = new State('delivered', 'Delivered', false, true, [
            'ui' => ['color' => '#green', 'icon' => 'package'],
            'automation' => ['request_review_after' => '7 days']
        ]);

        $cancelled = new State('cancelled', 'Cancelled', false, true, [
            'ui' => ['color' => '#red', 'icon' => 'times-circle'],
            'cleanup' => ['release_inventory' => true]
        ]);

        // Add states to workflow
        $workflow->addStates([$cart, $pending, $paid, $processing, $shipped, $delivered, $cancelled]);

        // Transitions with guards and actions
        $checkout = new Transition('cart', 'pending_payment', 'checkout');
        $checkout->addGuard(new CartNotEmptyGuard())
                ->addGuard(new InventoryAvailableGuard())
                ->addAction(new CalculateOrderTotalAction())
                ->addAction(new ReserveInventoryAction());

        $payment = new Transition('pending_payment', 'paid', 'confirm_payment');
        $payment->addGuard(new PaymentValidGuard())
               ->addAction(new ProcessPaymentAction())
               ->addAction(new SendConfirmationEmailAction());

        $process = new Transition('paid', 'processing', 'start_processing');
        $process->addAction(new NotifyWarehouseAction())
               ->addAction(new GeneratePickingListAction());

        $ship = new Transition('processing', 'shipped', 'ship_order');
        $ship->addGuard(new OrderPackagedGuard())
            ->addAction(new GenerateTrackingNumberAction())
            ->addAction(new UpdateInventoryAction())
            ->addAction(new SendShippingNotificationAction());

        $deliver = new Transition('shipped', 'delivered', 'confirm_delivery');
        $deliver->addAction(new ConfirmDeliveryAction())
               ->addAction(new RequestReviewAction());

        // Cancellation transitions
        $cancelFromCart = new Transition('cart', 'cancelled', 'abandon_cart');
        $cancelFromPending = new Transition('pending_payment', 'cancelled', 'cancel_order');
        $cancelFromPaid = new Transition('paid', 'cancelled', 'cancel_paid_order');
        $cancelFromPaid->addAction(new ProcessRefundAction());

        $workflow->addTransitions([
            $checkout, $payment, $process, $ship, $deliver,
            $cancelFromCart, $cancelFromPending, $cancelFromPaid
        ]);

        return $workflow;
    }
}
```

### Document Review Workflow with Complex Guards

```php
class DocumentReviewWorkflow
{
    public static function create(): Workflow
    {
        $workflow = new Workflow('document_review', 'Document Review Process');

        // States with role-based metadata
        $draft = new State('draft', 'Draft', true, false, [
            'permissions' => ['edit' => ['author'], 'view' => ['author', 'editor']],
            'ui' => ['color' => '#gray', 'icon' => 'edit']
        ]);

        $submitted = new State('submitted', 'Submitted for Review', false, false, [
            'permissions' => ['edit' => [], 'view' => ['author', 'editor', 'reviewer']],
            'ui' => ['color' => '#blue', 'icon' => 'eye'],
            'sla' => ['review_within' => '3 business days']
        ]);

        $underReview = new State('under_review', 'Under Review', false, false, [
            'permissions' => ['edit' => ['reviewer'], 'view' => ['author', 'editor', 'reviewer']],
            'ui' => ['color' => '#orange', 'icon' => 'search']
        ]);

        $changesRequested = new State('changes_requested', 'Changes Requested', false, false, [
            'permissions' => ['edit' => ['author'], 'view' => ['author', 'editor', 'reviewer']],
            'ui' => ['color' => '#yellow', 'icon' => 'exclamation-triangle']
        ]);

        $approved = new State('approved', 'Approved', false, false, [
            'permissions' => ['edit' => [], 'view' => ['author', 'editor', 'reviewer', 'publisher']],
            'ui' => ['color' => '#green', 'icon' => 'check']
        ]);

        $published = new State('published', 'Published', false, true, [
            'permissions' => ['edit' => [], 'view' => ['*']],
            'ui' => ['color' => '#success', 'icon' => 'globe']
        ]);

        $rejected = new State('rejected', 'Rejected', false, true, [
            'permissions' => ['edit' => [], 'view' => ['author', 'editor', 'reviewer']],
            'ui' => ['color' => '#red', 'icon' => 'times']
        ]);

        $workflow->addStates([
            $draft, $submitted, $underReview, $changesRequested, 
            $approved, $published, $rejected
        ]);

        // Complex transitions with multiple guards
        $submit = new Transition('draft', 'submitted', 'submit_for_review');
        $submit->addGuards([
                new DocumentCompleteGuard(),
                new AuthorRoleGuard(),
                new WordCountGuard(500, 5000), // Min 500, max 5000 words
                new PlagiarismCheckGuard()
            ])
            ->addActions([
                new ValidateDocumentAction(),
                new NotifyReviewersAction(),
                new LogSubmissionAction()
            ]);

        $startReview = new Transition('submitted', 'under_review', 'start_review');
        $startReview->addGuards([
                new ReviewerRoleGuard(),
                new ReviewerAvailabilityGuard()
            ])
            ->addActions([
                new AssignReviewerAction(),
                new NotifyAuthorAction('review_started')
            ]);

        $requestChanges = new Transition('under_review', 'changes_requested', 'request_changes');
        $requestChanges->addGuards([
                new ReviewerRoleGuard(),
                new FeedbackProvidedGuard()
            ])
            ->addActions([
                new CreateFeedbackAction(),
                new NotifyAuthorAction('changes_requested'),
                new ScheduleFollowUpAction()
            ]);

        $resubmit = new Transition('changes_requested', 'submitted', 'resubmit');
        $resubmit->addGuards([
                new AuthorRoleGuard(),
                new ChangesAddressedGuard()
            ])
            ->addActions([
                new UpdateDocumentAction(),
                new NotifyReviewersAction()
            ]);

        $approve = new Transition('under_review', 'approved', 'approve');
        $approve->addGuards([
                new ReviewerRoleGuard(),
                new QualityStandardsGuard()
            ])
            ->addActions([
                new ApproveDocumentAction(),
                new NotifyPublishersAction(),
                new UpdateMetricsAction()
            ]);

        $publish = new Transition('approved', 'published', 'publish');
        $publish->addGuards([
                new PublisherRoleGuard(),
                new SchedulingGuard(),
                new FinalQualityCheckGuard()
            ])
            ->addActions([
                new PublishDocumentAction(),
                new NotifySubscribersAction(),
                new UpdateSEOAction(),
                new AnalyticsTrackingAction()
            ]);

        $reject = new Transition('under_review', 'rejected', 'reject');
        $reject->addGuards([
                new ReviewerRoleGuard(),
                new RejectionReasonGuard()
            ])
            ->addActions([
                new RejectDocumentAction(),
                new NotifyAuthorAction('rejected'),
                new ArchiveDocumentAction()
            ]);

        $workflow->addTransitions([
            $submit, $startReview, $requestChanges, $resubmit, 
            $approve, $publish, $reject
        ]);

        return $workflow;
    }
}
```

### Custom Guard Examples

```php
// Word count validation
class WordCountGuard implements GuardInterface
{
    public function __construct(
        private int $minWords,
        private int $maxWords
    ) {}

    public function check($entity, array $context = []): bool
    {
        $wordCount = str_word_count(strip_tags($entity->content));
        return $wordCount >= $this->minWords && $wordCount <= $this->maxWords;
    }

    public function getMessage(): string
    {
        return "Document must be between {$this->minWords} and {$this->maxWords} words";
    }
}

// Business hours validation
class BusinessHoursGuard implements GuardInterface
{
    public function check($entity, array $context = []): bool
    {
        $now = now();
        return $now->isWeekday() && 
               $now->hour >= 9 && 
               $now->hour < 17;
    }

    public function getMessage(): string
    {
        return 'This action can only be performed during business hours (9 AM - 5 PM, Monday-Friday)';
    }
}

// Approval hierarchy validation
class ApprovalHierarchyGuard implements GuardInterface
{
    public function check($entity, array $context = []): bool
    {
        $user = auth()->user();
        $requiredLevel = $entity->getRequiredApprovalLevel();
        
        return $user->approval_level >= $requiredLevel;
    }

    public function getMessage(): string
    {
        return 'Insufficient approval level for this action';
    }
}

// Resource availability validation
class ResourceAvailabilityGuard implements GuardInterface
{
    public function __construct(private string $resourceType) {}

    public function check($entity, array $context = []): bool
    {
        return app('resource.manager')->isAvailable($this->resourceType, $entity);
    }

    public function getMessage(): string
    {
        return "Required {$this->resourceType} is not available";
    }
}
```

### Advanced Transition Patterns

```php
class AdvancedTransitionPatterns
{
    // Bulk transitions
    public static function createBulkTransition(): Transition
    {
        $transition = new Transition('pending', 'processed', 'bulk_process');
        
        $transition->addAction(new class implements ActionInterface {
            public function execute($entity, array $context = []): ActionResult
            {
                // Handle bulk processing
                $entities = $context['entities'] ?? [$entity];
                
                foreach ($entities as $item) {
                    // Process each item
                    $this->processItem($item, $context);
                }
                
                return new SuccessResult(['processed_count' => count($entities)]);
            }
        });
        
        return $transition;
    }

    // Conditional branching
    public static function createConditionalBranching(): array
    {
        $transitions = [];
        
        // High priority path
        $highPriorityTransition = new Transition('submitted', 'high_priority_review', 'prioritize');
        $highPriorityTransition->addCondition(fn($entity) => $entity->priority === 'high');
        
        // Standard path
        $standardTransition = new Transition('submitted', 'standard_review', 'review');
        $standardTransition->addCondition(fn($entity) => $entity->priority !== 'high');
        
        return [$highPriorityTransition, $standardTransition];
    }

    // Time-delayed transitions
    public static function createDelayedTransition(): Transition
    {
        $transition = new Transition('processing', 'completed', 'auto_complete');
        
        $transition->addAction(new class implements ActionInterface {
            public function execute($entity, array $context = []): ActionResult
            {
                // Schedule delayed execution
                ProcessCompletionJob::dispatch($entity)
                    ->delay(now()->addHours(24));
                
                return new SuccessResult(['scheduled_for' => now()->addHours(24)]);
            }
        });
        
        return $transition;
    }

    // Rollback transitions
    public static function createRollbackTransition(): Transition
    {
        $transition = new Transition('*', 'previous_state', 'rollback');
        
        $transition->addGuard(new class implements GuardInterface {
            public function check($entity, array $context = []): bool
            {
                return $entity->hasWorkflowHistory() && 
                       auth()->user()->can('rollback_workflow');
            }
            
            public function getMessage(): string
            {
                return 'Cannot rollback: insufficient permissions or no history';
            }
        });
        
        $transition->addAction(new class implements ActionInterface {
            public function execute($entity, array $context = []): ActionResult
            {
                $previousState = $entity->getPreviousWorkflowState();
                $entity->workflow_state = $previousState;
                $entity->save();
                
                return new SuccessResult(['rolled_back_to' => $previousState]);
            }
        });
        
        return $transition;
    }
}
```

This comprehensive documentation covers all aspects of states and transitions in Litepie Flow, from basic concepts to advanced implementation patterns, providing developers with everything they need to create sophisticated workflow systems.