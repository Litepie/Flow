<?php

namespace App\Examples\Integrations\Webhooks;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Litepie\Flow\Events\WorkflowTransitioned;
use Litepie\Actions\BaseAction;
use Litepie\Actions\Contracts\ActionResult;
use Carbon\Carbon;

/**
 * Outgoing Webhook Integration with Litepie Flow
 * 
 * This example demonstrates how to send webhook notifications when workflows
 * transition, including signature generation, retry logic, and failure handling.
 */

// 1. Webhook Delivery Service
class WebhookDeliveryService
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'timeout' => 30,
            'retry_attempts' => 3,
            'retry_delay' => [1, 5, 15], // seconds
            'verify_ssl' => true,
            'user_agent' => 'Litepie-Flow-Webhooks/1.0',
        ], $config);
    }

    public function deliverWebhook(WebhookPayload $payload): WebhookDeliveryResult
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->config['retry_attempts']) {
            try {
                $response = $this->sendWebhook($payload, $attempt);
                
                $result = new WebhookDeliveryResult(
                    success: true,
                    statusCode: $response->status(),
                    responseBody: $response->body(),
                    deliveryTime: now(),
                    attempts: $attempt + 1
                );

                $this->logDelivery($payload, $result, true);
                return $result;

            } catch (\Exception $e) {
                $lastException = $e;
                $attempt++;

                if ($attempt < $this->config['retry_attempts']) {
                    $delay = $this->config['retry_delay'][$attempt - 1] ?? 30;
                    sleep($delay);
                }
            }
        }

        $result = new WebhookDeliveryResult(
            success: false,
            statusCode: null,
            responseBody: null,
            deliveryTime: now(),
            attempts: $attempt,
            error: $lastException?->getMessage()
        );

        $this->logDelivery($payload, $result, false);
        return $result;
    }

    private function sendWebhook(WebhookPayload $payload, int $attempt): \Illuminate\Http\Client\Response
    {
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => $this->config['user_agent'],
            'X-Webhook-Signature' => $payload->getSignature(),
            'X-Webhook-ID' => $payload->getId(),
            'X-Webhook-Timestamp' => $payload->getTimestamp(),
            'X-Webhook-Attempt' => $attempt + 1,
        ];

        // Add custom headers if specified
        if ($payload->getHeaders()) {
            $headers = array_merge($headers, $payload->getHeaders());
        }

        return Http::withHeaders($headers)
            ->timeout($this->config['timeout'])
            ->when(!$this->config['verify_ssl'], fn($http) => $http->withoutVerifying())
            ->post($payload->getUrl(), $payload->getData());
    }

    private function logDelivery(WebhookPayload $payload, WebhookDeliveryResult $result, bool $success): void
    {
        $logData = [
            'webhook_id' => $payload->getId(),
            'url' => $payload->getUrl(),
            'event_type' => $payload->getEventType(),
            'success' => $success,
            'status_code' => $result->getStatusCode(),
            'attempts' => $result->getAttempts(),
            'delivery_time' => $result->getDeliveryTime(),
        ];

        if (!$success) {
            $logData['error'] = $result->getError();
        }

        if ($success) {
            Log::info('Webhook delivered successfully', $logData);
        } else {
            Log::error('Webhook delivery failed', $logData);
        }

        // Store delivery log in database
        $this->storeDeliveryLog($payload, $result);
    }

    private function storeDeliveryLog(WebhookPayload $payload, WebhookDeliveryResult $result): void
    {
        DB::table('webhook_delivery_logs')->insert([
            'webhook_id' => $payload->getId(),
            'url' => $payload->getUrl(),
            'event_type' => $payload->getEventType(),
            'payload' => json_encode($payload->getData()),
            'success' => $result->isSuccessful(),
            'status_code' => $result->getStatusCode(),
            'response_body' => $result->getResponseBody(),
            'attempts' => $result->getAttempts(),
            'error_message' => $result->getError(),
            'delivered_at' => $result->getDeliveryTime(),
            'created_at' => now(),
        ]);
    }
}

