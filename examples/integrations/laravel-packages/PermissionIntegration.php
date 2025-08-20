<?php

namespace App\Examples\Integrations\LaravelPackages;

use Spatie\Permission\Traits\HasRoles;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Litepie\Flow\Events\WorkflowTransitioned;
use Litepie\Flow\Traits\HasWorkflow;
use Litepie\Flow\Contracts\Workflowable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Event;

/**
 * Laravel Permission Integration with Litepie Flow
 * 
 * This example demonstrates how to integrate Spatie's Laravel Permission package
 * with workflow transitions to implement role-based and permission-based access control.
 */

// 1. User Model with Roles and Workflow Permissions
class User extends Model implements Workflowable
{
    use HasRoles, HasWorkflow;

    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
        'department_id'
    ];

    public function getWorkflowName(): string
    {
        return 'user_lifecycle';
    }

    protected function getWorkflowStateColumn(): string
    {
        return 'status';
    }

    // Check if user can perform workflow transition
    public function canPerformTransition(string $transition, $subject = null): bool
    {
        // Check direct permission
        if ($this->can("workflow.{$transition}")) {
            return true;
        }

        // Check subject-specific permission
        if ($subject) {
            $subjectType = strtolower(class_basename($subject));
            if ($this->can("workflow.{$subjectType}.{$transition}")) {
                return true;
            }
        }

        // Check role-based permissions
        return $this->hasAnyRole($this->getTransitionRoles($transition));
    }

    private function getTransitionRoles(string $transition): array
    {
        $roleMapping = [
            'approve' => ['manager', 'admin', 'supervisor'],
            'reject' => ['manager', 'admin', 'supervisor'],
            'process' => ['processor', 'admin'],
            'cancel' => ['admin', 'customer_service'],
            'ship' => ['warehouse_manager', 'shipping_coordinator'],
            'deliver' => ['delivery_agent', 'warehouse_manager'],
        ];

        return $roleMapping[$transition] ?? [];
    }
}

// 2. Order Model with Permission-Based Transitions
class OrderWithPermissions extends Model implements Workflowable
{
    use HasWorkflow;

    protected $table = 'orders';
    
    protected $fillable = [
        'customer_id',
        'total',
        'state',
        'priority',
        'assigned_to'
    ];

    public function getWorkflowName(): string
    {
        return 'order_processing';
    }

    protected function getWorkflowStateColumn(): string
    {
        return 'state';
    }

    // Permission-based transition checks
    public function canTransitionTo(string $toState, $user = null): bool
    {
        $user = $user ?: auth()->user();
        
        if (!$user) {
            return false;
        }

        $fromState = $this->getCurrentState()?->getName();
        $transition = "{$fromState}_to_{$toState}";

        // Check basic workflow transition permission
        if (!$user->can("order.transition.{$transition}")) {
            return false;
        }

        // Additional business logic checks
        return $this->checkBusinessRules($fromState, $toState, $user);
    }

    private function checkBusinessRules(string $fromState, string $toState, $user): bool
    {
        // High-value orders require manager approval
        if ($this->total > 10000 && $toState === 'processing') {
            return $user->hasRole('manager') || $user->hasRole('admin');
        }

        // Only assigned users can process orders
        if ($toState === 'processing' && $this->assigned_to) {
            return $user->id === $this->assigned_to || $user->hasRole('admin');
        }

        // Department-specific restrictions
        if ($user->department_id && $this->customer) {
            return $this->customer->department_id === $user->department_id 
                || $user->hasRole('admin');
        }

        return true;
    }

    // Relationship
    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}

// 3. Permission-Based Workflow Guard
class PermissionWorkflowGuard
{
    public function canTransition($subject, string $fromState, string $toState, array $context = []): bool
    {
        $user = $context['user'] ?? auth()->user();

        if (!$user) {
            return false;
        }

        // Get transition permission name
        $permissionName = $this->getTransitionPermissionName($subject, $fromState, $toState);

        // Check if user has permission
        if (!$user->can($permissionName)) {
            return false;
        }

        // Check additional role-based restrictions
        return $this->checkRoleRestrictions($subject, $user, $fromState, $toState, $context);
    }

