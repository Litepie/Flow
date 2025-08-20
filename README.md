# Litepie Flow

[![Latest Version on Packagist](https://img.shields.io/packagist/v/litepie/flow.svg?style=flat-square)](https://packagist.org/packages/litepie/flow)
[![Total Downloads](https://img.shields.io/packagist/dt/litepie/flow.svg?style=flat-square)](https://packagist.org/packages/litepie/flow)
[![License](https://img.shields.io/packagist/l/litepie/flow.svg?style=flat-square)](https://packagist.org/packages/litepie/flow)
[![Laravel](https://img.shields.io/badge/Laravel-8.x|9.x|10.x|11.x-orange.svg?style=flat-square)](https://laravel.com)

A powerful Laravel package for workflow management with states and transitions. Litepie Flow provides a comprehensive solution for building complex business workflows with state management and event-driven transitions, seamlessly integrated with the [Litepie Actions](https://github.com/litepie/actions) package.

## Features

- 🔄 **Workflow Management**: Define complex workflows with states and transitions
- 📊 **State Management**: Track and manage entity states throughout their lifecycle
- ⚡ **Event System**: Built-in event handling for workflow transitions
- 🎯 **Action Integration**: Seamless integration with the [Litepie Actions](https://github.com/litepie/actions) package
- 📝 **Database Logging**: Track workflow executions and transition history
- 🚀 **Laravel Integration**: Seamless integration with Laravel's ecosystem
- 🔧 **Extensible**: Easy to extend and customize for your specific needs

## Installation

Install the package via Composer:

```bash
composer require litepie/flow
```

> **Note**: This will automatically install the required `litepie/actions` dependency.

### Publish and Run Migrations

Publish and run the migrations:

```bash
php artisan vendor:publish --tag="flow-migrations"
php artisan migrate
```

### Configuration (Optional)

Optionally, publish the configuration file:

```bash
php artisan vendor:publish --tag="flow-config"
```

## Quick Start

### 1. Create an Action

First, create an action that will be executed during workflow transitions:

```php
<?php

namespace App\Actions;

use Litepie\Actions\BaseAction;
use Litepie\Actions\Traits\ValidatesInput;
use Litepie\Actions\Contracts\ActionResult;

class ProcessPaymentAction extends BaseAction
{
    use ValidatesInput;

    protected string $name = 'process_payment';

    public function execute(array $context = []): ActionResult
    {
        $validated = $this->validateContext($context);
        
        // Your payment processing logic here
        $result = $this->processPayment($validated);
        
        return $result['success']
            ? $this->success($result, 'Payment processed successfully')
            : $this->failure($result['errors'], 'Payment processing failed');
    }

    protected function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string',
            'order_id' => 'required|integer|exists:orders,id'
        ];
    }

    private function processPayment(array $data): array
    {
        // Implement your payment logic
        return ['success' => true, 'transaction_id' => '12345'];
    }
}
```

### 2. Define a Workflow

Create a workflow class that defines your business process:

```php
<?php

namespace App\Workflows;

use Litepie\Flow\Workflows\Workflow;
use Litepie\Flow\States\State;
use Litepie\Flow\Transitions\Transition;

class OrderWorkflow
{
    public static function create(): Workflow
    {
        $workflow = new Workflow('order_processing', 'Order Processing Workflow');

        // Define states
        $pending = new State('pending', 'Pending', true);        // Initial state
        $processing = new State('processing', 'Processing');
        $shipped = new State('shipped', 'Shipped');
        $delivered = new State('delivered', 'Delivered', false, true); // Final state

        // Add states to workflow
        $workflow->addState($pending)
                 ->addState($processing)
                 ->addState($shipped)
                 ->addState($delivered);

        // Define transitions with actions
        $processTransition = new Transition('pending', 'processing', 'process');
        $processTransition->addAction(new \App\Actions\ProcessPaymentAction());

        $shipTransition = new Transition('processing', 'shipped', 'ship');
        $deliverTransition = new Transition('shipped', 'delivered', 'deliver');

        // Add transitions to workflow
        $workflow->addTransition($processTransition)
                 ->addTransition($shipTransition)
                 ->addTransition($deliverTransition);

        return $workflow;
    }
}
```

### 3. Make Your Model Workflowable

Implement the workflow interface in your Eloquent model:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Litepie\Flow\Traits\HasWorkflow;
use Litepie\Flow\Contracts\Workflowable;

class Order extends Model implements Workflowable
{
    use HasWorkflow;

    protected $fillable = ['customer_id', 'total', 'state'];

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

### 4. Register Your Workflow

Register the workflow in a service provider:

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Litepie\Flow\Facades\Flow;
use App\Workflows\OrderWorkflow;

class WorkflowServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Flow::register('order_processing', OrderWorkflow::create());
    }
}
```

Don't forget to register your service provider in `config/app.php`:

```php
'providers' => [
    // Other providers...
    App\Providers\WorkflowServiceProvider::class,
],
```

## Usage Examples

### Basic Workflow Operations

```php
// Create a new order
$order = Order::create([
    'customer_id' => 1,
    'total' => 99.99,
    'state' => 'pending'
]);

// Check available transitions
$transitions = $order->getAvailableTransitions();

// Check if a specific transition is possible
if ($order->canTransitionTo('processing')) {
    // Transition to the next state with context data
    $order->transitionTo('processing', [
        'amount' => $order->total,
        'payment_method' => 'credit_card',
        'order_id' => $order->id
    ]);
}

// Get current state
$currentState = $order->getCurrentState();
echo $currentState->getLabel(); // "Processing"

// Get workflow history
$history = $order->getWorkflowHistory();
```

### Advanced Usage

```php
// Listen to workflow events
Event::listen('workflow.order_processing.transition.process', function ($event) {
    Log::info('Order transitioned to processing', [
        'order_id' => $event->getSubject()->id,
        'from' => $event->getFromState(),
        'to' => $event->getToState()
    ]);
});

// Conditional transitions
if ($order->getCurrentState()->getName() === 'pending' && $order->total > 100) {
    $order->transitionTo('processing', ['expedite' => true]);
}

// Bulk state updates
Order::whereIn('id', [1, 2, 3])
     ->each(function ($order) {
         if ($order->canTransitionTo('shipped')) {
             $order->transitionTo('shipped');
         }
     });
```

## Configuration

After publishing the config file, you can customize various aspects of the workflow system:

```php
return [
    // Database table names
    'tables' => [
        'workflows' => 'workflows',
        'workflow_states' => 'workflow_states',
        'workflow_transitions' => 'workflow_transitions',
        'workflow_logs' => 'workflow_logs',
    ],

    // Event handling
    'events' => [
        'enabled' => true,
        'prefix' => 'workflow',
    ],

    // Logging configuration
    'logging' => [
        'enabled' => true,
        'channel' => env('WORKFLOW_LOG_CHANNEL', 'default'),
    ],

    // Action configuration
    'actions' => [
        'namespace' => 'App\\Actions',
        'timeout' => 300, // seconds
    ],
];
```

## Architecture

### Core Components

1. **Workflow**: Defines the entire business process
2. **States**: Represent different stages in the workflow
3. **Transitions**: Define how to move between states
4. **Actions**: Execute business logic during transitions
5. **Events**: Handle workflow lifecycle events

### State Management

States can be:
- **Initial**: Starting point of the workflow
- **Final**: End point of the workflow
- **Intermediate**: States between initial and final

### Action Integration

Actions are powered by the [Litepie Actions](https://github.com/litepie/actions) package and provide:
- Input validation
- Result handling
- Error management
- Retry mechanisms

## Events

The package dispatches several events during workflow execution:

- `workflow.{name}.guard.{transition}` - Before transition validation
- `workflow.{name}.leave.{state}` - When leaving a state
- `workflow.{name}.transition.{transition}` - During transition
- `workflow.{name}.enter.{state}` - When entering a state
- `workflow.{name}.entered.{state}` - After entering a state

### Event Listeners

```php
// In EventServiceProvider
protected $listen = [
    'workflow.order_processing.enter.processing' => [
        NotifyCustomerListener::class,
    ],
    'workflow.order_processing.transition.ship' => [
        GenerateTrackingNumberListener::class,
    ],
];
```

## Testing

Run the tests with:

```bash
composer test
```

## Dependencies

This package depends on:
- [litepie/actions](https://github.com/litepie/actions) - For action pattern implementation
- Laravel 8.x|9.x|10.x|11.x
- PHP 8.0+

## Documentation

For more detailed documentation, please refer to:
- [Workflows](docs/workflows.md) - Workflow management guide
- [States & Transitions](docs/states-transitions.md) - State and transition documentation
- [Actions](docs/actions.md) - Action development guide
- [Events](docs/events.md) - Event system documentation
- [Integration](docs/integration.md) - Integration patterns and examples

## Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details on how to contribute to this project.

### Development Setup

1. Clone the repository
2. Install dependencies: `composer install`
3. Run tests: `composer test`
4. Check code style: `composer cs-check`
5. Fix code style: `composer cs-fix`

## Security

If you discover any security-related issues, please email the maintainers instead of using the issue tracker.

## Changelog

Please see [CHANGELOG.md](CHANGELOG.md) for more information about what has changed recently.

## License

The MIT License (MIT). Please see [LICENSE.md](LICENSE.md) for more information.

## Credits

- [Litepie Team](https://github.com/Litepie)
- [All Contributors](../../contributors)

## Support

If you find this package useful, please consider:
- ⭐ Starring the repository
- 🐛 Reporting bugs
- 💡 Suggesting new features
- 🔄 Contributing code improvements

---

**Litepie Flow** - Making workflow management in Laravel simple and powerful.