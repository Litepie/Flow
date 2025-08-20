<?php

namespace App\Examples\Integrations\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Litepie\Actions\BaseAction;
use Litepie\Actions\Contracts\ActionResult;
use Carbon\Carbon;

/**
 * Incoming Webhook Integration with Litepie Flow
 * 
 * This example demonstrates how to handle incoming webhooks from external systems
 * and trigger workflow transitions based on the received data.
 */

// 1. Webhook Security Manager
class WebhookSecurityManager
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'signature_header' => 'X-Hub-Signature-256',
            'timestamp_header' => 'X-Hub-Timestamp',
            'tolerance' => 300, // 5 minutes
            'require_signature' => true,
            'require_timestamp' => true,
            'allowed_ips' => [], // Empty means allow all
        ], $config);
    }

    public function validateRequest(Request $request, string $secret): WebhookValidationResult
    {
        $errors = [];

        // Validate IP address
        if (!empty($this->config['allowed_ips']) && !$this->isAllowedIP($request->ip())) {
            $errors[] = 'IP address not allowed';
        }

        // Validate timestamp
        if ($this->config['require_timestamp']) {
            $timestamp = $request->header($this->config['timestamp_header']);
            if (!$timestamp) {
                $errors[] = 'Missing timestamp header';
            } elseif (abs(time() - $timestamp) > $this->config['tolerance']) {
                $errors[] = 'Request timestamp too old';
            }
        }

        // Validate signature
        if ($this->config['require_signature']) {
            $signature = $request->header($this->config['signature_header']);
            if (!$signature) {
                $errors[] = 'Missing signature header';
            } else {
                $body = $request->getContent();
                $timestamp = $request->header($this->config['timestamp_header']) ?? time();
                
                if (!$this->verifySignature($body, $signature, $secret, $timestamp)) {
                    $errors[] = 'Invalid signature';
                }
            }
        }

        return new WebhookValidationResult(
            isValid: empty($errors),
            errors: $errors,
            clientIP: $request->ip(),
            timestamp: $request->header($this->config['timestamp_header']),
            signature: $request->header($this->config['signature_header'])
        );
    }

    private function isAllowedIP(string $ip): bool
    {
        foreach ($this->config['allowed_ips'] as $allowedIP) {
            if ($this->ipMatches($ip, $allowedIP)) {
                return true;
            }
        }
        return false;
    }

    private function ipMatches(string $ip, string $pattern): bool
    {
        // Support CIDR notation
        if (strpos($pattern, '/') !== false) {
            return $this->ipInCIDR($ip, $pattern);
        }
        
        // Exact match
        return $ip === $pattern;
    }

    private function ipInCIDR(string $ip, string $cidr): bool
    {
        list($subnet, $bits) = explode('/', $cidr);
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - $bits);
        $subnet &= $mask;
        return ($ip & $mask) == $subnet;
    }

    private function verifySignature(string $body, string $signature, string $secret, int $timestamp): bool
    {
        $payload = $timestamp . '.' . $body;
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        
        return hash_equals($expectedSignature, $signature);
    }
}

// 2. Webhook Validation Result
class WebhookValidationResult
{
    public function __construct(
        private bool $isValid,
        private array $errors,
        private string $clientIP,
        private ?string $timestamp,
        private ?string $signature
    ) {}

    public function isValid(): bool { return $this->isValid; }
    public function getErrors(): array { return $this->errors; }
    public function getClientIP(): string { return $this->clientIP; }
    public function getTimestamp(): ?string { return $this->timestamp; }
    public function getSignature(): ?string { return $this->signature; }
}

// 3. Webhook Event Processor
class WebhookEventProcessor
{
    private array $handlers = [];

    public function registerHandler(string $eventType, callable $handler): void
    {
        $this->handlers[$eventType] = $handler;
    }

