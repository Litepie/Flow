# Workflows

This guide covers everything you need to know about creating and managing workflows in Litepie Flow.

## Table of Contents

- [Overview](#overview)
- [Creating Workflows](#creating-workflows)
- [Workflow Types](#workflow-types)
- [Workflow Configuration](#workflow-configuration)
- [Managing Workflows](#managing-workflows)
- [Best Practices](#best-practices)
- [Examples](#examples)

## Overview

A workflow represents a complete business process that defines how entities move through different states. In Litepie Flow, workflows are composed of:

- **States**: The different stages an entity can be in
- **Transitions**: The allowed movements between states
- **Actions**: Business logic executed during transitions
- **Guards**: Conditions that must be met for transitions
- **Events**: Notifications fired during workflow execution

## Creating Workflows

### Basic Workflow Structure

```php
<?php

namespace App\Workflows;

use Litepie\Flow\Workflows\Workflow;
use Litepie\Flow\States\State;
use Litepie\Flow\Transitions\Transition;

class DocumentWorkflow
{
    public static function create(): Workflow
    {
        $workflow = new Workflow('document_approval', 'Document Approval Process');
        
        // Define states
        $draft = new State('draft', 'Draft', true); // Initial state
        $review = new State('review', 'Under Review');
        $approved = new State('approved', 'Approved', false, true); // Final state
        $rejected = new State('rejected', 'Rejected', false, true); // Final state
        
        // Add states
        $workflow->addState($draft)
                 ->addState($review)
                 ->addState($approved)
                 ->addState($rejected);
        
        // Define transitions
        $submitTransition = new Transition('draft', 'review', 'submit');
        $approveTransition = new Transition('review', 'approved', 'approve');
        $rejectTransition = new Transition('review', 'rejected', 'reject');
        $reviseTransition = new Transition('review', 'draft', 'revise');
        
        // Add transitions
        $workflow->addTransition($submitTransition)
                 ->addTransition($approveTransition)
                 ->addTransition($rejectTransition)
                 ->addTransition($reviseTransition);
        
        return $workflow;
    }
}
```

### Advanced Workflow with Actions and Guards

```php
<?php

namespace App\Workflows;

use Litepie\Flow\Workflows\Workflow;
use Litepie\Flow\States\State;
use Litepie\Flow\Transitions\Transition;
use App\Actions\ValidateDocumentAction;
use App\Actions\NotifyApproverAction;
use App\Actions\SendApprovalEmailAction;
use App\Guards\DocumentCompleteGuard;

class AdvancedDocumentWorkflow
{
    public static function create(): Workflow
    {
        $workflow = new Workflow('advanced_document', 'Advanced Document Workflow');
        
        // States with metadata
        $draft = new State('draft', 'Draft', true, false, [
            'description' => 'Document is being created',
            'color' => '#yellow'
        ]);
        
        $review = new State('review', 'Under Review', false, false, [
            'description' => 'Document is under review',
            'color' => '#blue'
        ]);
        
        $approved = new State('approved', 'Approved', false, true, [
            'description' => 'Document has been approved',
            'color' => '#green'
        ]);
        
        $workflow->addState($draft)
                 ->addState($review)
                 ->addState($approved);
        
        // Transition with actions and guards
        $submitTransition = new Transition('draft', 'review', 'submit');
        $submitTransition->addGuard(new DocumentCompleteGuard())
                        ->addAction(new ValidateDocumentAction())
                        ->addAction(new NotifyApproverAction());
        
        $approveTransition = new Transition('review', 'approved', 'approve');
        $approveTransition->addAction(new SendApprovalEmailAction());
        
        $workflow->addTransition($submitTransition)
                 ->addTransition($approveTransition);
        
        return $workflow;
    }
}
```

## Workflow Types

### Linear Workflows

Simple workflows where entities move through states in a linear fashion:

```php
// Draft → Review → Approved
$workflow = new Workflow('linear_approval', 'Linear Approval');
```

### Branching Workflows

Workflows with multiple possible paths:

```php
// Draft → Review → (Approved | Rejected)
//           ↓
//        Revision
```

### Cyclic Workflows

Workflows that allow returning to previous states:

```php
// Draft ⟷ Review ⟷ Testing → Production
```

### Parallel Workflows

Workflows with parallel state branches:

```php
// Start → (Review A, Review B) → Merge → End
```

## Workflow Configuration

### Metadata

Add metadata to workflows for additional context:

```php
$workflow = new Workflow('order_processing', 'Order Processing', [
    'version' => '1.0',
    'department' => 'sales',
    'priority' => 'high',
    'timeout' => 3600, // 1 hour
    'auto_advance' => true
]);
```

### Conditional Logic

Add conditions to control workflow behavior:

```php
class ConditionalWorkflow
{
    public static function create(): Workflow
    {
        $workflow = new Workflow('conditional_order', 'Conditional Order Processing');
        
        // Add conditional transitions based on order value
        $transition = new Transition('pending', 'processing', 'process');
        $transition->addCondition(function ($entity, $context) {
            return $entity->total >= 100; // Only process orders >= $100
        });
        
        $expressTransition = new Transition('pending', 'express_processing', 'express');
        $expressTransition->addCondition(function ($entity, $context) {
            return $entity->total >= 500; // Express for orders >= $500
        });
        
        return $workflow;
    }
}
```

## Managing Workflows

### Registering Workflows

Register workflows in your service provider:

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Litepie\Flow\Facades\Flow;

class WorkflowServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Register single workflow
        Flow::register('document_approval', DocumentWorkflow::create());
        
        // Register multiple workflows
        Flow::registerMany([
            'order_processing' => OrderWorkflow::create(),
            'user_registration' => UserRegistrationWorkflow::create(),
            'content_moderation' => ContentModerationWorkflow::create(),
        ]);
    }
}
```

### Dynamic Workflow Registration

Register workflows conditionally:

```php
public function boot(): void
{
    // Register based on configuration
    if (config('workflows.document_approval.enabled')) {
        Flow::register('document_approval', DocumentWorkflow::create());
    }
    
    // Register based on feature flags
    if (Feature::active('advanced-workflows')) {
        Flow::register('advanced_order', AdvancedOrderWorkflow::create());
    }
    
    // Register tenant-specific workflows
    if (app()->bound('tenant')) {
        $tenant = app('tenant');
        Flow::register(
            "tenant_{$tenant->id}_workflow",
            TenantSpecificWorkflow::create($tenant)
        );
    }
}
```

### Workflow Discovery

Automatically discover and register workflows:

```php
public function boot(): void
{
    $workflowClasses = glob(app_path('Workflows/*.php'));
    
    foreach ($workflowClasses as $workflowFile) {
        $className = 'App\\Workflows\\' . basename($workflowFile, '.php');
        
        if (class_exists($className) && method_exists($className, 'create')) {
            $workflowName = Str::snake(basename($workflowFile, '.php'));
            Flow::register($workflowName, $className::create());
        }
    }
}
```

## Best Practices

### 1. Naming Conventions

```php
// Use descriptive, action-oriented names
'order_processing'     // ✅ Good
'order'               // ❌ Too generic

// Use snake_case for workflow names
'user_registration'   // ✅ Good
'userRegistration'    // ❌ Inconsistent

// Use descriptive state names
'pending_payment'     // ✅ Good
'state1'             // ❌ Not descriptive
```

### 2. State Design

```php
// Keep states focused and specific
$states = [
    'draft',           // ✅ Clear purpose
    'pending_review',  // ✅ Specific status
    'approved',        // ✅ Clear outcome
];

// Avoid generic states
$states = [
    'active',          // ❌ Too generic
    'processing',      // ❌ Unclear what's processing
];
```

### 3. Transition Logic

```php
// Keep transitions simple and focused
$transition = new Transition('draft', 'review', 'submit');
$transition->addAction(new ValidateDocumentAction()); // ✅ Single responsibility

// Avoid complex transitions
$transition = new Transition('draft', 'review', 'submit_and_notify_and_log');
$transition->addAction(new ComplexMultiPurposeAction()); // ❌ Too complex
```

### 4. Error Handling

```php
class RobustWorkflow
{
    public static function create(): Workflow
    {
        $workflow = new Workflow('robust_process', 'Robust Process');
        
        // Add error handling states
        $error = new State('error', 'Error State');
        $workflow->addState($error);
        
        // Add error transitions
        $errorTransition = new Transition('*', 'error', 'handle_error');
        $retryTransition = new Transition('error', 'draft', 'retry');
        
        $workflow->addTransition($errorTransition)
                 ->addTransition($retryTransition);
        
        return $workflow;
    }
}
```

## Examples

### E-commerce Order Workflow

```php
class EcommerceOrderWorkflow
{
    public static function create(): Workflow
    {
        $workflow = new Workflow('ecommerce_order', 'E-commerce Order Processing');
        
        // States
        $cart = new State('cart', 'Shopping Cart', true);
        $pending = new State('pending_payment', 'Pending Payment');
        $paid = new State('paid', 'Payment Confirmed');
        $processing = new State('processing', 'Order Processing');
        $shipped = new State('shipped', 'Shipped');
        $delivered = new State('delivered', 'Delivered', false, true);
        $cancelled = new State('cancelled', 'Cancelled', false, true);
        $refunded = new State('refunded', 'Refunded', false, true);
        
        $workflow->addStates([$cart, $pending, $paid, $processing, $shipped, $delivered, $cancelled, $refunded]);
        
        // Transitions
        $checkout = new Transition('cart', 'pending_payment', 'checkout');
        $checkout->addAction(new CalculateOrderTotalAction())
                ->addAction(new ReserveInventoryAction());
        
        $pay = new Transition('pending_payment', 'paid', 'pay');
        $pay->addAction(new ProcessPaymentAction())
            ->addAction(new SendOrderConfirmationAction());
        
        $process = new Transition('paid', 'processing', 'process');
        $process->addAction(new UpdateInventoryAction())
               ->addAction(new NotifyWarehouseAction());
        
        $ship = new Transition('processing', 'shipped', 'ship');
        $ship->addAction(new GenerateTrackingNumberAction())
             ->addAction(new SendShippingNotificationAction());
        
        $deliver = new Transition('shipped', 'delivered', 'deliver');
        $deliver->addAction(new ConfirmDeliveryAction())
               ->addAction(new RequestReviewAction());
        
        // Cancellation paths
        $cancelPending = new Transition('pending_payment', 'cancelled', 'cancel');
        $cancelPaid = new Transition('paid', 'refunded', 'cancel_and_refund');
        $cancelPaid->addAction(new ProcessRefundAction());
        
        $workflow->addTransitions([
            $checkout, $pay, $process, $ship, $deliver,
            $cancelPending, $cancelPaid
        ]);
        
        return $workflow;
    }
}
```

### Content Publishing Workflow

```php
class ContentPublishingWorkflow
{
    public static function create(): Workflow
    {
        $workflow = new Workflow('content_publishing', 'Content Publishing Process');
        
        // States with role-based access
        $draft = new State('draft', 'Draft', true, false, ['roles' => ['author']]);
        $review = new State('editorial_review', 'Editorial Review', false, false, ['roles' => ['editor']]);
        $approved = new State('approved', 'Approved', false, false, ['roles' => ['editor']]);
        $published = new State('published', 'Published', false, true, ['roles' => ['publisher']]);
        $archived = new State('archived', 'Archived', false, true);
        
        $workflow->addStates([$draft, $review, $approved, $published, $archived]);
        
        // Role-based transitions
        $submit = new Transition('draft', 'editorial_review', 'submit_for_review');
        $submit->addGuard(new ContentCompleteGuard())
              ->addAction(new NotifyEditorsAction());
        
        $approve = new Transition('editorial_review', 'approved', 'approve');
        $approve->addGuard(new EditorRoleGuard())
               ->addAction(new ApproveContentAction());
        
        $publish = new Transition('approved', 'published', 'publish');
        $publish->addGuard(new PublisherRoleGuard())
               ->addAction(new PublishContentAction())
               ->addAction(new NotifySubscribersAction());
        
        $archive = new Transition('published', 'archived', 'archive');
        $archive->addAction(new ArchiveContentAction());
        
        // Rejection path
        $reject = new Transition('editorial_review', 'draft', 'reject');
        $reject->addAction(new NotifyAuthorAction());
        
        $workflow->addTransitions([$submit, $approve, $publish, $archive, $reject]);
        
        return $workflow;
    }
}
```

This documentation provides comprehensive coverage of workflow creation and management in Litepie Flow, from basic concepts to advanced implementation patterns.