// 2. Webhook Payload Class
class WebhookPayload
{
    public function __construct(
        private string $id,
        private string $url,
        private string $eventType,
        private array $data,
        private string $secret,
        private array $headers = [],
        private ?Carbon $timestamp = null
    ) {
        $this->timestamp = $timestamp ?: now();
    }

    public static function fromWorkflowTransition(WorkflowTransitioned $event, string $url, string $secret): self
    {
        $subject = $event->getSubject();
        
        $data = [
            'event' => 'workflow.transitioned',
            'timestamp' => now()->toISOString(),
            'data' => [
                'workflow_name' => $event->getWorkflowName(),
                'entity' => [
                    'type' => get_class($subject),
                    'id' => $subject->getKey(),
                    'attributes' => $subject->toArray(),
                ],
                'transition' => [
                    'from' => $event->getFromState(),
                    'to' => $event->getToState(),
                    'event' => $event->getTransitionEvent() ?? 'transition',
                ],
                'context' => $event->getContext(),
                'metadata' => [
                    'user_id' => auth()->id(),
                    'ip_address' => request()->ip(),
                    'timestamp' => now()->toISOString(),
                ],
            ],
        ];

        return new self(
            id: (string) \Str::uuid(),
            url: $url,
            eventType: 'workflow.transitioned',
            data: $data,
            secret: $secret
        );
    }

    public function getSignature(): string
    {
        $payload = json_encode($this->data);
        $timestamp = $this->timestamp->timestamp;
        $signaturePayload = $timestamp . '.' . $payload;
        
        return 'sha256=' . hash_hmac('sha256', $signaturePayload, $this->secret);
    }

    // Getters
    public function getId(): string { return $this->id; }
    public function getUrl(): string { return $this->url; }
    public function getEventType(): string { return $this->eventType; }
    public function getData(): array { return $this->data; }
    public function getHeaders(): array { return $this->headers; }
    public function getTimestamp(): string { return (string) $this->timestamp->timestamp; }
}

// 3. Webhook Delivery Result Class
class WebhookDeliveryResult
{
    public function __construct(
        private bool $success,
        private ?int $statusCode,
        private ?string $responseBody,
        private Carbon $deliveryTime,
        private int $attempts,
        private ?string $error = null
    ) {}

    public function isSuccessful(): bool { return $this->success; }
    public function getStatusCode(): ?int { return $this->statusCode; }
    public function getResponseBody(): ?string { return $this->responseBody; }
    public function getDeliveryTime(): Carbon { return $this->deliveryTime; }
    public function getAttempts(): int { return $this->attempts; }
    public function getError(): ?string { return $this->error; }
}

// 4. Webhook Configuration Manager
class WebhookConfigurationManager
{
    public function getWebhooksForEvent(string $eventType): array
    {
        return DB::table('webhook_subscriptions')
            ->where('event_type', $eventType)
            ->where('active', true)
            ->get()
            ->map(function ($subscription) {
                return [
                    'id' => $subscription->id,
                    'url' => $subscription->url,
                    'secret' => $subscription->secret,
                    'headers' => json_decode($subscription->headers ?? '{}', true),
                    'filters' => json_decode($subscription->filters ?? '{}', true),
                ];
            })
            ->toArray();
    }