    private function getTransitionPermissionName($subject, string $fromState, string $toState): string
    {
        $subjectType = strtolower(class_basename($subject));
        return "workflow.{$subjectType}.{$fromState}_to_{$toState}";
    }

    private function checkRoleRestrictions($subject, $user, string $fromState, string $toState, array $context): bool
    {
        // Admin can do anything
        if ($user->hasRole('admin')) {
            return true;
        }

        // Subject owner permissions
        if (method_exists($subject, 'isOwnedBy') && $subject->isOwnedBy($user)) {
            return $this->checkOwnerPermissions($fromState, $toState);
        }

        // Department-based permissions
        if (method_exists($subject, 'belongsToDepartment') && method_exists($user, 'belongsToDepartment')) {
            if ($subject->belongsToDepartment($user->department_id)) {
                return $this->checkDepartmentPermissions($user, $fromState, $toState);
            }
        }

        return false;
    }

    private function checkOwnerPermissions(string $fromState, string $toState): bool
    {
        // Owners can typically cancel their own items
        $ownerAllowed = [
            'pending_to_cancelled',
            'draft_to_submitted',
            'submitted_to_cancelled'
        ];

        return in_array("{$fromState}_to_{$toState}", $ownerAllowed);
    }

    private function checkDepartmentPermissions($user, string $fromState, string $toState): bool
    {
        // Department managers have more permissions
        if ($user->hasRole('department_manager')) {
            return true;
        }

        // Regular department users have limited permissions
        $departmentAllowed = [
            'draft_to_submitted',
            'pending_to_processing'
        ];

        return in_array("{$fromState}_to_{$toState}", $departmentAllowed);
    }
}

// 4. Dynamic Permission Creator
class WorkflowPermissionCreator
{
    public function createWorkflowPermissions(string $workflowName, array $states, array $transitions): void
    {
        $guardName = 'web';

        // Create general workflow permissions
        $this->createGeneralPermissions($workflowName, $guardName);

        // Create state-based permissions
        $this->createStatePermissions($workflowName, $states, $guardName);

        // Create transition-based permissions
        $this->createTransitionPermissions($workflowName, $transitions, $guardName);

        // Clear permission cache
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function createGeneralPermissions(string $workflowName, string $guardName): void
    {
        $generalPermissions = [
            "workflow.{$workflowName}.view" => "View {$workflowName} workflow",
            "workflow.{$workflowName}.manage" => "Manage {$workflowName} workflow",
            "workflow.{$workflowName}.history" => "View {$workflowName} workflow history",
        ];

        foreach ($generalPermissions as $name => $description) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => $guardName,
            ], [
                'description' => $description,
            ]);
        }
    }

    private function createStatePermissions(string $workflowName, array $states, string $guardName): void
    {
        foreach ($states as $state) {
            $permissions = [
                "workflow.{$workflowName}.state.{$state}.view" => "View items in {$state} state",
                "workflow.{$workflowName}.state.{$state}.edit" => "Edit items in {$state} state",
            ];

            foreach ($permissions as $name => $description) {
                Permission::firstOrCreate([
                    'name' => $name,
                    'guard_name' => $guardName,
                ], [
                    'description' => $description,
                ]);
            }
        }
    }

    private function createTransitionPermissions(string $workflowName, array $transitions, string $guardName): void
    {
        foreach ($transitions as $transition) {
            $from = $transition['from'];
            $to = $transition['to'];
            $event = $transition['event'] ?? "{$from}_to_{$to}";

            Permission::firstOrCreate([
                'name' => "workflow.{$workflowName}.{$from}_to_{$to}",
                'guard_name' => $guardName,
            ], [
                'description' => "Transition {$workflowName} from {$from} to {$to}",
            ]);
        }
    }
}

