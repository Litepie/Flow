<?php

namespace App\Examples\Integrations\Microservices;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Litepie\Flow\Events\WorkflowTransitioned;
use Litepie\Actions\BaseAction;
use Litepie\Actions\Contracts\ActionResult;
use Carbon\Carbon;

/**
 * Microservices Integration with Litepie Flow
 * 
 * This example demonstrates how to integrate workflow transitions with
 * microservices architecture including service discovery, load balancing,
 * and distributed transactions.
 */

// 1. Service Registry
class ServiceRegistry
{
    private array $services = [];

    public function __construct()
    {
        $this->loadServices();
    }

    public function registerService(string $name, ServiceEndpoint $endpoint): void
    {
        if (!isset($this->services[$name])) {
            $this->services[$name] = [];
        }

        $this->services[$name][] = $endpoint;
        
        // Store in cache for persistence
        Cache::put("service_registry", $this->services, 300);
        
        Log::info('Service registered', [
            'service' => $name,
            'endpoint' => $endpoint->getUrl(),
            'health_check' => $endpoint->getHealthCheckUrl(),
        ]);
    }

    public function discoverService(string $name): ?ServiceEndpoint
    {
        if (!isset($this->services[$name])) {
            return null;
        }

        $healthyEndpoints = array_filter(
            $this->services[$name],
            fn(ServiceEndpoint $endpoint) => $this->isHealthy($endpoint)
        );

        if (empty($healthyEndpoints)) {
            Log::warning('No healthy endpoints available for service', ['service' => $name]);
            return null;
        }

        // Simple round-robin load balancing
        return $this->selectEndpoint($healthyEndpoints);
    }

    public function getAllServices(): array
    {
        return array_keys($this->services);
    }

    public function getServiceEndpoints(string $name): array
    {
        return $this->services[$name] ?? [];
    }

    private function loadServices(): void
    {
        $this->services = Cache::get("service_registry", []);
        
        // Load from configuration if cache is empty
        if (empty($this->services)) {
            $this->loadFromConfig();
        }
    }

    private function loadFromConfig(): void
    {
        $config = config('microservices.services', []);
        
        foreach ($config as $serviceName => $endpoints) {
            foreach ($endpoints as $endpointConfig) {
                $endpoint = new ServiceEndpoint(
                    url: $endpointConfig['url'],
                    healthCheckUrl: $endpointConfig['health_check'] ?? null,
                    weight: $endpointConfig['weight'] ?? 1,
                    metadata: $endpointConfig['metadata'] ?? []
                );
                
                $this->registerService($serviceName, $endpoint);
            }
        }
    }

    private function isHealthy(ServiceEndpoint $endpoint): bool
    {
        $healthCheckUrl = $endpoint->getHealthCheckUrl();
        
        if (!$healthCheckUrl) {
            return true; // Assume healthy if no health check URL
        }

        $cacheKey = "health_check:" . md5($healthCheckUrl);
        $cachedResult = Cache::get($cacheKey);
        
        if ($cachedResult !== null) {
            return $cachedResult;
        }

        try {
            $response = Http::timeout(5)->get($healthCheckUrl);
            $isHealthy = $response->successful();
            
            // Cache health check result for 30 seconds
            Cache::put($cacheKey, $isHealthy, 30);
            
            return $isHealthy;
            
        } catch (\Exception $e) {
            Log::warning('Health check failed', [
                'endpoint' => $healthCheckUrl,
                'error' => $e->getMessage(),
            ]);
            
            Cache::put($cacheKey, false, 30);
            return false;
        }
    }

    private function selectEndpoint(array $endpoints): ServiceEndpoint
    {
        // Weighted random selection
        $totalWeight = array_sum(array_map(fn($e) => $e->getWeight(), $endpoints));
        $random = rand(1, $totalWeight);
        
        $weightSum = 0;
        foreach ($endpoints as $endpoint) {
            $weightSum += $endpoint->getWeight();
            if ($random <= $weightSum) {
                return $endpoint;
            }
        }
        
        return $endpoints[0]; // Fallback
    }
}