    public function createWebhookSubscription(array $data): int
    {
        return DB::table('webhook_subscriptions')->insertGetId([
            'name' => $data['name'],
            'url' => $data['url'],
            'event_type' => $data['event_type'],
            'secret' => $data['secret'] ?? $this->generateSecret(),
            'headers' => json_encode($data['headers'] ?? []),
            'filters' => json_encode($data['filters'] ?? []),
            'active' => $data['active'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function updateWebhookSubscription(int $id, array $data): bool
    {
        return DB::table('webhook_subscriptions')
            ->where('id', $id)
            ->update(array_merge($data, ['updated_at' => now()]));
    }

    public function deleteWebhookSubscription(int $id): bool
    {
        return DB::table('webhook_subscriptions')->where('id', $id)->delete();
    }

    private function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function testWebhook(int $subscriptionId): WebhookDeliveryResult
    {
        $subscription = DB::table('webhook_subscriptions')
            ->where('id', $subscriptionId)
            ->first();

        if (!$subscription) {
            return new WebhookDeliveryResult(
                success: false,
                statusCode: null,
                responseBody: null,
                deliveryTime: now(),
                attempts: 0,
                error: 'Webhook subscription not found'
            );
        }

        $testPayload = new WebhookPayload(
            id: (string) \Str::uuid(),
            url: $subscription->url,
            eventType: 'webhook.test',
            data: [
                'event' => 'webhook.test',
                'timestamp' => now()->toISOString(),
                'data' => [
                    'message' => 'This is a test webhook',
                    'subscription_id' => $subscriptionId,
                ],
            ],
            secret: $subscription->secret,
            headers: json_decode($subscription->headers ?? '{}', true)
        );

        $deliveryService = new WebhookDeliveryService();
        return $deliveryService->deliverWebhook($testPayload);
    }
}

// 5. Workflow Webhook Action
class WorkflowWebhookAction extends BaseAction
{
    public function __construct(
        private WebhookDeliveryService $deliveryService,
        private WebhookConfigurationManager $configManager
    ) {}

    public function execute(array $context = []): ActionResult
    {
        $event = $context['event'];
        $eventType = $context['event_type'] ?? 'workflow.transitioned';

        try {
            $webhooks = $this->configManager->getWebhooksForEvent($eventType);
            
            if (empty($webhooks)) {
                return $this->success([
                    'webhooks_found' => 0,
                    'deliveries_attempted' => 0,
                ], 'No webhooks configured for this event type');
            }

            $results = [];
            $successCount = 0;
            $failureCount = 0;

            foreach ($webhooks as $webhook) {
                // Apply filters if configured
                if (!$this->passesFilters($event, $webhook['filters'])) {
                    continue;
                }

                $payload = WebhookPayload::fromWorkflowTransition(
                    $event,
                    $webhook['url'],
                    $webhook['secret']
                );

                // Add custom headers
                if (!empty($webhook['headers'])) {
                    $payload = new WebhookPayload(
                        $payload->getId(),
                        $payload->getUrl(),
                        $payload->getEventType(),
                        $payload->getData(),
                        $webhook['secret'],
                        $webhook['headers']
                    );
                }

                $result = $this->deliveryService->deliverWebhook($payload);
                
                $results[] = [
                    'webhook_id' => $webhook['id'],
                    'url' => $webhook['url'],
                    'success' => $result->isSuccessful(),
                    'status_code' => $result->getStatusCode(),
                    'attempts' => $result->getAttempts(),
                ];

                if ($result->isSuccessful()) {
                    $successCount++;
                } else {
                    $failureCount++;
                }
            }

            return $this->success([
                'webhooks_found' => count($webhooks),
                'deliveries_attempted' => count($results),
                'successful_deliveries' => $successCount,
                'failed_deliveries' => $failureCount,
                'delivery_results' => $results,
            ], 'Webhook deliveries completed');

        } catch (\Exception $e) {
            return $this->failure([
                'error' => $e->getMessage(),
                'event_type' => $eventType,
            ], 'Webhook delivery process failed');
        }
    }

    private function passesFilters($event, array $filters): bool
    {
        if (empty($filters)) {
            return true;
        }

        $subject = $event->getSubject();

        // Check model type filter
        if (isset($filters['model_type'])) {
            if (!in_array(get_class($subject), (array) $filters['model_type'])) {
                return false;
            }
        }

        // Check state filter
        if (isset($filters['to_state'])) {
            if (!in_array($event->getToState(), (array) $filters['to_state'])) {
                return false;
            }
        }

        // Check attribute filters
        if (isset($filters['attributes'])) {
            foreach ($filters['attributes'] as $attribute => $expectedValue) {
                if ($subject->getAttribute($attribute) !== $expectedValue) {
                    return false;
                }
            }
        }

        return true;
    }

    protected function rules(): array
    {
        return [
            'event' => 'required',
            'event_type' => 'string',
        ];
    }
}

// 6. Incoming Webhook Handler
class IncomingWebhookHandler
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'verify_signatures' => true,
            'tolerance' => 300, // 5 minutes
        ], $config);
    }

