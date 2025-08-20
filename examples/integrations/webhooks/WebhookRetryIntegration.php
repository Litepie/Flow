<?php

namespace App\Examples\Integrations\Webhooks;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Carbon\Carbon;

/**
 * Webhook Retry Integration with Litepie Flow
 * 
 * This example demonstrates advanced webhook retry mechanisms including
 * exponential backoff, circuit breakers, and dead letter queues.
 */

// 1. Webhook Retry Manager
class WebhookRetryManager
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'max_attempts' => 5,
            'initial_delay' => 1, // seconds
            'max_delay' => 300, // 5 minutes
            'backoff_multiplier' => 2,
            'jitter' => true,
            'dead_letter_queue' => 'webhook-dlq',
            'circuit_breaker_threshold' => 5,
            'circuit_breaker_timeout' => 600, // 10 minutes
        ], $config);
    }

    public function scheduleRetry(WebhookDeliveryAttempt $attempt): void
    {
        if ($attempt->getAttemptNumber() >= $this->config['max_attempts']) {
            $this->moveToDeadLetterQueue($attempt);
            return;
        }

        // Check circuit breaker
        if ($this->isCircuitBreakerOpen($attempt->getWebhookUrl())) {
            Log::warning('Circuit breaker is open, delaying webhook retry', [
                'webhook_id' => $attempt->getWebhookId(),
                'url' => $attempt->getWebhookUrl(),
            ]);
            
            $this->scheduleCircuitBreakerRetry($attempt);
            return;
        }

        $delay = $this->calculateDelay($attempt->getAttemptNumber());
        
        // Schedule the retry job
        WebhookRetryJob::dispatch($attempt)
            ->delay(now()->addSeconds($delay))
            ->onQueue('webhook-retries');

        // Log the retry scheduling
        $this->logRetryScheduled($attempt, $delay);
    }

    public function recordFailure(string $webhookId, string $url, string $error): void
    {
        $failureKey = "webhook_failures:{$url}";
        $failures = Cache::increment($failureKey, 1);
        Cache::expire($failureKey, 3600); // Reset counter after 1 hour

        // Check if we should open the circuit breaker
        if ($failures >= $this->config['circuit_breaker_threshold']) {
            $this->openCircuitBreaker($url);
        }

        // Log the failure
        DB::table('webhook_failure_logs')->insert([
            'webhook_id' => $webhookId,
            'url' => $url,
            'error_message' => $error,
            'failure_count' => $failures,
            'created_at' => now(),
        ]);
    }

    public function recordSuccess(string $webhookId, string $url): void
    {
        // Reset failure counter on success
        Cache::forget("webhook_failures:{$url}");
        
        // Close circuit breaker if it was open
        $this->closeCircuitBreaker($url);

        // Log the success
        DB::table('webhook_success_logs')->insert([
            'webhook_id' => $webhookId,
            'url' => $url,
            'created_at' => now(),
        ]);
    }

    private function calculateDelay(int $attemptNumber): int
    {
        $delay = $this->config['initial_delay'] * pow($this->config['backoff_multiplier'], $attemptNumber - 1);
        $delay = min($delay, $this->config['max_delay']);

        // Add jitter to prevent thundering herd
        if ($this->config['jitter']) {
            $jitter = rand(0, (int) ($delay * 0.1));
            $delay += $jitter;
        }

        return (int) $delay;
    }

    private function isCircuitBreakerOpen(string $url): bool
    {
        $breakerKey = "circuit_breaker:{$url}";
        return Cache::has($breakerKey);
    }

    private function openCircuitBreaker(string $url): void
    {
        $breakerKey = "circuit_breaker:{$url}";
        Cache::put($breakerKey, true, $this->config['circuit_breaker_timeout']);

        Log::warning('Circuit breaker opened for webhook URL', [
            'url' => $url,
            'timeout' => $this->config['circuit_breaker_timeout'],
        ]);
    }

    private function closeCircuitBreaker(string $url): void
    {
        $breakerKey = "circuit_breaker:{$url}";
        Cache::forget($breakerKey);

        Log::info('Circuit breaker closed for webhook URL', [
            'url' => $url,
        ]);
    }

    private function scheduleCircuitBreakerRetry(WebhookDeliveryAttempt $attempt): void
    {
        // Schedule retry after circuit breaker timeout
        WebhookRetryJob::dispatch($attempt)
            ->delay(now()->addSeconds($this->config['circuit_breaker_timeout']))
            ->onQueue('webhook-circuit-breaker');
    }

    private function moveToDeadLetterQueue(WebhookDeliveryAttempt $attempt): void
    {
        WebhookDeadLetterJob::dispatch($attempt)
            ->onQueue($this->config['dead_letter_queue']);

        Log::error('Webhook moved to dead letter queue', [
            'webhook_id' => $attempt->getWebhookId(),
            'url' => $attempt->getWebhookUrl(),
            'attempts' => $attempt->getAttemptNumber(),
        ]);

        // Record in database
        DB::table('webhook_dead_letters')->insert([
            'webhook_id' => $attempt->getWebhookId(),
            'url' => $attempt->getWebhookUrl(),
            'payload' => json_encode($attempt->getPayload()),
            'total_attempts' => $attempt->getAttemptNumber(),
            'last_error' => $attempt->getLastError(),
            'created_at' => now(),
        ]);
    }

    private function logRetryScheduled(WebhookDeliveryAttempt $attempt, int $delay): void
    {
        DB::table('webhook_retry_logs')->insert([
            'webhook_id' => $attempt->getWebhookId(),
            'url' => $attempt->getWebhookUrl(),
            'attempt_number' => $attempt->getAttemptNumber() + 1,
            'delay_seconds' => $delay,
            'scheduled_at' => now(),
            'retry_at' => now()->addSeconds($delay),
        ]);

        Log::info('Webhook retry scheduled', [
            'webhook_id' => $attempt->getWebhookId(),
            'url' => $attempt->getWebhookUrl(),
            'attempt' => $attempt->getAttemptNumber() + 1,
            'delay' => $delay,
        ]);
    }
}