// 2. Service Endpoint
class ServiceEndpoint
{
    public function __construct(
        private string $url,
        private ?string $healthCheckUrl = null,
        private int $weight = 1,
        private array $metadata = []
    ) {}

    public function getUrl(): string { return $this->url; }
    public function getHealthCheckUrl(): ?string { return $this->healthCheckUrl; }
    public function getWeight(): int { return $this->weight; }
    public function getMetadata(): array { return $this->metadata; }
}

// 3. Microservice Client
class MicroserviceClient
{
    private ServiceRegistry $serviceRegistry;
    private array $config;

    public function __construct(ServiceRegistry $serviceRegistry, array $config = [])
    {
        $this->serviceRegistry = $serviceRegistry;
        $this->config = array_merge([
            'timeout' => 30,
            'retry_attempts' => 3,
            'retry_delay' => [1, 3, 5],
            'circuit_breaker' => true,
        ], $config);
    }

    public function call(string $serviceName, string $method, string $path, array $data = [], array $headers = []): MicroserviceResponse
    {
        $endpoint = $this->serviceRegistry->discoverService($serviceName);
        
        if (!$endpoint) {
            return new MicroserviceResponse(
                success: false,
                statusCode: 503,
                data: null,
                error: "Service unavailable: {$serviceName}"
            );
        }

        // Check circuit breaker
        if ($this->config['circuit_breaker'] && $this->isCircuitBreakerOpen($serviceName)) {
            return new MicroserviceResponse(
                success: false,
                statusCode: 503,
                data: null,
                error: "Circuit breaker open for service: {$serviceName}"
            );
        }

        return $this->executeWithRetry($endpoint, $serviceName, $method, $path, $data, $headers);
    }

    private function executeWithRetry(
        ServiceEndpoint $endpoint,
        string $serviceName,
        string $method,
        string $path,
        array $data,
        array $headers
    ): MicroserviceResponse {
        $lastException = null;
        
        for ($attempt = 1; $attempt <= $this->config['retry_attempts']; $attempt++) {
            try {
                $response = $this->makeRequest($endpoint, $method, $path, $data, $headers);
                
                // Reset circuit breaker on success
                $this->recordSuccess($serviceName);
                
                return new MicroserviceResponse(
                    success: $response->successful(),
                    statusCode: $response->status(),
                    data: $response->json(),
                    error: $response->successful() ? null : $response->body()
                );
                
            } catch (\Exception $e) {
                $lastException = $e;
                
                if ($attempt < $this->config['retry_attempts']) {
                    $delay = $this->config['retry_delay'][$attempt - 1] ?? 5;
                    sleep($delay);
                }
            }
        }

        // Record failure for circuit breaker
        $this->recordFailure($serviceName);

        return new MicroserviceResponse(
            success: false,
            statusCode: 0,
            data: null,
            error: $lastException ? $lastException->getMessage() : 'Unknown error'
        );
    }

    private function makeRequest(
        ServiceEndpoint $endpoint,
        string $method,
        string $path,
        array $data,
        array $headers
    ): \Illuminate\Http\Client\Response {
        $url = rtrim($endpoint->getUrl(), '/') . '/' . ltrim($path, '/');
        
        $defaultHeaders = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Request-ID' => (string) \Str::uuid(),
            'X-Service-Source' => config('app.name'),
        ];

        $allHeaders = array_merge($defaultHeaders, $headers);

        return Http::withHeaders($allHeaders)
            ->timeout($this->config['timeout'])
            ->$method($url, $data);
    }

    private function isCircuitBreakerOpen(string $serviceName): bool
    {
        return Cache::has("circuit_breaker:{$serviceName}");
    }

    private function recordSuccess(string $serviceName): void
    {
        Cache::forget("circuit_breaker:{$serviceName}");
        Cache::forget("service_failures:{$serviceName}");
    }

    private function recordFailure(string $serviceName): void
    {
        $failures = Cache::increment("service_failures:{$serviceName}", 1);
        Cache::expire("service_failures:{$serviceName}", 300); // 5 minutes

        // Open circuit breaker after 5 failures
        if ($failures >= 5) {
            Cache::put("circuit_breaker:{$serviceName}", true, 60); // 1 minute
            
            Log::warning('Circuit breaker opened for service', [
                'service' => $serviceName,
                'failures' => $failures,
            ]);
        }
    }
}