// 5. Role-Based Workflow Manager
class RoleBasedWorkflowManager
{
    public function assignWorkflowRoles(): void
    {
        $this->createWorkflowRoles();
        $this->assignPermissionsToRoles();
    }

    private function createWorkflowRoles(): void
    {
        $roles = [
            'workflow_admin' => 'Can manage all workflows',
            'order_processor' => 'Can process orders',
            'order_manager' => 'Can manage order workflow',
            'customer_service' => 'Can handle customer service workflows',
            'warehouse_manager' => 'Can manage warehouse and shipping workflows',
            'approval_manager' => 'Can approve high-value transactions',
        ];

        foreach ($roles as $name => $description) {
            Role::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ], [
                'description' => $description,
            ]);
        }
    }

    private function assignPermissionsToRoles(): void
    {
        // Workflow Admin - all permissions
        $workflowAdmin = Role::findByName('workflow_admin');
        $workflowAdmin->givePermissionTo(Permission::where('name', 'like', 'workflow.%')->get());

        // Order Processor
        $orderProcessor = Role::findByName('order_processor');
        $orderProcessor->givePermissionTo([
            'workflow.order_processing.view',
            'workflow.order_processing.pending_to_processing',
            'workflow.order_processing.processing_to_shipped',
            'workflow.order_processing.state.pending.view',
            'workflow.order_processing.state.processing.view',
            'workflow.order_processing.state.processing.edit',
        ]);

        // Order Manager
        $orderManager = Role::findByName('order_manager');
        $orderManager->givePermissionTo([
            'workflow.order_processing.view',
            'workflow.order_processing.manage',
            'workflow.order_processing.history',
            'workflow.order_processing.pending_to_processing',
            'workflow.order_processing.processing_to_shipped',
            'workflow.order_processing.shipped_to_delivered',
            'workflow.order_processing.pending_to_cancelled',
            'workflow.order_processing.processing_to_cancelled',
        ]);

        // Customer Service
        $customerService = Role::findByName('customer_service');
        $customerService->givePermissionTo([
            'workflow.order_processing.view',
            'workflow.order_processing.pending_to_cancelled',
            'workflow.order_processing.processing_to_cancelled',
            'workflow.order_processing.history',
        ]);
    }
}

// 6. Permission-Based Workflow Middleware
class WorkflowPermissionMiddleware
{
    public function handle($request, \Closure $next, string $permission = null)
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Check specific permission if provided
        if ($permission && !$user->can($permission)) {
            return response()->json(['error' => 'Insufficient permissions'], 403);
        }

        // Check workflow-specific permissions for route parameters
        if ($request->route('workflow') && $request->route('transition')) {
            $workflowName = $request->route('workflow');
            $transition = $request->route('transition');
            
            if (!$user->can("workflow.{$workflowName}.{$transition}")) {
                return response()->json(['error' => 'Insufficient workflow permissions'], 403);
            }
        }

        return $next($request);
    }
}

// 7. Event Listener for Permission-Based Actions
class PermissionWorkflowListener
{
    public function handle(WorkflowTransitioned $event): void
    {
        $subject = $event->getSubject();
        $toState = $event->getToState();
        $context = $event->getContext();
        $user = $context['user'] ?? auth()->user();

        // Log permission usage
        if ($user) {
            activity()
                ->causedBy($user)
                ->performedOn($subject)
                ->withProperties([
                    'workflow_name' => $event->getWorkflowName(),
                    'from_state' => $event->getFromState(),
                    'to_state' => $toState,
                    'user_roles' => $user->getRoleNames()->toArray(),
                    'permissions_used' => $this->getPermissionsUsed($subject, $event->getFromState(), $toState),
                ])
                ->log('Workflow transition with permissions');
        }

        // Auto-assign roles based on state
        $this->autoAssignRoles($subject, $toState, $user);
    }

    private function getPermissionsUsed($subject, string $fromState, string $toState): array
    {
        $subjectType = strtolower(class_basename($subject));
        return [
            "workflow.{$subjectType}.{$fromState}_to_{$toState}",
            "workflow.{$subjectType}.state.{$toState}.view",
        ];
    }