// 2. Webhook Delivery Attempt
class WebhookDeliveryAttempt
{
    public function __construct(
        private string $webhookId,
        private string $url,
        private array $payload,
        private array $headers,
        private int $attemptNumber,
        private ?string $lastError = null,
        private ?Carbon $firstAttemptAt = null
    ) {
        $this->firstAttemptAt = $firstAttemptAt ?: now();
    }

    public static function create(WebhookPayload $payload): self
    {
        return new self(
            webhookId: $payload->getId(),
            url: $payload->getUrl(),
            payload: $payload->getData(),
            headers: $payload->getHeaders(),
            attemptNumber: 1
        );
    }

    public function nextAttempt(?string $error = null): self
    {
        return new self(
            webhookId: $this->webhookId,
            url: $this->url,
            payload: $this->payload,
            headers: $this->headers,
            attemptNumber: $this->attemptNumber + 1,
            lastError: $error,
            firstAttemptAt: $this->firstAttemptAt
        );
    }

    // Getters
    public function getWebhookId(): string { return $this->webhookId; }
    public function getWebhookUrl(): string { return $this->url; }
    public function getPayload(): array { return $this->payload; }
    public function getHeaders(): array { return $this->headers; }
    public function getAttemptNumber(): int { return $this->attemptNumber; }
    public function getLastError(): ?string { return $this->lastError; }
    public function getFirstAttemptAt(): Carbon { return $this->firstAttemptAt; }
    public function getAge(): int { return now()->diffInSeconds($this->firstAttemptAt); }
}

// 3. Webhook Retry Job
class WebhookRetryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 1; // Only one try per job, retries are handled by retry manager
    public int $timeout = 60;

    public function __construct(
        private WebhookDeliveryAttempt $attempt
    ) {}

    public function handle(WebhookDeliveryService $deliveryService, WebhookRetryManager $retryManager): void
    {
        try {
            Log::info('Attempting webhook delivery', [
                'webhook_id' => $this->attempt->getWebhookId(),
                'url' => $this->attempt->getWebhookUrl(),
                'attempt' => $this->attempt->getAttemptNumber(),
            ]);

            // Create payload from attempt data
            $payload = new WebhookPayload(
                $this->attempt->getWebhookId(),
                $this->attempt->getWebhookUrl(),
                'retry',
                $this->attempt->getPayload(),
                config('webhooks.secret'), // You might want to store this per webhook
                $this->attempt->getHeaders()
            );

            // Attempt delivery
            $result = $deliveryService->deliverWebhook($payload);

            if ($result->isSuccessful()) {
                $retryManager->recordSuccess($this->attempt->getWebhookId(), $this->attempt->getWebhookUrl());
                
                Log::info('Webhook retry successful', [
                    'webhook_id' => $this->attempt->getWebhookId(),
                    'url' => $this->attempt->getWebhookUrl(),
                    'attempt' => $this->attempt->getAttemptNumber(),
                    'status_code' => $result->getStatusCode(),
                ]);
            } else {
                $this->handleFailure($result->getError(), $retryManager);
            }

        } catch (\Exception $e) {
            $this->handleFailure($e->getMessage(), $retryManager);
        }
    }

    private function handleFailure(string $error, WebhookRetryManager $retryManager): void
    {
        Log::warning('Webhook retry failed', [
            'webhook_id' => $this->attempt->getWebhookId(),
            'url' => $this->attempt->getWebhookUrl(),
            'attempt' => $this->attempt->getAttemptNumber(),
            'error' => $error,
        ]);

        $retryManager->recordFailure($this->attempt->getWebhookId(), $this->attempt->getWebhookUrl(), $error);

        $nextAttempt = $this->attempt->nextAttempt($error);
        $retryManager->scheduleRetry($nextAttempt);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Webhook retry job failed catastrophically', [
            'webhook_id' => $this->attempt->getWebhookId(),
            'url' => $this->attempt->getWebhookUrl(),
            'attempt' => $this->attempt->getAttemptNumber(),
            'exception' => $exception->getMessage(),
        ]);
    }
}