// 4. Microservice Response
class MicroserviceResponse
{
    public function __construct(
        private bool $success,
        private int $statusCode,
        private mixed $data,
        private ?string $error = null
    ) {}

    public function isSuccessful(): bool { return $this->success; }
    public function getStatusCode(): int { return $this->statusCode; }
    public function getData(): mixed { return $this->data; }
    public function getError(): ?string { return $this->error; }
}

// 5. Distributed Transaction Manager
class DistributedTransactionManager
{
    private MicroserviceClient $client;
    private array $participants = [];

    public function __construct(MicroserviceClient $client)
    {
        $this->client = $client;
    }

    public function beginTransaction(string $transactionId): DistributedTransaction
    {
        $transaction = new DistributedTransaction($transactionId, $this->client);
        
        // Store transaction state
        Cache::put("transaction:{$transactionId}", [
            'id' => $transactionId,
            'status' => 'active',
            'participants' => [],
            'created_at' => now(),
        ], 3600); // 1 hour timeout

        Log::info('Distributed transaction started', [
            'transaction_id' => $transactionId,
        ]);

        return $transaction;
    }

    public function getTransaction(string $transactionId): ?DistributedTransaction
    {
        $data = Cache::get("transaction:{$transactionId}");
        
        if (!$data) {
            return null;
        }

        $transaction = new DistributedTransaction($transactionId, $this->client);
        $transaction->setParticipants($data['participants']);
        
        return $transaction;
    }
}

// 6. Distributed Transaction
class DistributedTransaction
{
    private array $participants = [];
    private string $status = 'active';

    public function __construct(
        private string $id,
        private MicroserviceClient $client
    ) {}

    public function addParticipant(string $serviceName, string $operation, array $data): void
    {
        $this->participants[] = [
            'service' => $serviceName,
            'operation' => $operation,
            'data' => $data,
            'status' => 'pending',
        ];

        $this->updateTransactionState();
    }

    public function commit(): bool
    {
        // Phase 1: Prepare all participants
        foreach ($this->participants as $index => $participant) {
            $response = $this->client->call(
                $participant['service'],
                'POST',
                '/transactions/prepare',
                [
                    'transaction_id' => $this->id,
                    'operation' => $participant['operation'],
                    'data' => $participant['data'],
                ]
            );

            if (!$response->isSuccessful()) {
                $this->participants[$index]['status'] = 'prepare_failed';
                $this->updateTransactionState();
                
                // Abort the transaction
                $this->abort();
                return false;
            }

            $this->participants[$index]['status'] = 'prepared';
        }

        // Phase 2: Commit all participants
        $allCommitted = true;
        foreach ($this->participants as $index => $participant) {
            $response = $this->client->call(
                $participant['service'],
                'POST',
                '/transactions/commit',
                ['transaction_id' => $this->id]
            );

            if ($response->isSuccessful()) {
                $this->participants[$index]['status'] = 'committed';
            } else {
                $this->participants[$index]['status'] = 'commit_failed';
                $allCommitted = false;
            }
        }

        $this->status = $allCommitted ? 'committed' : 'partially_committed';
        $this->updateTransactionState();

        Log::info('Distributed transaction completed', [
            'transaction_id' => $this->id,
            'status' => $this->status,
            'participants' => count($this->participants),
        ]);

        return $allCommitted;
    }

    public function abort(): void
    {
        foreach ($this->participants as $index => $participant) {
            if (in_array($participant['status'], ['prepared', 'prepare_failed'])) {
                $response = $this->client->call(
                    $participant['service'],
                    'POST',
                    '/transactions/abort',
                    ['transaction_id' => $this->id]
                );

                $this->participants[$index]['status'] = 'aborted';
            }
        }

        $this->status = 'aborted';
        $this->updateTransactionState();

        Log::info('Distributed transaction aborted', [
            'transaction_id' => $this->id,
        ]);
    }

