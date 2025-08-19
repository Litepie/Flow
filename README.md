# Laravel Flow

[![Latest Version on Packagist](https://img.shields.io/packagist/v/litepie/flow.svg?style=flat-square)](https://packagist.org/packages/litepie/flow)
[![Total Downloads](https://img.shields.io/packagist/dt/litepie/flow.svg?style=flat-square)](https://packagist.org/packages/litepie/flow)

A powerful Laravel package for workflow management with states and transitions. Laravel Flow provides a comprehensive solution for building complex business workflows with state management and event-driven transitions.

## Features

- **Workflow Management**: Define complex workflows with states and transitions
- **State Management**: Track and manage entity states throughout their lifecycle
- **Event System**: Built-in event handling for workflow transitions
- **Action Integration**: Seamless integration with the [Litepie Actions](https://github.com/litepie/actions) package
- **Database Logging**: Track workflow executions and transition history
- **Laravel Integration**: Seamless integration with Laravel's ecosystem

## Installation

Install the package via Composer:

```bash
composer require litepie/flow
```

This will automatically install the required `litepie/actions` dependency.

Publish and run the migrations:

```bash
php artisan vendor:publish --tag="flow-migrations"
php artisan migrate
```

Optionally, publish the configuration file:

```bash
php artisan vendor:publish --tag="flow-config"
```

## Quick Start

### 1. Create Actions (using litepie/actions)

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

### 2. Create a Workflow

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
        $pending = new State('pending', 'Pending', true);
        $processing = new State('processing', 'Processing');
        $shipped = new State('shipped', 'Shipped');
        $delivered = new State('delivered', 'Delivered', false, true);

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

### 3. Use the HasWorkflow Trait

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

### 5. Use the Workflow

```php
// Create a new order
$order = Order::create([
    'customer_id' => 1,
    'total' => 99.99,
    'state' => 'pending'
]);

// Check available transitions
$transitions = $order->getAvailableTransitions();

// Transition to the next state
if ($order->canTransitionTo('processing')) {
    $order->transitionTo('processing', [
        'amount' => $order->total,
        'payment_method' => 'credit_card',
        'order_id' => $order->id
    ]);
}

// Get current state
$currentState = $order->getCurrentState();
echo $currentState->getLabel(); // "Processing"
```

## Dependencies

This package depends on:
- **[litepie/actions](https://github.com/litepie/actions)** - For action pattern implementation

## Documentation

- [Workflows](docs/workflows.md) - Workflow management guide
- [Integration](docs/integration.md) - Integration patterns and examples

## Testing

```bash
composer test
```

## Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## License

The MIT License (MIT). Please see [LICENSE.md](LICENSE.md) for more information.