// 4. Dead Letter Queue Job
class WebhookDeadLetterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 1;
    public int $timeout = 30;

    public function __construct(
        private WebhookDeliveryAttempt $attempt
    ) {}

    public function handle(): void
    {
        Log::error('Webhook in dead letter queue', [
            'webhook_id' => $this->attempt->getWebhookId(),
            'url' => $this->attempt->getWebhookUrl(),
            'total_attempts' => $this->attempt->getAttemptNumber(),
            'last_error' => $this->attempt->getLastError(),
            'age_seconds' => $this->attempt->getAge(),
        ]);

        // Here you might want to:
        // 1. Send alert to administrators
        // 2. Save to a database for manual inspection
        // 3. Send to an external monitoring system
        
        $this->notifyAdministrators();
    }

    private function notifyAdministrators(): void
    {
        // Send notification to administrators about failed webhook
        $message = sprintf(
            'Webhook %s failed after %d attempts. URL: %s, Last error: %s',
            $this->attempt->getWebhookId(),
            $this->attempt->getAttemptNumber(),
            $this->attempt->getWebhookUrl(),
            $this->attempt->getLastError()
        );

        // You could integrate with your notification system here
        Log::critical('Webhook failed permanently', [
            'message' => $message,
            'webhook_id' => $this->attempt->getWebhookId(),
        ]);
    }
}

// 5. Webhook Health Monitor
class WebhookHealthMonitor
{
    public function getHealthMetrics(): array
    {
        $last24Hours = now()->subDay();

        return [
            'total_attempts' => $this->getTotalAttempts($last24Hours),
            'successful_deliveries' => $this->getSuccessfulDeliveries($last24Hours),
            'failed_deliveries' => $this->getFailedDeliveries($last24Hours),
            'dead_letter_count' => $this->getDeadLetterCount($last24Hours),
            'circuit_breakers_open' => $this->getOpenCircuitBreakers(),
            'average_retry_count' => $this->getAverageRetryCount($last24Hours),
            'top_failing_urls' => $this->getTopFailingUrls($last24Hours),
        ];
    }

    private function getTotalAttempts(Carbon $since): int
    {
        return DB::table('webhook_delivery_logs')
            ->where('created_at', '>=', $since)
            ->count();
    }

    private function getSuccessfulDeliveries(Carbon $since): int
    {
        return DB::table('webhook_success_logs')
            ->where('created_at', '>=', $since)
            ->count();
    }

    private function getFailedDeliveries(Carbon $since): int
    {
        return DB::table('webhook_failure_logs')
            ->where('created_at', '>=', $since)
            ->count();
    }

    private function getDeadLetterCount(Carbon $since): int
    {
        return DB::table('webhook_dead_letters')
            ->where('created_at', '>=', $since)
            ->count();
    }

    private function getOpenCircuitBreakers(): array
    {
        // This would require storing circuit breaker state in database
        // For now, we'll return an empty array
        return [];
    }

    private function getAverageRetryCount(Carbon $since): float
    {
        $retries = DB::table('webhook_retry_logs')
            ->where('scheduled_at', '>=', $since)
            ->groupBy('webhook_id')
            ->selectRaw('webhook_id, MAX(attempt_number) as max_attempts')
            ->get();

        if ($retries->isEmpty()) {
            return 0;
        }

        return $retries->avg('max_attempts');
    }

    private function getTopFailingUrls(Carbon $since, int $limit = 10): array
    {
        return DB::table('webhook_failure_logs')
            ->where('created_at', '>=', $since)
            ->groupBy('url')
            ->selectRaw('url, COUNT(*) as failure_count')
            ->orderByDesc('failure_count')
            ->limit($limit)
            ->get()
            ->toArray();
    }
}