    public function setParticipants(array $participants): void
    {
        $this->participants = $participants;
    }

    private function updateTransactionState(): void
    {
        Cache::put("transaction:{$this->id}", [
            'id' => $this->id,
            'status' => $this->status,
            'participants' => $this->participants,
            'updated_at' => now(),
        ], 3600);
    }
}

// 7. Microservice Workflow Action
class MicroserviceWorkflowAction extends BaseAction
{
    public function __construct(
        private MicroserviceClient $client,
        private DistributedTransactionManager $transactionManager
    ) {}

    public function execute(array $context = []): ActionResult
    {
        $event = $context['event'];
        $serviceCalls = $context['service_calls'] ?? [];
        $useTransaction = $context['use_transaction'] ?? false;

        try {
            if ($useTransaction) {
                return $this->executeWithTransaction($event, $serviceCalls);
            } else {
                return $this->executeWithoutTransaction($event, $serviceCalls);
            }
        } catch (\Exception $e) {
            return $this->failure([
                'error' => $e->getMessage(),
                'service_calls' => $serviceCalls,
            ], 'Microservice workflow action failed');
        }
    }

    private function executeWithTransaction($event, array $serviceCalls): ActionResult
    {
        $transactionId = (string) \Str::uuid();
        $transaction = $this->transactionManager->beginTransaction($transactionId);

        foreach ($serviceCalls as $call) {
            $transaction->addParticipant(
                $call['service'],
                $call['operation'],
                $this->prepareServiceData($event, $call['data'] ?? [])
            );
        }

        $success = $transaction->commit();

        return $this->result($success, [
            'transaction_id' => $transactionId,
            'service_calls' => count($serviceCalls),
            'committed' => $success,
        ], $success ? 'Transaction completed successfully' : 'Transaction failed');
    }

    private function executeWithoutTransaction($event, array $serviceCalls): ActionResult
    {
        $results = [];
        $allSuccessful = true;

        foreach ($serviceCalls as $call) {
            $response = $this->client->call(
                $call['service'],
                $call['method'] ?? 'POST',
                $call['path'],
                $this->prepareServiceData($event, $call['data'] ?? []),
                $call['headers'] ?? []
            );

            $results[] = [
                'service' => $call['service'],
                'path' => $call['path'],
                'success' => $response->isSuccessful(),
                'status_code' => $response->getStatusCode(),
                'data' => $response->getData(),
                'error' => $response->getError(),
            ];

            if (!$response->isSuccessful()) {
                $allSuccessful = false;
            }
        }

        return $this->result($allSuccessful, [
            'service_calls' => $results,
            'successful_calls' => count(array_filter($results, fn($r) => $r['success'])),
            'failed_calls' => count(array_filter($results, fn($r) => !$r['success'])),
        ], $allSuccessful ? 'All service calls successful' : 'Some service calls failed');
    }

    private function prepareServiceData($event, array $additionalData = []): array
    {
        $subject = $event->getSubject();
        
        return array_merge([
            'event' => [
                'type' => 'workflow.transitioned',
                'workflow_name' => $event->getWorkflowName(),
                'from_state' => $event->getFromState(),
                'to_state' => $event->getToState(),
                'transition_event' => $event->getTransitionEvent(),
            ],
            'entity' => [
                'type' => get_class($subject),
                'id' => $subject->getKey(),
                'attributes' => $subject->toArray(),
            ],
            'context' => $event->getContext(),
            'timestamp' => now()->toISOString(),
        ], $additionalData);
    }

    protected function rules(): array
    {
        return [
            'event' => 'required',
            'service_calls' => 'array',
            'use_transaction' => 'boolean',
        ];
    }
}

// 8. Microservice Event Publisher
class MicroserviceEventPublisher
{
    public function __construct(
        private MicroserviceClient $client,
        private ServiceRegistry $serviceRegistry
    ) {}

    public function publishEvent(WorkflowTransitioned $event): void
    {
        $eventServices = $this->getEventSubscribers('workflow.transitioned');
        
        foreach ($eventServices as $service) {
            $this->publishToService($service, $event);
        }
    }