    public function handle(Request $request, string $endpointSecret): array
    {
        // Verify webhook signature
        if ($this->config['verify_signatures']) {
            if (!$this->verifySignature($request, $endpointSecret)) {
                throw new WebhookVerificationException('Invalid webhook signature');
            }
        }

        // Parse webhook payload
        $payload = $request->json()->all();
        
        if (!$this->isValidPayload($payload)) {
            throw new WebhookValidationException('Invalid webhook payload structure');
        }

        // Process the webhook
        return $this->processWebhook($payload);
    }

    private function verifySignature(Request $request, string $secret): bool
    {
        $signature = $request->header('X-Webhook-Signature');
        $timestamp = $request->header('X-Webhook-Timestamp');
        
        if (!$signature || !$timestamp) {
            return false;
        }

        // Check timestamp tolerance
        if (abs(time() - $timestamp) > $this->config['tolerance']) {
            return false;
        }

        $body = $request->getContent();
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        
        return hash_equals($expectedSignature, $signature);
    }

    private function isValidPayload(array $payload): bool
    {
        return isset($payload['event']) 
            && isset($payload['timestamp']) 
            && isset($payload['data']);
    }

    private function processWebhook(array $payload): array
    {
        $eventType = $payload['event'];
        $eventData = $payload['data'];

        // Log incoming webhook
        Log::info('Incoming webhook received', [
            'event_type' => $eventType,
            'timestamp' => $payload['timestamp'],
            'data_keys' => array_keys($eventData),
        ]);

        // Process based on event type
        $result = match($eventType) {
            'order.status_updated' => $this->handleOrderStatusUpdate($eventData),
            'payment.completed' => $this->handlePaymentCompleted($eventData),
            'shipment.delivered' => $this->handleShipmentDelivered($eventData),
            default => $this->handleUnknownEvent($eventType, $eventData)
        };

        // Store webhook event
        $this->storeIncomingWebhook($payload, $result);

        return $result;
    }

    private function handleOrderStatusUpdate(array $data): array
    {
        $orderId = $data['order_id'] ?? null;
        $newStatus = $data['status'] ?? null;

        if (!$orderId || !$newStatus) {
            throw new WebhookValidationException('Missing required fields: order_id, status');
        }

        // Find and update order
        $order = \App\Models\Order::find($orderId);
        
        if (!$order) {
            return [
                'success' => false,
                'message' => 'Order not found',
                'order_id' => $orderId,
            ];
        }

        // Trigger workflow transition
        $success = $order->transitionTo($newStatus, [
            'webhook_data' => $data,
            'external_update' => true,
        ]);

        return [
            'success' => $success,
            'message' => $success ? 'Order status updated' : 'Failed to update order status',
            'order_id' => $orderId,
            'new_status' => $newStatus,
        ];
    }

    private function handlePaymentCompleted(array $data): array
    {
        $paymentId = $data['payment_id'] ?? null;
        $orderId = $data['order_id'] ?? null;

        if (!$paymentId || !$orderId) {
            throw new WebhookValidationException('Missing required fields: payment_id, order_id');
        }

        $order = \App\Models\Order::find($orderId);
        
        if (!$order) {
            return [
                'success' => false,
                'message' => 'Order not found',
                'order_id' => $orderId,
            ];
        }

        // Update order with payment information
        $order->update([
            'payment_id' => $paymentId,
            'payment_status' => 'completed',
            'paid_at' => now(),
        ]);

        // Trigger workflow transition
        $success = $order->transitionTo('processing', [
            'payment_data' => $data,
            'external_update' => true,
        ]);

        return [
            'success' => $success,
            'message' => 'Payment processed',
            'order_id' => $orderId,
            'payment_id' => $paymentId,
        ];
    }

