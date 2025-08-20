<?php

namespace App\Examples\Integrations\ExternalAPIs;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Litepie\Flow\Events\WorkflowTransitioned;
use Litepie\Actions\BaseAction;
use Litepie\Actions\Contracts\ActionResult;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use Carbon\Carbon;

/**
 * REST API Integration with Litepie Flow
 * 
 * This example demonstrates how to integrate REST APIs with workflow transitions,
 * including retry logic, error handling, rate limiting, and response caching.
 */

// 1. REST API Client with Retry Logic
class RestApiClient
{
    private Client $client;
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'base_uri' => '',
            'timeout' => 30,
            'connect_timeout' => 10,
            'retry_attempts' => 3,
            'retry_delay' => 1000, // milliseconds
            'rate_limit' => 100, // requests per minute
            'cache_ttl' => 300, // seconds
        ], $config);

        $this->client = new Client([
            'base_uri' => $this->config['base_uri'],
            'timeout' => $this->config['timeout'],
            'connect_timeout' => $this->config['connect_timeout'],
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => 'Litepie-Flow/1.0',
            ],
        ]);
    }

    public function get(string $endpoint, array $params = [], array $options = []): array
    {
        return $this->makeRequest('GET', $endpoint, [
            'query' => $params,
        ] + $options);
    }

    public function post(string $endpoint, array $data = [], array $options = []): array
    {
        return $this->makeRequest('POST', $endpoint, [
            'json' => $data,
        ] + $options);
    }

    public function put(string $endpoint, array $data = [], array $options = []): array
    {
        return $this->makeRequest('PUT', $endpoint, [
            'json' => $data,
        ] + $options);
    }

    public function delete(string $endpoint, array $options = []): array
    {
        return $this->makeRequest('DELETE', $endpoint, $options);
    }

    private function makeRequest(string $method, string $endpoint, array $options = []): array
    {
        $cacheKey = $this->getCacheKey($method, $endpoint, $options);
        
        // Return cached response for GET requests
        if ($method === 'GET' && $cached = Cache::get($cacheKey)) {
            return $cached;
        }

        // Check rate limiting
        $this->checkRateLimit();

        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->config['retry_attempts']) {
            try {
                $response = $this->client->request($method, $endpoint, $options);
                $data = json_decode($response->getBody()->getContents(), true);

                // Cache successful GET responses
                if ($method === 'GET') {
                    Cache::put($cacheKey, $data, $this->config['cache_ttl']);
                }

                $this->logRequest($method, $endpoint, $options, $response->getStatusCode(), true);
                
                return $data;

            } catch (ConnectException $e) {
                $lastException = $e;
                $this->logRequest($method, $endpoint, $options, null, false, $e->getMessage());
                
                if ($attempt < $this->config['retry_attempts'] - 1) {
                    $this->sleep($attempt);
                }
                
            } catch (RequestException $e) {
                $lastException = $e;
                $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : null;
                
                $this->logRequest($method, $endpoint, $options, $statusCode, false, $e->getMessage());

                // Don't retry on client errors (4xx)
                if ($statusCode && $statusCode >= 400 && $statusCode < 500) {
                    break;
                }

                if ($attempt < $this->config['retry_attempts'] - 1) {
                    $this->sleep($attempt);
                }
            }

            $attempt++;
        }

        throw new ApiException(
            "API request failed after {$this->config['retry_attempts']} attempts: " . 
            ($lastException ? $lastException->getMessage() : 'Unknown error')
        );
    }

    private function checkRateLimit(): void
    {
        $key = 'api_rate_limit:' . $this->config['base_uri'];
        $requests = Cache::get($key, 0);

        if ($requests >= $this->config['rate_limit']) {
            throw new RateLimitException('Rate limit exceeded. Please try again later.');
        }

        Cache::put($key, $requests + 1, 60); // 1 minute window
    }

    private function sleep(int $attempt): void
    {
        $delay = $this->config['retry_delay'] * (2 ** $attempt); // Exponential backoff
        usleep($delay * 1000); // Convert to microseconds
    }

    private function getCacheKey(string $method, string $endpoint, array $options): string
    {
        return 'api_cache:' . md5($method . $endpoint . serialize($options));
    }

    private function logRequest(string $method, string $endpoint, array $options, ?int $statusCode, bool $success, ?string $error = null): void
    {
        $logData = [
            'method' => $method,
            'endpoint' => $endpoint,
            'status_code' => $statusCode,
            'success' => $success,
            'base_uri' => $this->config['base_uri'],
        ];

        if ($error) {
            $logData['error'] = $error;
        }

        if ($success) {
            Log::info('API request successful', $logData);
        } else {
            Log::warning('API request failed', $logData);
        }
    }
}