    private function getEventSubscribers(string $eventType): array
    {
        // Get services that subscribe to this event type
        $subscribers = [];
        
        foreach ($this->serviceRegistry->getAllServices() as $serviceName) {
            $endpoints = $this->serviceRegistry->getServiceEndpoints($serviceName);
            
            foreach ($endpoints as $endpoint) {
                $metadata = $endpoint->getMetadata();
                $subscribedEvents = $metadata['subscribed_events'] ?? [];
                
                if (in_array($eventType, $subscribedEvents)) {
                    $subscribers[] = $serviceName;
                    break; // Only add service once
                }
            }
        }
        
        return $subscribers;
    }

    private function publishToService(string $serviceName, WorkflowTransitioned $event): void
    {
        $response = $this->client->call(
            $serviceName,
            'POST',
            '/events/workflow-transitioned',
            $this->prepareEventData($event)
        );

        if ($response->isSuccessful()) {
            Log::info('Event published to microservice', [
                'service' => $serviceName,
                'event_type' => 'workflow.transitioned',
                'workflow' => $event->getWorkflowName(),
            ]);
        } else {
            Log::error('Failed to publish event to microservice', [
                'service' => $serviceName,
                'error' => $response->getError(),
                'status_code' => $response->getStatusCode(),
            ]);
        }
    }

    private function prepareEventData(WorkflowTransitioned $event): array
    {
        $subject = $event->getSubject();
        
        return [
            'event_id' => (string) \Str::uuid(),
            'event_type' => 'workflow.transitioned',
            'timestamp' => now()->toISOString(),
            'source_service' => config('app.name'),
            'data' => [
                'workflow_name' => $event->getWorkflowName(),
                'entity' => [
                    'type' => get_class($subject),
                    'id' => $subject->getKey(),
                ],
                'transition' => [
                    'from' => $event->getFromState(),
                    'to' => $event->getToState(),
                    'event' => $event->getTransitionEvent(),
                ],
                'context' => $event->getContext(),
            ],
        ];
    }
}

// 9. Usage Examples
class MicroserviceUsageExamples
{
    public function __construct(
        private ServiceRegistry $serviceRegistry,
        private MicroserviceClient $client,
        private DistributedTransactionManager $transactionManager
    ) {}

    public function setupServices(): void
    {
        // Register services
        $this->serviceRegistry->registerService('payment-service', new ServiceEndpoint(
            url: 'http://payment-service:8080',
            healthCheckUrl: 'http://payment-service:8080/health',
            weight: 1,
            metadata: ['subscribed_events' => ['workflow.transitioned']]
        ));

        $this->serviceRegistry->registerService('inventory-service', new ServiceEndpoint(
            url: 'http://inventory-service:8080',
            healthCheckUrl: 'http://inventory-service:8080/health',
            weight: 1,
            metadata: ['subscribed_events' => ['workflow.transitioned']]
        ));

        $this->serviceRegistry->registerService('notification-service', new ServiceEndpoint(
            url: 'http://notification-service:8080',
            healthCheckUrl: 'http://notification-service:8080/health',
            weight: 1
        ));

        echo "Services registered successfully\n";
    }

    public function callMicroservice(): void
    {
        $response = $this->client->call(
            'payment-service',
            'POST',
            '/payments/process',
            ['amount' => 100.00, 'currency' => 'USD']
        );

        if ($response->isSuccessful()) {
            echo "Payment processed: " . json_encode($response->getData()) . "\n";
        } else {
            echo "Payment failed: " . $response->getError() . "\n";
        }
    }

    public function executeDistributedTransaction(): void
    {
        $transactionId = (string) \Str::uuid();
        $transaction = $this->transactionManager->beginTransaction($transactionId);

        // Add participants
        $transaction->addParticipant('payment-service', 'charge', ['amount' => 100]);
        $transaction->addParticipant('inventory-service', 'reserve', ['item_id' => 123, 'quantity' => 2]);
        $transaction->addParticipant('notification-service', 'send', ['type' => 'order_confirmation']);

        // Commit transaction
        $success = $transaction->commit();

        echo "Transaction " . ($success ? "committed" : "failed") . "\n";
    }
}