    public function process(IncomingWebhookEvent $event): WebhookProcessingResult
    {
        try {
            $eventType = $event->getEventType();
            
            if (!isset($this->handlers[$eventType])) {
                return new WebhookProcessingResult(
                    success: false,
                    message: "No handler registered for event type: {$eventType}",
                    eventType: $eventType,
                    handlerFound: false
                );
            }

            $handler = $this->handlers[$eventType];
            $result = $handler($event);

            return new WebhookProcessingResult(
                success: true,
                message: 'Event processed successfully',
                eventType: $eventType,
                handlerFound: true,
                result: $result
            );

        } catch (\Exception $e) {
            Log::error('Webhook event processing failed', [
                'event_type' => $event->getEventType(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new WebhookProcessingResult(
                success: false,
                message: $e->getMessage(),
                eventType: $event->getEventType(),
                handlerFound: true,
                error: $e
            );
        }
    }
}

// 4. Incoming Webhook Event
class IncomingWebhookEvent
{
    public function __construct(
        private string $id,
        private string $eventType,
        private array $data,
        private array $headers,
        private Carbon $receivedAt,
        private string $sourceIP,
        private ?string $source = null
    ) {}

    public static function fromRequest(Request $request): self
    {
        $payload = $request->json()->all();
        
        return new self(
            id: $request->header('X-Webhook-ID') ?? (string) \Str::uuid(),
            eventType: $payload['event'] ?? 'unknown',
            data: $payload,
            headers: $request->headers->all(),
            receivedAt: now(),
            sourceIP: $request->ip(),
            source: $request->header('User-Agent')
        );
    }

    // Getters
    public function getId(): string { return $this->id; }
    public function getEventType(): string { return $this->eventType; }
    public function getData(): array { return $this->data; }
    public function getHeaders(): array { return $this->headers; }
    public function getReceivedAt(): Carbon { return $this->receivedAt; }
    public function getSourceIP(): string { return $this->sourceIP; }
    public function getSource(): ?string { return $this->source; }
    
    public function getEventData(): array
    {
        return $this->data['data'] ?? [];
    }
}

// 5. Webhook Processing Result
class WebhookProcessingResult
{
    public function __construct(
        private bool $success,
        private string $message,
        private string $eventType,
        private bool $handlerFound,
        private mixed $result = null,
        private ?\Exception $error = null
    ) {}

    public function isSuccessful(): bool { return $this->success; }
    public function getMessage(): string { return $this->message; }
    public function getEventType(): string { return $this->eventType; }
    public function isHandlerFound(): bool { return $this->handlerFound; }
    public function getResult(): mixed { return $this->result; }
    public function getError(): ?\Exception { return $this->error; }
}

// 6. Webhook Endpoint Controller
class WebhookEndpointController
{
    public function __construct(
        private WebhookSecurityManager $securityManager,
        private WebhookEventProcessor $eventProcessor,
        private WebhookLogger $logger
    ) {}

    public function handle(Request $request, string $endpoint): \Illuminate\Http\JsonResponse
    {
        $webhookId = (string) \Str::uuid();
        
        try {
            // Get endpoint configuration
            $config = $this->getEndpointConfig($endpoint);
            if (!$config) {
                return response()->json([
                    'success' => false,
                    'error' => 'Endpoint not found',
                    'webhook_id' => $webhookId,
                ], 404);
            }

            // Validate request security
            $validationResult = $this->securityManager->validateRequest($request, $config['secret']);
            
            if (!$validationResult->isValid()) {
                $this->logger->logSecurityFailure($webhookId, $endpoint, $validationResult);
                
                return response()->json([
                    'success' => false,
                    'error' => 'Security validation failed',
                    'errors' => $validationResult->getErrors(),
                    'webhook_id' => $webhookId,
                ], 401);
            }

            // Create webhook event
            $event = IncomingWebhookEvent::fromRequest($request);
            
            // Log incoming webhook
            $this->logger->logIncoming($webhookId, $endpoint, $event);

            // Process the event
            $processingResult = $this->eventProcessor->process($event);

            // Log processing result
            $this->logger->logProcessing($webhookId, $processingResult);

            $responseData = [
                'success' => $processingResult->isSuccessful(),
                'message' => $processingResult->getMessage(),
                'webhook_id' => $webhookId,
                'event_type' => $processingResult->getEventType(),
            ];

            if ($processingResult->getResult()) {
                $responseData['result'] = $processingResult->getResult();
            }

            $statusCode = $processingResult->isSuccessful() ? 200 : 422;
            
            return response()->json($responseData, $statusCode);

        } catch (\Exception $e) {
            $this->logger->logError($webhookId, $endpoint, $e);
            
            return response()->json([
                'success' => false,
                'error' => 'Internal server error',
                'webhook_id' => $webhookId,
            ], 500);
        }
    }

    private function getEndpointConfig(string $endpoint): ?array
    {
        return Cache::remember("webhook_endpoint:{$endpoint}", 300, function () use ($endpoint) {
            return DB::table('webhook_endpoints')
                ->where('name', $endpoint)
                ->where('active', true)
                ->first()?->toArray();
        });
    }
}

// 7. Webhook Logger
class WebhookLogger
{
    public function logIncoming(string $webhookId, string $endpoint, IncomingWebhookEvent $event): void
    {
        DB::table('webhook_logs')->insert([
            'webhook_id' => $webhookId,
            'endpoint' => $endpoint,
            'event_type' => $event->getEventType(),
            'source_ip' => $event->getSourceIP(),
            'user_agent' => $event->getSource(),
            'headers' => json_encode($event->getHeaders()),
            'payload' => json_encode($event->getData()),
            'status' => 'received',
            'created_at' => now(),
        ]);

        Log::info('Incoming webhook received', [
            'webhook_id' => $webhookId,
            'endpoint' => $endpoint,
            'event_type' => $event->getEventType(),
            'source_ip' => $event->getSourceIP(),
        ]);
    }

    public function logSecurityFailure(string $webhookId, string $endpoint, WebhookValidationResult $validation): void
    {
        DB::table('webhook_logs')->insert([
            'webhook_id' => $webhookId,
            'endpoint' => $endpoint,
            'source_ip' => $validation->getClientIP(),
            'status' => 'security_failed',
            'error_message' => implode(', ', $validation->getErrors()),
            'created_at' => now(),
        ]);

        Log::warning('Webhook security validation failed', [
            'webhook_id' => $webhookId,
            'endpoint' => $endpoint,
            'source_ip' => $validation->getClientIP(),
            'errors' => $validation->getErrors(),
        ]);
    }

    public function logProcessing(string $webhookId, WebhookProcessingResult $result): void
    {
        DB::table('webhook_logs')
            ->where('webhook_id', $webhookId)
            ->update([
                'status' => $result->isSuccessful() ? 'processed' : 'failed',
                'processing_result' => json_encode([
                    'success' => $result->isSuccessful(),
                    'message' => $result->getMessage(),
                    'handler_found' => $result->isHandlerFound(),
                    'result' => $result->getResult(),
                ]),
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function logError(string $webhookId, string $endpoint, \Exception $e): void
    {
        DB::table('webhook_logs')
            ->updateOrInsert(
                ['webhook_id' => $webhookId],
                [
                    'endpoint' => $endpoint,
                    'status' => 'error',
                    'error_message' => $e->getMessage(),
                    'error_trace' => $e->getTraceAsString(),
                    'updated_at' => now(),
                ]
            );

        Log::error('Webhook processing error', [
            'webhook_id' => $webhookId,
            'endpoint' => $endpoint,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}

// 8. Specific Webhook Handlers

// Order Status Update Handler
class OrderStatusWebhookHandler
{
    public function handle(IncomingWebhookEvent $event): array
    {
        $data = $event->getEventData();
        
        $validator = Validator::make($data, [
            'order_id' => 'required|integer|exists:orders,id',
            'status' => 'required|string',
            'updated_at' => 'required|date',
        ]);

        if ($validator->fails()) {
            throw new \InvalidArgumentException('Invalid order status data: ' . implode(', ', $validator->errors()->all()));
        }

        $orderId = $data['order_id'];
        $newStatus = $data['status'];
        $updatedAt = Carbon::parse($data['updated_at']);

        $order = \App\Models\Order::find($orderId);
        
        // Check if the update is newer than our current data
        if ($order->updated_at && $order->updated_at->greaterThan($updatedAt)) {
            return [
                'skipped' => true,
                'reason' => 'Local data is newer',
                'order_id' => $orderId,
            ];
        }

        // Attempt to transition the order
        $transitioned = $order->transitionTo($newStatus, [
            'external_update' => true,
            'webhook_data' => $data,
            'source' => $event->getSource(),
        ]);

        if (!$transitioned) {
            return [
                'success' => false,
                'reason' => 'Invalid transition',
                'order_id' => $orderId,
                'current_status' => $order->getCurrentState(),
                'attempted_status' => $newStatus,
            ];
        }

        return [
            'success' => true,
            'order_id' => $orderId,
            'previous_status' => $order->getCurrentState(),
            'new_status' => $newStatus,
            'transitioned_at' => now(),
        ];
    }
}

// Payment Webhook Handler
class PaymentWebhookHandler
{
    public function handle(IncomingWebhookEvent $event): array
    {
        $data = $event->getEventData();
        
        $validator = Validator::make($data, [
            'payment_id' => 'required|string',
            'order_id' => 'required|integer|exists:orders,id',
            'amount' => 'required|numeric',
            'currency' => 'required|string|size:3',
            'status' => 'required|string|in:completed,failed,refunded',
        ]);

        if ($validator->fails()) {
            throw new \InvalidArgumentException('Invalid payment data: ' . implode(', ', $validator->errors()->all()));
        }

        $order = \App\Models\Order::find($data['order_id']);
        
        // Update payment information
        $order->update([
            'payment_id' => $data['payment_id'],
            'payment_status' => $data['status'],
            'payment_amount' => $data['amount'],
            'payment_currency' => $data['currency'],
            'payment_completed_at' => $data['status'] === 'completed' ? now() : null,
        ]);

        // Trigger appropriate workflow transition
        $newState = match($data['status']) {
            'completed' => 'processing',
            'failed' => 'payment_failed',
            'refunded' => 'refunded',
        };

        $transitioned = $order->transitionTo($newState, [
            'payment_data' => $data,
            'external_update' => true,
        ]);

        return [
            'success' => $transitioned,
            'order_id' => $order->id,
            'payment_status' => $data['status'],
            'new_order_status' => $transitioned ? $newState : $order->getCurrentState(),
        ];
    }
}

// Inventory Update Handler
class InventoryWebhookHandler
{
    public function handle(IncomingWebhookEvent $event): array
    {
        $data = $event->getEventData();
        
        $validator = Validator::make($data, [
            'sku' => 'required|string',
            'quantity' => 'required|integer|min:0',
            'location' => 'string',
        ]);

        if ($validator->fails()) {
            throw new \InvalidArgumentException('Invalid inventory data: ' . implode(', ', $validator->errors()->all()));
        }

        $sku = $data['sku'];
        $quantity = $data['quantity'];
        $location = $data['location'] ?? 'default';

        // Update inventory
        $inventory = DB::table('inventory')
            ->where('sku', $sku)
            ->where('location', $location)
            ->first();

        if (!$inventory) {
            // Create new inventory record
            DB::table('inventory')->insert([
                'sku' => $sku,
                'quantity' => $quantity,
                'location' => $location,
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        } else {
            // Update existing inventory
            DB::table('inventory')
                ->where('sku', $sku)
                ->where('location', $location)
                ->update([
                    'quantity' => $quantity,
                    'updated_at' => now(),
                ]);
        }

        // Check for pending orders that can now be fulfilled
        $this->checkPendingOrders($sku, $location);

        return [
            'success' => true,
            'sku' => $sku,
            'quantity' => $quantity,
            'location' => $location,
            'action' => $inventory ? 'updated' : 'created',
        ];
    }

    private function checkPendingOrders(string $sku, string $location): void
    {
        $pendingOrders = DB::table('orders')
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.current_state', 'pending_inventory')
            ->where('order_items.sku', $sku)
            ->select('orders.*')
            ->get();

        foreach ($pendingOrders as $orderData) {
            $order = \App\Models\Order::find($orderData->id);
            
            if ($this->hasInventoryForOrder($order)) {
                $order->transitionTo('processing', [
                    'inventory_update' => true,
                    'sku' => $sku,
                ]);
            }
        }
    }

    private function hasInventoryForOrder(\App\Models\Order $order): bool
    {
        foreach ($order->items as $item) {
            $inventory = DB::table('inventory')
                ->where('sku', $item->sku)
                ->sum('quantity');
                
            if ($inventory < $item->quantity) {
                return false;
            }
        }
        
        return true;
    }
}

// 9. Webhook Setup and Configuration
class IncomingWebhookSetup
{
    public function setupEventHandlers(WebhookEventProcessor $processor): void
    {
        // Register handlers for different event types
        $processor->registerHandler('order.status_updated', function (IncomingWebhookEvent $event) {
            $handler = new OrderStatusWebhookHandler();
            return $handler->handle($event);
        });

        $processor->registerHandler('payment.completed', function (IncomingWebhookEvent $event) {
            $handler = new PaymentWebhookHandler();
            return $handler->handle($event);
        });

        $processor->registerHandler('payment.failed', function (IncomingWebhookEvent $event) {
            $handler = new PaymentWebhookHandler();
            return $handler->handle($event);
        });

        $processor->registerHandler('inventory.updated', function (IncomingWebhookEvent $event) {
            $handler = new InventoryWebhookHandler();
            return $handler->handle($event);
        });
    }

    public function createWebhookEndpoint(string $name, string $secret, array $config = []): int
    {
        return DB::table('webhook_endpoints')->insertGetId([
            'name' => $name,
            'secret' => $secret,
            'config' => json_encode($config),
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

// 10. Usage Examples
class IncomingWebhookUsageExamples
{
    public function setupWebhookEndpoint(): void
    {
        $setup = new IncomingWebhookSetup();
        
        // Create endpoint configuration
        $endpointId = $setup->createWebhookEndpoint('order_updates', 'your-secret-key', [
            'allowed_ips' => ['192.168.1.0/24', '10.0.0.1'],
            'require_signature' => true,
            'tolerance' => 300,
        ]);

        echo "Created webhook endpoint with ID: {$endpointId}\n";
    }

    public function processWebhookRequest(Request $request): array
    {
        $securityManager = new WebhookSecurityManager([
            'allowed_ips' => ['192.168.1.0/24'],
            'require_signature' => true,
        ]);

        $eventProcessor = new WebhookEventProcessor();
        $logger = new WebhookLogger();
        $setup = new IncomingWebhookSetup();
        
        // Setup event handlers
        $setup->setupEventHandlers($eventProcessor);

        $controller = new WebhookEndpointController($securityManager, $eventProcessor, $logger);
        
        return $controller->handle($request, 'order_updates')->getData(true);
    }
}