// 2. Order API Integration Action
class SyncOrderWithExternalApiAction extends BaseAction
{
    private RestApiClient $apiClient;

    public function __construct()
    {
        $this->apiClient = new RestApiClient([
            'base_uri' => config('services.order_api.base_url'),
            'timeout' => 30,
            'retry_attempts' => 3,
        ]);
    }

    public function execute(array $context = []): ActionResult
    {
        try {
            $order = $context['order'];
            $operation = $context['operation'] ?? 'update';

            $result = match($operation) {
                'create' => $this->createOrderInExternalSystem($order),
                'update' => $this->updateOrderInExternalSystem($order),
                'cancel' => $this->cancelOrderInExternalSystem($order),
                'ship' => $this->markOrderAsShipped($order),
                default => throw new \InvalidArgumentException("Unsupported operation: {$operation}")
            };

            return $this->success([
                'external_id' => $result['id'] ?? null,
                'external_status' => $result['status'] ?? null,
                'sync_timestamp' => now()->toISOString(),
                'operation' => $operation,
            ], 'Order synchronized with external API successfully');

        } catch (ApiException $e) {
            return $this->failure([
                'error' => $e->getMessage(),
                'operation' => $operation ?? 'unknown',
                'order_id' => $context['order']->id ?? null,
            ], 'Failed to synchronize with external API');

        } catch (\Exception $e) {
            Log::error('Unexpected error in API sync', [
                'error' => $e->getMessage(),
                'context' => $context,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->failure([
                'error' => 'Unexpected error occurred',
                'operation' => $operation ?? 'unknown',
            ], 'System error during API synchronization');
        }
    }

    private function createOrderInExternalSystem($order): array
    {
        $payload = [
            'order_number' => $order->number,
            'customer_id' => $order->customer_id,
            'total_amount' => $order->total,
            'currency' => $order->currency ?? 'USD',
            'items' => $order->items->map(function ($item) {
                return [
                    'sku' => $item->sku,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'name' => $item->name,
                ];
            })->toArray(),
            'shipping_address' => $order->shipping_address,
            'billing_address' => $order->billing_address,
            'created_at' => $order->created_at->toISOString(),
        ];

        $response = $this->apiClient->post('/orders', $payload);

        // Store external ID for future reference
        $order->update(['external_id' => $response['id']]);

        return $response;
    }

    private function updateOrderInExternalSystem($order): array
    {
        if (!$order->external_id) {
            throw new ApiException('Order not found in external system');
        }

        $payload = [
            'status' => $this->mapInternalStatusToExternal($order->state),
            'updated_at' => $order->updated_at->toISOString(),
        ];

        // Add additional fields based on current state
        if ($order->state === 'processing') {
            $payload['processing_started_at'] = now()->toISOString();
        } elseif ($order->state === 'shipped') {
            $payload['shipped_at'] = now()->toISOString();
            $payload['tracking_number'] = $order->tracking_number;
        }

        return $this->apiClient->put("/orders/{$order->external_id}", $payload);
    }

    private function cancelOrderInExternalSystem($order): array
    {
        if (!$order->external_id) {
            throw new ApiException('Order not found in external system');
        }

        $payload = [
            'status' => 'cancelled',
            'cancelled_at' => now()->toISOString(),
            'cancellation_reason' => $order->cancellation_reason ?? 'Customer request',
        ];

        return $this->apiClient->put("/orders/{$order->external_id}/cancel", $payload);
    }

    private function markOrderAsShipped($order): array
    {
        if (!$order->external_id) {
            throw new ApiException('Order not found in external system');
        }

        $payload = [
            'tracking_number' => $order->tracking_number,
            'carrier' => $order->carrier ?? 'Unknown',
            'shipped_at' => now()->toISOString(),
            'estimated_delivery_date' => $order->estimated_delivery_date?->toISOString(),
        ];

        return $this->apiClient->post("/orders/{$order->external_id}/ship", $payload);
    }

    private function mapInternalStatusToExternal(string $internalStatus): string
    {
        return match($internalStatus) {
            'pending' => 'pending',
            'processing' => 'processing',
            'shipped' => 'shipped',
            'delivered' => 'completed',
            'cancelled' => 'cancelled',
            default => 'unknown'
        };
    }

    protected function rules(): array
    {
        return [
            'order' => 'required',
            'operation' => 'required|string|in:create,update,cancel,ship',
        ];
    }
}

// 3. Payment Gateway API Integration
class PaymentGatewayApiClient extends RestApiClient
{
    public function __construct()
    {
        parent::__construct([
            'base_uri' => config('services.payment_gateway.base_url'),
            'timeout' => 60, // Payment operations may take longer
            'retry_attempts' => 2, // Fewer retries for financial operations
        ]);
    }

    public function processPayment(array $paymentData): array
    {
        $payload = [
            'amount' => $paymentData['amount'],
            'currency' => $paymentData['currency'] ?? 'USD',
            'payment_method' => $paymentData['payment_method'],
            'customer_id' => $paymentData['customer_id'],
            'order_id' => $paymentData['order_id'],
            'description' => $paymentData['description'] ?? 'Order payment',
            'metadata' => $paymentData['metadata'] ?? [],
        ];

        if (isset($paymentData['card_token'])) {
            $payload['card_token'] = $paymentData['card_token'];
        }

        return $this->post('/payments', $payload, [
            'headers' => [
                'Authorization' => 'Bearer ' . config('services.payment_gateway.api_key'),
                'Idempotency-Key' => $paymentData['idempotency_key'] ?? uniqid('pay_', true),
            ],
        ]);
    }

    public function refundPayment(string $paymentId, float $amount, string $reason = ''): array
    {
        $payload = [
            'amount' => $amount,
            'reason' => $reason,
        ];

        return $this->post("/payments/{$paymentId}/refunds", $payload, [
            'headers' => [
                'Authorization' => 'Bearer ' . config('services.payment_gateway.api_key'),
            ],
        ]);
    }

    public function getPaymentStatus(string $paymentId): array
    {
        return $this->get("/payments/{$paymentId}", [], [
            'headers' => [
                'Authorization' => 'Bearer ' . config('services.payment_gateway.api_key'),
            ],
        ]);
    }
}

// 4. Inventory Management API Integration
class InventoryApiClient extends RestApiClient
{
    public function __construct()
    {
        parent::__construct([
            'base_uri' => config('services.inventory_api.base_url'),
            'timeout' => 30,
            'retry_attempts' => 3,
        ]);
    }

    public function reserveInventory(array $items): array
    {
        $payload = [
            'items' => array_map(function ($item) {
                return [
                    'sku' => $item['sku'],
                    'quantity' => $item['quantity'],
                    'warehouse_id' => $item['warehouse_id'] ?? null,
                ];
            }, $items),
            'reservation_id' => uniqid('res_', true),
            'expires_at' => now()->addHours(2)->toISOString(),
        ];

        return $this->post('/inventory/reserve', $payload, [
            'headers' => [
                'Authorization' => 'Bearer ' . config('services.inventory_api.api_key'),
            ],
        ]);
    }

    public function commitReservation(string $reservationId): array
    {
        return $this->post("/inventory/reservations/{$reservationId}/commit", [], [
            'headers' => [
                'Authorization' => 'Bearer ' . config('services.inventory_api.api_key'),
            ],
        ]);
    }

    public function releaseReservation(string $reservationId): array
    {
        return $this->delete("/inventory/reservations/{$reservationId}", [
            'headers' => [
                'Authorization' => 'Bearer ' . config('services.inventory_api.api_key'),
            ],
        ]);
    }

    public function checkAvailability(array $skus): array
    {
        return $this->get('/inventory/availability', [
            'skus' => implode(',', $skus),
        ], [
            'headers' => [
                'Authorization' => 'Bearer ' . config('services.inventory_api.api_key'),
            ],
        ]);
    }
}

// 5. Shipping API Integration
class ShippingApiClient extends RestApiClient
{
    public function __construct()
    {
        parent::__construct([
            'base_uri' => config('services.shipping_api.base_url'),
            'timeout' => 45,
            'retry_attempts' => 3,
        ]);
    }

    public function createShipment(array $shipmentData): array
    {
        $payload = [
            'from_address' => $shipmentData['from_address'],
            'to_address' => $shipmentData['to_address'],
            'packages' => $shipmentData['packages'],
            'service_type' => $shipmentData['service_type'] ?? 'standard',
            'reference_number' => $shipmentData['order_number'],
        ];

        return $this->post('/shipments', $payload, [
            'headers' => [
                'Authorization' => 'Bearer ' . config('services.shipping_api.api_key'),
            ],
        ]);
    }

    public function getTrackingInfo(string $trackingNumber): array
    {
        return $this->get("/tracking/{$trackingNumber}", [], [
            'headers' => [
                'Authorization' => 'Bearer ' . config('services.shipping_api.api_key'),
            ],
        ]);
    }

    public function cancelShipment(string $shipmentId): array
    {
        return $this->delete("/shipments/{$shipmentId}", [
            'headers' => [
                'Authorization' => 'Bearer ' . config('services.shipping_api.api_key'),
            ],
        ]);
    }
}

// 6. Multi-API Workflow Action
class MultiApiWorkflowAction extends BaseAction
{
    private PaymentGatewayApiClient $paymentClient;
    private InventoryApiClient $inventoryClient;
    private ShippingApiClient $shippingClient;

    public function __construct()
    {
        $this->paymentClient = new PaymentGatewayApiClient();
        $this->inventoryClient = new InventoryApiClient();
        $this->shippingClient = new ShippingApiClient();
    }

    public function execute(array $context = []): ActionResult
    {
        $order = $context['order'];
        $operation = $context['operation'];

        try {
            $results = match($operation) {
                'process_order' => $this->processCompleteOrder($order),
                'cancel_order' => $this->cancelCompleteOrder($order),
                'ship_order' => $this->shipCompleteOrder($order),
                default => throw new \InvalidArgumentException("Unsupported operation: {$operation}")
            };

            return $this->success($results, 'Multi-API operation completed successfully');

        } catch (\Exception $e) {
            Log::error('Multi-API operation failed', [
                'order_id' => $order->id,
                'operation' => $operation,
                'error' => $e->getMessage(),
            ]);

            return $this->failure([
                'error' => $e->getMessage(),
                'operation' => $operation,
            ], 'Multi-API operation failed');
        }
    }

    private function processCompleteOrder($order): array
    {
        $results = [];

        // 1. Reserve inventory
        $inventoryResult = $this->inventoryClient->reserveInventory(
            $order->items->toArray()
        );
        $results['inventory'] = $inventoryResult;

        // 2. Process payment
        $paymentResult = $this->paymentClient->processPayment([
            'amount' => $order->total,
            'currency' => $order->currency,
            'payment_method' => $order->payment_method,
            'customer_id' => $order->customer_id,
            'order_id' => $order->id,
            'card_token' => $order->payment_token,
        ]);
        $results['payment'] = $paymentResult;

        // 3. Commit inventory reservation
        $commitResult = $this->inventoryClient->commitReservation(
            $inventoryResult['reservation_id']
        );
        $results['inventory_commit'] = $commitResult;

        // Update order with external references
        $order->update([
            'payment_id' => $paymentResult['id'],
            'inventory_reservation_id' => $inventoryResult['reservation_id'],
        ]);

        return $results;
    }

    private function cancelCompleteOrder($order): array
    {
        $results = [];

        // 1. Refund payment if exists
        if ($order->payment_id) {
            $refundResult = $this->paymentClient->refundPayment(
                $order->payment_id,
                $order->total,
                'Order cancelled'
            );
            $results['refund'] = $refundResult;
        }

        // 2. Release inventory reservation
        if ($order->inventory_reservation_id) {
            $releaseResult = $this->inventoryClient->releaseReservation(
                $order->inventory_reservation_id
            );
            $results['inventory_release'] = $releaseResult;
        }

        // 3. Cancel shipment if exists
        if ($order->shipment_id) {
            $cancelShipmentResult = $this->shippingClient->cancelShipment(
                $order->shipment_id
            );
            $results['shipment_cancel'] = $cancelShipmentResult;
        }

        return $results;
    }

    private function shipCompleteOrder($order): array
    {
        $results = [];

        // Create shipment
        $shipmentResult = $this->shippingClient->createShipment([
            'from_address' => config('shipping.warehouse_address'),
            'to_address' => $order->shipping_address,
            'packages' => $this->getPackageData($order),
            'service_type' => $order->shipping_method,
            'order_number' => $order->number,
        ]);
        $results['shipment'] = $shipmentResult;

        // Update order with tracking information
        $order->update([
            'shipment_id' => $shipmentResult['id'],
            'tracking_number' => $shipmentResult['tracking_number'],
            'shipped_at' => now(),
        ]);

        return $results;
    }

    private function getPackageData($order): array
    {
        return [
            [
                'weight' => $order->total_weight ?? 1.0,
                'dimensions' => [
                    'length' => 12,
                    'width' => 12,
                    'height' => 6,
                ],
                'description' => "Order #{$order->number}",
            ]
        ];
    }

    protected function rules(): array
    {
        return [
            'order' => 'required',
            'operation' => 'required|string|in:process_order,cancel_order,ship_order',
        ];
    }
}

// 7. API Health Monitor
class ApiHealthMonitor
{
    private array $apis;

    public function __construct()
    {
        $this->apis = [
            'payment' => new PaymentGatewayApiClient(),
            'inventory' => new InventoryApiClient(),
            'shipping' => new ShippingApiClient(),
        ];
    }

    public function checkAllApis(): array
    {
        $results = [];

        foreach ($this->apis as $name => $client) {
            $results[$name] = $this->checkApiHealth($name, $client);
        }

        return $results;
    }

    private function checkApiHealth(string $name, RestApiClient $client): array
    {
        try {
            $start = microtime(true);
            
            // Use health check endpoint if available, otherwise use a simple GET
            $response = $client->get('/health');
            
            $duration = microtime(true) - $start;

            return [
                'status' => 'healthy',
                'response_time' => round($duration * 1000, 2), // ms
                'last_checked' => now()->toISOString(),
                'details' => $response,
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'last_checked' => now()->toISOString(),
            ];
        }
    }
}

// 8. Custom Exceptions
class ApiException extends \Exception {}
class RateLimitException extends \Exception {}

// 9. Usage Examples
class RestApiUsageExamples
{
    public function syncOrderOnStateChange($order, string $toState): void
    {
        $operation = match($toState) {
            'processing' => 'update',
            'shipped' => 'ship',
            'cancelled' => 'cancel',
            default => 'update'
        };

        $action = new SyncOrderWithExternalApiAction();
        $result = $action->execute([
            'order' => $order,
            'operation' => $operation,
        ]);

        if (!$result->isSuccessful()) {
            Log::error('Order sync failed', [
                'order_id' => $order->id,
                'operation' => $operation,
                'error' => $result->getMessage(),
            ]);
        }
    }

    public function processOrderWithMultipleApis($order): void
    {
        $action = new MultiApiWorkflowAction();
        $result = $action->execute([
            'order' => $order,
            'operation' => 'process_order',
        ]);

        if ($result->isSuccessful()) {
            Log::info('Order processed successfully', [
                'order_id' => $order->id,
                'results' => $result->getData(),
            ]);
        }
    }
}