    private function autoAssignRoles($subject, string $toState, $user): void
    {
        // Auto-assign roles based on workflow state
        if ($subject instanceof OrderWithPermissions && $toState === 'processing') {
            // Assign order processor role if not already assigned
            if ($user && !$user->hasRole('order_processor')) {
                $user->assignRole('order_processor');
            }
        }
    }
}

// 8. Permission Policy for Workflow Models
class WorkflowModelPolicy
{
    public function view($user, $model): bool
    {
        $modelType = strtolower(class_basename($model));
        
        // Check direct permission
        if ($user->can("workflow.{$modelType}.view")) {
            return true;
        }

        // Check state-specific permission
        $currentState = $model->getCurrentState()?->getName();
        if ($currentState && $user->can("workflow.{$modelType}.state.{$currentState}.view")) {
            return true;
        }

        // Check ownership
        if (method_exists($model, 'isOwnedBy') && $model->isOwnedBy($user)) {
            return true;
        }

        return false;
    }

    public function update($user, $model): bool
    {
        $modelType = strtolower(class_basename($model));
        
        // Check direct permission
        if ($user->can("workflow.{$modelType}.manage")) {
            return true;
        }

        // Check state-specific permission
        $currentState = $model->getCurrentState()?->getName();
        if ($currentState && $user->can("workflow.{$modelType}.state.{$currentState}.edit")) {
            return true;
        }

        return false;
    }

    public function transition($user, $model, string $toState): bool
    {
        return $model->canTransitionTo($toState, $user);
    }
}

// 9. Service Provider for Permission Integration
class PermissionIntegrationServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PermissionWorkflowGuard::class);
        $this->app->singleton(WorkflowPermissionCreator::class);
        $this->app->singleton(RoleBasedWorkflowManager::class);
    }

    public function boot(): void
    {
        // Register event listener
        Event::listen(
            WorkflowTransitioned::class,
            PermissionWorkflowListener::class
        );

        // Register gates
        $this->registerGates();

        // Register policies
        $this->registerPolicies();
    }

    private function registerGates(): void
    {
        Gate::define('workflow.transition', function ($user, $subject, $toState) {
            return $subject->canTransitionTo($toState, $user);
        });

        Gate::define('workflow.manage', function ($user, $workflowName) {
            return $user->can("workflow.{$workflowName}.manage");
        });
    }

    private function registerPolicies(): void
    {
        Gate::policy(OrderWithPermissions::class, WorkflowModelPolicy::class);
        Gate::policy(User::class, WorkflowModelPolicy::class);
    }
}

// 10. Usage Examples
class PermissionUsageExamples
{
    public function __construct(
        private WorkflowPermissionCreator $permissionCreator,
        private RoleBasedWorkflowManager $roleManager
    ) {}

    public function setupOrderWorkflowPermissions(): void
    {
        $states = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
        $transitions = [
            ['from' => 'pending', 'to' => 'processing'],
            ['from' => 'processing', 'to' => 'shipped'],
            ['from' => 'shipped', 'to' => 'delivered'],
            ['from' => 'pending', 'to' => 'cancelled'],
            ['from' => 'processing', 'to' => 'cancelled'],
        ];

        $this->permissionCreator->createWorkflowPermissions('order_processing', $states, $transitions);
        $this->roleManager->assignWorkflowRoles();
    }

    public function checkUserPermissions($user, $order): array
    {
        return [
            'can_view' => Gate::allows('view', $order),
            'can_update' => Gate::allows('update', $order),
            'can_transition_to_processing' => Gate::allows('workflow.transition', [$order, 'processing']),
            'can_transition_to_shipped' => Gate::allows('workflow.transition', [$order, 'shipped']),
            'can_cancel' => Gate::allows('workflow.transition', [$order, 'cancelled']),
        ];
    }

    public function assignUserToWorkflowRole($user, string $roleName): void
    {
        if (Role::where('name', $roleName)->exists()) {
            $user->assignRole($roleName);
        }
    }
}
