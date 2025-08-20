# Actions

This guide covers the action system in Litepie Flow, which integrates with the [Litepie Actions](https://github.com/litepie/actions) package to provide powerful, reusable business logic components.

## Table of Contents

- [Overview](#overview)
- [Creating Actions](#creating-actions)
- [Action Types](#action-types)
- [Input Validation](#input-validation)
- [Result Handling](#result-handling)
- [Error Management](#error-management)
- [Testing Actions](#testing-actions)
- [Advanced Patterns](#advanced-patterns)
- [Examples](#examples)

## Overview

Actions in Litepie Flow are self-contained units of business logic that are executed during workflow transitions. They are built on top of the Litepie Actions package, providing:

- **Input Validation**: Automatic validation of input data
- **Result Handling**: Consistent success/failure result patterns
- **Error Management**: Built-in error handling and retry mechanisms
- **Testability**: Easy unit testing with clear interfaces
- **Reusability**: Actions can be shared across multiple workflows

## Creating Actions

### Basic Action Structure

```php
<?php

namespace App\Actions;

use Litepie\Actions\BaseAction;
use Litepie\Actions\Traits\ValidatesInput;
use Litepie\Actions\Contracts\ActionResult;

class SendWelcomeEmailAction extends BaseAction
{
    use ValidatesInput;

    protected string $name = 'send_welcome_email';

    public function execute(array $context = []): ActionResult
    {
        $validated = $this->validateContext($context);
        
        try {
            $user = $validated['user'];
            
            Mail::to($user->email)->send(new WelcomeEmail($user));
            
            return $this->success([
                'email_sent_to' => $user->email,
                'sent_at' => now()
            ], 'Welcome email sent successfully');
            
        } catch (\Exception $e) {
            return $this->failure(
                ['error' => $e->getMessage()],
                'Failed to send welcome email'
            );
        }
    }

    protected function rules(): array
    {
        return [
            'user' => 'required',
            'user.email' => 'required|email',
            'user.name' => 'required|string'
        ];
    }
}
```

### Action with Dependencies

```php
<?php

namespace App\Actions;

use Litepie\Actions\BaseAction;
use Litepie\Actions\Contracts\ActionResult;
use App\Services\PaymentService;
use App\Services\InventoryService;

class ProcessOrderAction extends BaseAction
{
    public function __construct(
        private PaymentService $paymentService,
        private InventoryService $inventoryService
    ) {}

    public function execute(array $context = []): ActionResult
    {
        $order = $context['order'];
        
        // Process payment
        $paymentResult = $this->paymentService->charge(
            $order->payment_method,
            $order->total
        );
        
        if (!$paymentResult->isSuccessful()) {
            return $this->failure(
                ['payment_error' => $paymentResult->getError()],
                'Payment processing failed'
            );
        }
        
        // Update inventory
        $inventoryResult = $this->inventoryService->reserve($order->items);
        
        if (!$inventoryResult->isSuccessful()) {
            // Rollback payment
            $this->paymentService->refund($paymentResult->getTransactionId());
            
            return $this->failure(
                ['inventory_error' => $inventoryResult->getError()],
                'Insufficient inventory'
            );
        }
        
        return $this->success([
            'transaction_id' => $paymentResult->getTransactionId(),
            'reservation_id' => $inventoryResult->getReservationId()
        ], 'Order processed successfully');
    }
}
```

## Action Types

### Notification Actions

Handle various types of notifications:

```php
class NotificationAction extends BaseAction
{
    use ValidatesInput;

    public function execute(array $context = []): ActionResult
    {
        $validated = $this->validateContext($context);
        
        $channels = $validated['channels'] ?? ['email'];
        $results = [];
        
        foreach ($channels as $channel) {
            $result = $this->sendNotification($channel, $validated);
            $results[$channel] = $result;
        }
        
        return $this->success($results, 'Notifications sent');
    }

    private function sendNotification(string $channel, array $data): array
    {
        return match($channel) {
            'email' => $this->sendEmail($data),
            'sms' => $this->sendSms($data),
            'slack' => $this->sendSlack($data),
            'push' => $this->sendPushNotification($data),
            default => ['status' => 'unsupported', 'channel' => $channel]
        };
    }

    protected function rules(): array
    {
        return [
            'recipient' => 'required',
            'message' => 'required|string',
            'channels' => 'array',
            'channels.*' => 'in:email,sms,slack,push'
        ];
    }
}
```

### Data Processing Actions

Handle data transformation and processing:

```php
class ProcessDataAction extends BaseAction
{
    use ValidatesInput;

    public function execute(array $context = []): ActionResult
    {
        $validated = $this->validateContext($context);
        
        $rawData = $validated['data'];
        $processor = $validated['processor'] ?? 'default';
        
        $processedData = $this->processData($rawData, $processor);
        
        if (empty($processedData)) {
            return $this->failure(
                ['raw_data_count' => count($rawData)],
                'No data could be processed'
            );
        }
        
        return $this->success([
            'processed_count' => count($processedData),
            'skipped_count' => count($rawData) - count($processedData),
            'data' => $processedData
        ], 'Data processed successfully');
    }

    private function processData(array $data, string $processor): array
    {
        return match($processor) {
            'normalize' => $this->normalizeData($data),
            'aggregate' => $this->aggregateData($data),
            'filter' => $this->filterData($data),
            'transform' => $this->transformData($data),
            default => $this->defaultProcessing($data)
        };
    }

    protected function rules(): array
    {
        return [
            'data' => 'required|array',
            'processor' => 'string|in:normalize,aggregate,filter,transform'
        ];
    }
}
```

### Integration Actions

Handle external service integrations:

```php
class SyncWithExternalServiceAction extends BaseAction
{
    use ValidatesInput;

    public function __construct(
        private ExternalApiClient $apiClient
    ) {}

    public function execute(array $context = []): ActionResult
    {
        $validated = $this->validateContext($context);
        
        $entity = $validated['entity'];
        $endpoint = $validated['endpoint'];
        
        try {
            $response = $this->apiClient->sync($endpoint, $entity->toArray());
            
            if ($response->isSuccessful()) {
                $entity->update([
                    'external_id' => $response->getData('id'),
                    'synced_at' => now(),
                    'sync_status' => 'success'
                ]);
                
                return $this->success([
                    'external_id' => $response->getData('id'),
                    'synced_at' => now()
                ], 'Successfully synced with external service');
            }
            
            return $this->failure([
                'api_error' => $response->getError(),
                'status_code' => $response->getStatusCode()
            ], 'External service sync failed');
            
        } catch (\Exception $e) {
            return $this->failure([
                'exception' => $e->getMessage(),
                'endpoint' => $endpoint
            ], 'Sync operation failed');
        }
    }

    protected function rules(): array
    {
        return [
            'entity' => 'required',
            'endpoint' => 'required|string',
            'retry_count' => 'integer|min:0|max:3'
        ];
    }
}
```

## Input Validation

### Custom Validation Rules

```php
class ValidateDocumentAction extends BaseAction
{
    use ValidatesInput;

    public function execute(array $context = []): ActionResult
    {
        $validated = $this->validateContext($context);
        
        $document = $validated['document'];
        $validationResults = [];
        
        // Content validation
        $validationResults['content'] = $this->validateContent($document);
        
        // Format validation
        $validationResults['format'] = $this->validateFormat($document);
        
        // Business rules validation
        $validationResults['business_rules'] = $this->validateBusinessRules($document);
        
        $hasErrors = collect($validationResults)
            ->pluck('has_errors')
            ->contains(true);
        
        if ($hasErrors) {
            return $this->failure($validationResults, 'Document validation failed');
        }
        
        return $this->success($validationResults, 'Document validation passed');
    }

    protected function rules(): array
    {
        return [
            'document' => 'required',
            'document.title' => 'required|string|min:5|max:255',
            'document.content' => 'required|string|min:100',
            'document.author_id' => 'required|exists:users,id',
            'document.category_id' => 'required|exists:categories,id',
            'validation_level' => 'string|in:basic,standard,strict'
        ];
    }

    protected function messages(): array
    {
        return [
            'document.title.min' => 'Document title must be at least 5 characters',
            'document.content.min' => 'Document content must be at least 100 characters',
        ];
    }

    // Custom validation method
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $document = $this->getValidatedData()['document'];
            
            // Custom word count validation
            $wordCount = str_word_count(strip_tags($document->content));
            if ($wordCount < 50) {
                $validator->errors()->add(
                    'document.content',
                    'Content must contain at least 50 words'
                );
            }
            
            // Custom uniqueness validation
            if ($this->titleExists($document->title, $document->id)) {
                $validator->errors()->add(
                    'document.title',
                    'A document with this title already exists'
                );
            }
        });
    }
}
```

### Conditional Validation

```php
class ConditionalValidationAction extends BaseAction
{
    use ValidatesInput;

    protected function rules(): array
    {
        $context = $this->getContext();
        $orderType = $context['order_type'] ?? 'standard';
        
        $baseRules = [
            'customer_id' => 'required|exists:customers,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1'
        ];
        
        // Add conditional rules based on order type
        return match($orderType) {
            'express' => array_merge($baseRules, [
                'delivery_date' => 'required|date|after:today',
                'express_fee' => 'required|numeric|min:0'
            ]),
            'subscription' => array_merge($baseRules, [
                'billing_cycle' => 'required|in:monthly,quarterly,yearly',
                'start_date' => 'required|date',
                'auto_renew' => 'boolean'
            ]),
            'bulk' => array_merge($baseRules, [
                'items.*.quantity' => 'required|integer|min:10',
                'discount_code' => 'string|exists:discount_codes,code'
            ]),
            default => $baseRules
        };
    }
}
```

## Result Handling

### Success Results

```php
class CreateUserAction extends BaseAction
{
    public function execute(array $context = []): ActionResult
    {
        $userData = $context['user_data'];
        
        $user = User::create($userData);
        
        // Simple success
        return $this->success(['user_id' => $user->id]);
        
        // Success with message
        return $this->success(
            ['user_id' => $user->id, 'email' => $user->email],
            'User created successfully'
        );
        
        // Success with metadata
        return $this->success(
            ['user_id' => $user->id],
            'User created successfully',
            ['created_at' => now(), 'ip_address' => request()->ip()]
        );
    }
}
```

### Failure Results

```php
class ProcessPaymentAction extends BaseAction
{
    public function execute(array $context = []): ActionResult
    {
        try {
            $result = $this->processPayment($context);
            return $this->success($result);
            
        } catch (InsufficientFundsException $e) {
            return $this->failure(
                ['available_balance' => $e->getAvailableBalance()],
                'Insufficient funds for transaction',
                ['error_code' => 'INSUFFICIENT_FUNDS']
            );
            
        } catch (PaymentGatewayException $e) {
            return $this->failure(
                ['gateway_error' => $e->getGatewayMessage()],
                'Payment gateway error',
                ['retry_allowed' => true, 'error_code' => 'GATEWAY_ERROR']
            );
            
        } catch (\Exception $e) {
            return $this->failure(
                ['exception' => $e->getMessage()],
                'Unexpected error during payment processing',
                ['retry_allowed' => false, 'error_code' => 'UNKNOWN_ERROR']
            );
        }
    }
}
```

### Custom Result Types

```php
use Litepie\Actions\Results\ActionResult;

class CustomActionResult extends ActionResult
{
    public function __construct(
        private string $status,
        private array $data = [],
        private string $message = '',
        private array $metadata = []
    ) {
        parent::__construct($this->isSuccessStatus($status), $data, $message, $metadata);
    }

    private function isSuccessStatus(string $status): bool
    {
        return in_array($status, ['success', 'completed', 'processed']);
    }

    public function getStatus(): string
    {
        return $this->status;
    }
}

class StatusBasedAction extends BaseAction
{
    public function execute(array $context = []): ActionResult
    {
        $result = $this->performOperation($context);
        
        return new CustomActionResult(
            status: $result['status'],
            data: $result['data'],
            message: $result['message'],
            metadata: ['operation_time' => $result['duration']]
        );
    }
}
```

## Error Management

### Retry Mechanisms

```php
class RetryableAction extends BaseAction
{
    use ValidatesInput;

    private int $maxRetries = 3;
    private int $retryDelay = 1000; // milliseconds

    public function execute(array $context = []): ActionResult
    {
        $validated = $this->validateContext($context);
        $attempt = $context['attempt'] ?? 1;
        
        try {
            $result = $this->performOperation($validated);
            return $this->success($result, 'Operation completed successfully');
            
        } catch (RetryableException $e) {
            if ($attempt < $this->maxRetries) {
                // Schedule retry
                $this->scheduleRetry($context, $attempt + 1);
                
                return $this->failure([
                    'error' => $e->getMessage(),
                    'attempt' => $attempt,
                    'next_retry_at' => now()->addMilliseconds($this->retryDelay * $attempt)
                ], 'Operation failed, retry scheduled');
            }
            
            return $this->failure([
                'error' => $e->getMessage(),
                'attempts' => $attempt,
                'max_retries_exceeded' => true
            ], 'Operation failed after maximum retries');
            
        } catch (NonRetryableException $e) {
            return $this->failure([
                'error' => $e->getMessage(),
                'retryable' => false
            ], 'Operation failed with non-retryable error');
        }
    }

    private function scheduleRetry(array $context, int $attempt): void
    {
        RetryActionJob::dispatch($this, $context, $attempt)
            ->delay(now()->addMilliseconds($this->retryDelay * $attempt));
    }

    protected function rules(): array
    {
        return [
            'operation_id' => 'required|string',
            'data' => 'required|array',
            'attempt' => 'integer|min:1|max:' . $this->maxRetries
        ];
    }
}
```

### Circuit Breaker Pattern

```php
use Illuminate\Support\Facades\Cache;

class CircuitBreakerAction extends BaseAction
{
    private string $circuitKey;
    private int $failureThreshold = 5;
    private int $timeWindow = 300; // 5 minutes

    public function __construct()
    {
        $this->circuitKey = 'circuit_breaker_' . static::class;
    }

    public function execute(array $context = []): ActionResult
    {
        if ($this->isCircuitOpen()) {
            return $this->failure([
                'circuit_breaker' => 'open',
                'retry_after' => $this->getRetryAfter()
            ], 'Service temporarily unavailable');
        }

        try {
            $result = $this->performOperation($context);
            $this->recordSuccess();
            return $this->success($result);
            
        } catch (\Exception $e) {
            $this->recordFailure();
            
            if ($this->shouldOpenCircuit()) {
                $this->openCircuit();
            }
            
            return $this->failure([
                'error' => $e->getMessage(),
                'failure_count' => $this->getFailureCount()
            ], 'Operation failed');
        }
    }

    private function isCircuitOpen(): bool
    {
        return Cache::has($this->circuitKey . '_open');
    }

    private function shouldOpenCircuit(): bool
    {
        return $this->getFailureCount() >= $this->failureThreshold;
    }

    private function openCircuit(): void
    {
        Cache::put($this->circuitKey . '_open', true, $this->timeWindow);
        Cache::forget($this->circuitKey . '_failures');
    }

    private function recordFailure(): void
    {
        $failures = Cache::get($this->circuitKey . '_failures', []);
        $failures[] = now()->timestamp;
        
        // Keep only failures within the time window
        $cutoff = now()->subSeconds($this->timeWindow)->timestamp;
        $failures = array_filter($failures, fn($time) => $time > $cutoff);
        
        Cache::put($this->circuitKey . '_failures', $failures, $this->timeWindow);
    }

    private function recordSuccess(): void
    {
        Cache::forget($this->circuitKey . '_failures');
        Cache::forget($this->circuitKey . '_open');
    }

    private function getFailureCount(): int
    {
        return count(Cache::get($this->circuitKey . '_failures', []));
    }
}
```

## Testing Actions

### Unit Testing

```php
<?php

namespace Tests\Unit\Actions;

use Tests\TestCase;
use App\Actions\ProcessOrderAction;
use App\Models\Order;
use App\Services\PaymentService;
use App\Services\InventoryService;
use Mockery;

class ProcessOrderActionTest extends TestCase
{
    public function test_successful_order_processing()
    {
        // Arrange
        $paymentService = Mockery::mock(PaymentService::class);
        $inventoryService = Mockery::mock(InventoryService::class);
        
        $order = Order::factory()->create();
        
        $paymentService->shouldReceive('charge')
            ->once()
            ->with($order->payment_method, $order->total)
            ->andReturn(new SuccessfulPaymentResult('txn_123'));
        
        $inventoryService->shouldReceive('reserve')
            ->once()
            ->with($order->items)
            ->andReturn(new SuccessfulInventoryResult('res_456'));
        
        $action = new ProcessOrderAction($paymentService, $inventoryService);
        
        // Act
        $result = $action->execute(['order' => $order]);
        
        // Assert
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals('txn_123', $result->getData()['transaction_id']);
        $this->assertEquals('res_456', $result->getData()['reservation_id']);
    }

    public function test_payment_failure_handling()
    {
        // Arrange
        $paymentService = Mockery::mock(PaymentService::class);
        $inventoryService = Mockery::mock(InventoryService::class);
        
        $order = Order::factory()->create();
        
        $paymentService->shouldReceive('charge')
            ->once()
            ->andReturn(new FailedPaymentResult('Insufficient funds'));
        
        $inventoryService->shouldNotReceive('reserve');
        
        $action = new ProcessOrderAction($paymentService, $inventoryService);
        
        // Act
        $result = $action->execute(['order' => $order]);
        
        // Assert
        $this->assertFalse($result->isSuccessful());
        $this->assertEquals('Payment processing failed', $result->getMessage());
        $this->assertArrayHasKey('payment_error', $result->getData());
    }

    public function test_inventory_failure_with_payment_rollback()
    {
        // Arrange
        $paymentService = Mockery::mock(PaymentService::class);
        $inventoryService = Mockery::mock(InventoryService::class);
        
        $order = Order::factory()->create();
        
        $paymentService->shouldReceive('charge')
            ->once()
            ->andReturn(new SuccessfulPaymentResult('txn_123'));
        
        $inventoryService->shouldReceive('reserve')
            ->once()
            ->andReturn(new FailedInventoryResult('Out of stock'));
        
        $paymentService->shouldReceive('refund')
            ->once()
            ->with('txn_123');
        
        $action = new ProcessOrderAction($paymentService, $inventoryService);
        
        // Act
        $result = $action->execute(['order' => $order]);
        
        // Assert
        $this->assertFalse($result->isSuccessful());
        $this->assertEquals('Insufficient inventory', $result->getMessage());
    }
}
```

### Integration Testing

```php
<?php

namespace Tests\Feature\Actions;

use Tests\TestCase;
use App\Actions\SendWelcomeEmailAction;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use App\Mail\WelcomeEmail;

class SendWelcomeEmailActionTest extends TestCase
{
    public function test_welcome_email_is_sent_successfully()
    {
        // Arrange
        Mail::fake();
        $user = User::factory()->create();
        $action = new SendWelcomeEmailAction();
        
        // Act
        $result = $action->execute(['user' => $user]);
        
        // Assert
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals('Welcome email sent successfully', $result->getMessage());
        
        Mail::assertSent(WelcomeEmail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    public function test_validation_fails_with_invalid_user()
    {
        // Arrange
        $action = new SendWelcomeEmailAction();
        
        // Act & Assert
        $this->expectException(ValidationException::class);
        $action->execute(['user' => null]);
    }

    public function test_email_failure_is_handled_gracefully()
    {
        // Arrange
        Mail::shouldReceive('to')->andThrow(new \Exception('SMTP server down'));
        
        $user = User::factory()->create();
        $action = new SendWelcomeEmailAction();
        
        // Act
        $result = $action->execute(['user' => $user]);
        
        // Assert
        $this->assertFalse($result->isSuccessful());
        $this->assertEquals('Failed to send welcome email', $result->getMessage());
        $this->assertArrayHasKey('error', $result->getData());
    }
}
```

## Advanced Patterns

### Action Composition

```php
class CompositeOrderProcessingAction extends BaseAction
{
    public function __construct(
        private ValidateOrderAction $validateOrder,
        private ProcessPaymentAction $processPayment,
        private ReserveInventoryAction $reserveInventory,
        private SendConfirmationAction $sendConfirmation
    ) {}

    public function execute(array $context = []): ActionResult
    {
        $order = $context['order'];
        $results = [];
        
        // Step 1: Validate order
        $validationResult = $this->validateOrder->execute($context);
        if (!$validationResult->isSuccessful()) {
            return $validationResult;
        }
        $results['validation'] = $validationResult->getData();
        
        // Step 2: Process payment
        $paymentResult = $this->processPayment->execute($context);
        if (!$paymentResult->isSuccessful()) {
            return $paymentResult;
        }
        $results['payment'] = $paymentResult->getData();
        
        // Step 3: Reserve inventory
        $inventoryContext = array_merge($context, [
            'transaction_id' => $paymentResult->getData()['transaction_id']
        ]);
        
        $inventoryResult = $this->reserveInventory->execute($inventoryContext);
        if (!$inventoryResult->isSuccessful()) {
            // Rollback payment
            $this->rollbackPayment($paymentResult->getData()['transaction_id']);
            return $inventoryResult;
        }
        $results['inventory'] = $inventoryResult->getData();
        
        // Step 4: Send confirmation
        $confirmationContext = array_merge($context, [
            'transaction_id' => $paymentResult->getData()['transaction_id'],
            'reservation_id' => $inventoryResult->getData()['reservation_id']
        ]);
        
        $confirmationResult = $this->sendConfirmation->execute($confirmationContext);
        $results['confirmation'] = $confirmationResult->getData();
        
        return $this->success($results, 'Order processed successfully');
    }

    private function rollbackPayment(string $transactionId): void
    {
        // Implementation for payment rollback
        app(PaymentService::class)->refund($transactionId);
    }
}
```

### Async Action Execution

```php
class AsyncActionExecutor extends BaseAction
{
    public function execute(array $context = []): ActionResult
    {
        $actions = $context['actions'];
        $mode = $context['mode'] ?? 'sequential';
        
        return match($mode) {
            'parallel' => $this->executeParallel($actions, $context),
            'sequential' => $this->executeSequential($actions, $context),
            'batch' => $this->executeBatch($actions, $context),
            default => $this->failure(['mode' => $mode], 'Invalid execution mode')
        };
    }

    private function executeParallel(array $actions, array $context): ActionResult
    {
        $jobs = [];
        $jobId = Str::uuid();
        
        foreach ($actions as $index => $actionClass) {
            $job = new AsyncActionJob($actionClass, $context, $jobId, $index);
            $jobs[] = dispatch($job);
        }
        
        return $this->success([
            'job_id' => $jobId,
            'action_count' => count($actions),
            'status' => 'dispatched'
        ], 'Actions dispatched for parallel execution');
    }

    private function executeSequential(array $actions, array $context): ActionResult
    {
        $results = [];
        $aggregatedData = [];
        
        foreach ($actions as $index => $actionClass) {
            $action = app($actionClass);
            $actionContext = array_merge($context, ['previous_results' => $aggregatedData]);
            
            $result = $action->execute($actionContext);
            $results[$index] = $result->toArray();
            
            if (!$result->isSuccessful()) {
                return $this->failure([
                    'failed_at_step' => $index,
                    'failed_action' => $actionClass,
                    'results' => $results
                ], 'Sequential execution failed');
            }
            
            $aggregatedData = array_merge($aggregatedData, $result->getData());
        }
        
        return $this->success([
            'results' => $results,
            'aggregated_data' => $aggregatedData
        ], 'Sequential execution completed');
    }
}
```

### Conditional Action Execution

```php
class ConditionalActionExecutor extends BaseAction
{
    public function execute(array $context = []): ActionResult
    {
        $conditions = $context['conditions'];
        $entity = $context['entity'];
        
        $executedActions = [];
        $skippedActions = [];
        
        foreach ($conditions as $condition) {
            if ($this->evaluateCondition($condition['condition'], $entity, $context)) {
                $action = app($condition['action']);
                $result = $action->execute($context);
                
                $executedActions[] = [
                    'action' => $condition['action'],
                    'condition' => $condition['condition'],
                    'result' => $result->toArray()
                ];
                
                if (!$result->isSuccessful() && ($condition['required'] ?? false)) {
                    return $this->failure([
                        'executed_actions' => $executedActions,
                        'failed_action' => $condition['action']
                    ], 'Required action failed');
                }
            } else {
                $skippedActions[] = [
                    'action' => $condition['action'],
                    'condition' => $condition['condition'],
                    'reason' => 'condition_not_met'
                ];
            }
        }
        
        return $this->success([
            'executed_actions' => $executedActions,
            'skipped_actions' => $skippedActions
        ], 'Conditional execution completed');
    }

    private function evaluateCondition($condition, $entity, array $context): bool
    {
        if (is_callable($condition)) {
            return $condition($entity, $context);
        }
        
        if (is_string($condition)) {
            return $this->evaluateStringCondition($condition, $entity, $context);
        }
        
        return (bool) $condition;
    }

    private function evaluateStringCondition(string $condition, $entity, array $context): bool
    {
        // Simple expression evaluator
        return match(true) {
            str_contains($condition, 'entity.status == ') => 
                $entity->status === trim(str_replace('entity.status == ', '', $condition), '"\''),
            str_contains($condition, 'entity.amount > ') => 
                $entity->amount > (float) str_replace('entity.amount > ', '', $condition),
            str_contains($condition, 'context.') => 
                $this->evaluateContextCondition($condition, $context),
            default => false
        };
    }
}
```

## Examples

### E-commerce Actions

```php
// Order processing action
class ProcessEcommerceOrderAction extends BaseAction
{
    use ValidatesInput;

    public function execute(array $context = []): ActionResult
    {
        $validated = $this->validateContext($context);
        $order = $validated['order'];
        
        try {
            DB::beginTransaction();
            
            // Calculate totals
            $totals = $this->calculateOrderTotals($order);
            
            // Validate inventory
            $this->validateInventoryAvailability($order->items);
            
            // Process payment
            $paymentResult = $this->processPayment($order, $totals);
            
            // Reserve inventory
            $inventoryResult = $this->reserveInventory($order->items);
            
            // Update order status
            $order->update([
                'status' => 'processing',
                'payment_status' => 'paid',
                'total' => $totals['total'],
                'tax' => $totals['tax'],
                'shipping' => $totals['shipping'],
                'transaction_id' => $paymentResult['transaction_id']
            ]);
            
            DB::commit();
            
            // Send notifications asynchronously
            $this->dispatchNotifications($order);
            
            return $this->success([
                'order_id' => $order->id,
                'transaction_id' => $paymentResult['transaction_id'],
                'total' => $totals['total'],
                'estimated_shipping' => $this->calculateShippingDate($order)
            ], 'Order processed successfully');
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->failure([
                'error' => $e->getMessage(),
                'order_id' => $order->id ?? null
            ], 'Order processing failed');
        }
    }

    protected function rules(): array
    {
        return [
            'order' => 'required',
            'payment_method' => 'required|string',
            'shipping_address' => 'required|array',
            'billing_address' => 'required|array'
        ];
    }

    private function calculateOrderTotals($order): array
    {
        $subtotal = $order->items->sum(fn($item) => $item->price * $item->quantity);
        $tax = $subtotal * 0.08; // 8% tax
        $shipping = $this->calculateShipping($order);
        
        return [
            'subtotal' => $subtotal,
            'tax' => $tax,
            'shipping' => $shipping,
            'total' => $subtotal + $tax + $shipping
        ];
    }
}
```

### Content Management Actions

```php
class PublishContentAction extends BaseAction
{
    use ValidatesInput;

    public function execute(array $context = []): ActionResult
    {
        $validated = $this->validateContext($context);
        $content = $validated['content'];
        $publishOptions = $validated['options'] ?? [];
        
        try {
            // Pre-publication checks
            $this->runPrePublicationChecks($content);
            
            // Generate SEO metadata
            $seoData = $this->generateSEOMetadata($content);
            
            // Process images and media
            $mediaData = $this->processMedia($content);
            
            // Update content
            $content->update([
                'status' => 'published',
                'published_at' => $publishOptions['scheduled_at'] ?? now(),
                'seo_title' => $seoData['title'],
                'seo_description' => $seoData['description'],
                'featured_image' => $mediaData['featured_image'] ?? null
            ]);
            
            // Generate static cache
            $this->generateStaticCache($content);
            
            // Update search index
            $this->updateSearchIndex($content);
            
            // Notify subscribers
            if ($publishOptions['notify_subscribers'] ?? true) {
                $this->notifySubscribers($content);
            }
            
            // Submit to sitemap
            $this->updateSitemap($content);
            
            return $this->success([
                'content_id' => $content->id,
                'published_at' => $content->published_at,
                'url' => $content->getPublicUrl(),
                'seo_score' => $seoData['score'],
                'cache_generated' => true
            ], 'Content published successfully');
            
        } catch (\Exception $e) {
            return $this->failure([
                'error' => $e->getMessage(),
                'content_id' => $content->id
            ], 'Content publication failed');
        }
    }

    protected function rules(): array
    {
        return [
            'content' => 'required',
            'options.scheduled_at' => 'nullable|date|after:now',
            'options.notify_subscribers' => 'boolean',
            'options.social_share' => 'boolean'
        ];
    }
}
```

This comprehensive documentation covers all aspects of actions in Litepie Flow, providing developers with the knowledge needed to create powerful, reusable business logic components that integrate seamlessly with the workflow system.