    private function handleShipmentDelivered(array $data): array
    {
        $trackingNumber = $data['tracking_number'] ?? null;
        $deliveredAt = $data['delivered_at'] ?? null;

        if (!$trackingNumber) {
            throw new WebhookValidationException('Missing required field: tracking_number');
        }

        $order = \App\Models\Order::where('tracking_number', $trackingNumber)->first();
        
        if (!$order) {
            return [
                'success' => false,
                'message' => 'Order not found',
                'tracking_number' => $trackingNumber,
            ];
        }

        // Update order with delivery information
        $order->update([
            'delivered_at' => $deliveredAt ? Carbon::parse($deliveredAt) : now(),
        ]);

        // Trigger workflow transition
        $success = $order->transitionTo('delivered', [
            'delivery_data' => $data,
            'external_update' => true,
        ]);

        return [
            'success' => $success,
            'message' => 'Delivery confirmed',
            'order_id' => $order->id,
            'tracking_number' => $trackingNumber,
        ];
    }

    private function handleUnknownEvent(string $eventType, array $data): array
    {
        Log::warning('Unknown webhook event received', [
            'event_type' => $eventType,
            'data' => $data,
        ]);

        return [
            'success' => false,
            'message' => 'Unknown event type',
            'event_type' => $eventType,
        ];
    }

    private function storeIncomingWebhook(array $payload, array $result): void
    {
        DB::table('incoming_webhook_logs')->insert([
            'event_type' => $payload['event'],
            'payload' => json_encode($payload),
            'processing_result' => json_encode($result),
            'success' => $result['success'] ?? false,
            'received_at' => now(),
        ]);
    }
}

// 7. Webhook Event Listener
class OutgoingWebhookListener
{
    public function __construct(
        private WebhookDeliveryService $deliveryService,
        private WebhookConfigurationManager $configManager
    ) {}

    public function handle(WorkflowTransitioned $event): void
    {
        $action = new WorkflowWebhookAction($this->deliveryService, $this->configManager);
        
        $result = $action->execute([
            'event' => $event,
            'event_type' => 'workflow.transitioned',
        ]);

        if (!$result->isSuccessful()) {
            Log::error('Webhook action failed', [
                'error' => $result->getMessage(),
                'data' => $result->getData(),
            ]);
        }
    }
}

// 8. Custom Exceptions
class WebhookVerificationException extends \Exception {}
class WebhookValidationException extends \Exception {}

// 9. Usage Examples
class WebhookUsageExamples
{
    public function __construct(
        private WebhookConfigurationManager $configManager,
        private IncomingWebhookHandler $incomingHandler
    ) {}

    public function createWebhookSubscription(): void
    {
        $subscriptionId = $this->configManager->createWebhookSubscription([
            'name' => 'Order Status Updates',
            'url' => 'https://api.example.com/webhooks/order-status',
            'event_type' => 'workflow.transitioned',
            'headers' => [
                'Authorization' => 'Bearer your-api-token',
            ],
            'filters' => [
                'model_type' => ['App\\Models\\Order'],
                'to_state' => ['processing', 'shipped', 'delivered'],
            ],
        ]);

        echo "Created webhook subscription with ID: {$subscriptionId}\n";
    }

    public function testWebhook(int $subscriptionId): void
    {
        $result = $this->configManager->testWebhook($subscriptionId);
        
        if ($result->isSuccessful()) {
            echo "Webhook test successful! Status: {$result->getStatusCode()}\n";
        } else {
            echo "Webhook test failed: {$result->getError()}\n";
        }
    }

    public function handleIncomingWebhook(Request $request): array
    {
        $endpointSecret = config('webhooks.endpoint_secret');
        
        try {
            return $this->incomingHandler->handle($request, $endpointSecret);
        } catch (WebhookVerificationException $e) {
            return [
                'success' => false,
                'error' => 'Signature verification failed',
            ];
        } catch (WebhookValidationException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