// 6. Webhook Retry Configuration
class WebhookRetryConfiguration
{
    public static function getDefaultConfig(): array
    {
        return [
            'max_attempts' => 5,
            'initial_delay' => 1,
            'max_delay' => 300,
            'backoff_multiplier' => 2,
            'jitter' => true,
            'dead_letter_queue' => 'webhook-dlq',
            'circuit_breaker_threshold' => 5,
            'circuit_breaker_timeout' => 600,
        ];
    }

    public static function getConfigForUrl(string $url): array
    {
        // You could customize retry behavior per URL
        $customConfigs = [
            'critical-partner.com' => [
                'max_attempts' => 10,
                'circuit_breaker_threshold' => 10,
            ],
            'slow-service.com' => [
                'max_delay' => 600,
                'initial_delay' => 5,
            ],
        ];

        $domain = parse_url($url, PHP_URL_HOST);
        $default = self::getDefaultConfig();

        return array_merge($default, $customConfigs[$domain] ?? []);
    }
}

// 7. Manual Retry Management
class WebhookManualRetryManager
{
    public function retryDeadLetterWebhook(int $deadLetterId): bool
    {
        $deadLetter = DB::table('webhook_dead_letters')->find($deadLetterId);
        
        if (!$deadLetter) {
            return false;
        }

        // Create a new attempt
        $attempt = new WebhookDeliveryAttempt(
            webhookId: $deadLetter->webhook_id,
            url: $deadLetter->url,
            payload: json_decode($deadLetter->payload, true),
            headers: [],
            attemptNumber: 1
        );

        // Schedule immediate retry
        WebhookRetryJob::dispatch($attempt)->onQueue('webhook-manual-retries');

        // Log manual retry
        Log::info('Manual webhook retry initiated', [
            'dead_letter_id' => $deadLetterId,
            'webhook_id' => $deadLetter->webhook_id,
            'url' => $deadLetter->url,
        ]);

        return true;
    }

    public function retryAllDeadLetters(): int
    {
        $deadLetters = DB::table('webhook_dead_letters')
            ->where('retried_at', null)
            ->get();

        $count = 0;
        foreach ($deadLetters as $deadLetter) {
            if ($this->retryDeadLetterWebhook($deadLetter->id)) {
                $count++;
                
                // Mark as retried
                DB::table('webhook_dead_letters')
                    ->where('id', $deadLetter->id)
                    ->update(['retried_at' => now()]);
            }
        }

        return $count;
    }

    public function clearCircuitBreaker(string $url): void
    {
        $breakerKey = "circuit_breaker:{$url}";
        Cache::forget($breakerKey);
        Cache::forget("webhook_failures:{$url}");

        Log::info('Circuit breaker manually cleared', ['url' => $url]);
    }
}

// 8. Usage Examples
class WebhookRetryUsageExamples
{
    public function __construct(
        private WebhookRetryManager $retryManager,
        private WebhookHealthMonitor $healthMonitor,
        private WebhookManualRetryManager $manualRetryManager
    ) {}

    public function handleFailedWebhook(): void
    {
        // Create a failed delivery attempt
        $payload = new WebhookPayload(
            id: 'webhook-123',
            url: 'https://api.example.com/webhooks',
            eventType: 'order.created',
            data: ['order_id' => 123],
            secret: 'secret-key'
        );

        $attempt = WebhookDeliveryAttempt::create($payload);
        
        // Schedule retry
        $this->retryManager->scheduleRetry($attempt);
        
        echo "Webhook retry scheduled\n";
    }

    public function checkWebhookHealth(): void
    {
        $metrics = $this->healthMonitor->getHealthMetrics();
        
        echo "Webhook Health Metrics:\n";
        echo "Total attempts: {$metrics['total_attempts']}\n";
        echo "Successful deliveries: {$metrics['successful_deliveries']}\n";
        echo "Failed deliveries: {$metrics['failed_deliveries']}\n";
        echo "Dead letter count: {$metrics['dead_letter_count']}\n";
        echo "Average retry count: {$metrics['average_retry_count']}\n";
    }

    public function retryFailedWebhooks(): void
    {
        $retriedCount = $this->manualRetryManager->retryAllDeadLetters();
        echo "Retried {$retriedCount} dead letter webhooks\n";
    }

    public function clearCircuitBreaker(): void
    {
        $this->manualRetryManager->clearCircuitBreaker('https://problematic-api.com');
        echo "Circuit breaker cleared\n";
    }
}
