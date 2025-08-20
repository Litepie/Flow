<?php

namespace App\Examples\Integrations\Enterprise;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Litepie\Flow\Events\WorkflowTransitioned;
use Litepie\Actions\BaseAction;
use Litepie\Actions\Contracts\ActionResult;
use Carbon\Carbon;

/**
 * Enterprise System Integration with Litepie Flow
 * 
 * This example demonstrates integration with enterprise systems like SAP, Oracle EBS,
 * legacy mainframes, and enterprise service buses (ESB).
 */

// 1. Enterprise Service Bus (ESB) Integration
class ESBIntegration
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'esb_endpoint' => 'https://esb.enterprise.com/api',
            'auth_type' => 'oauth2', // oauth2, basic, certificate
            'timeout' => 60,
            'retry_attempts' => 3,
            'message_format' => 'xml', // xml, json, soap
        ], $config);
    }

    public function publishMessage(EnterpriseMessage $message): ESBResponse
    {
        try {
            $formattedMessage = $this->formatMessage($message);
            $response = $this->sendToESB($formattedMessage);
            
            $this->logMessage($message, $response, true);
            
            return new ESBResponse(
                success: $response->successful(),
                messageId: $response->json('messageId'),
                correlationId: $response->json('correlationId'),
                response: $response->json(),
                statusCode: $response->status()
            );
            
        } catch (\Exception $e) {
            $this->logMessage($message, null, false, $e->getMessage());
            
            return new ESBResponse(
                success: false,
                messageId: null,
                correlationId: null,
                response: null,
                statusCode: 0,
                error: $e->getMessage()
            );
        }
    }

    public function subscribeToTopic(string $topic, callable $handler): void
    {
        // In a real implementation, this would set up a message listener
        // For demonstration, we'll simulate polling
        $this->pollForMessages($topic, $handler);
    }

    private function formatMessage(EnterpriseMessage $message): array
    {
        $baseMessage = [
            'messageId' => $message->getId(),
            'timestamp' => $message->getTimestamp()->toISOString(),
            'source' => config('app.name'),
            'destination' => $message->getDestination(),
            'messageType' => $message->getType(),
            'correlationId' => $message->getCorrelationId(),
            'payload' => $message->getPayload(),
        ];

        if ($this->config['message_format'] === 'xml') {
            return $this->convertToXML($baseMessage);
        }

        return $baseMessage;
    }

    private function convertToXML(array $data): array
    {
        // Convert array to XML format for ESB
        $xml = new \SimpleXMLElement('<message/>');
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $this->arrayToXML($value, $xml->addChild($key));
            } else {
                $xml->addChild($key, htmlspecialchars($value));
            }
        }

        return ['xml' => $xml->asXML()];
    }

    private function arrayToXML(array $data, \SimpleXMLElement $xml): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $this->arrayToXML($value, $xml->addChild($key));
            } else {
                $xml->addChild($key, htmlspecialchars($value));
            }
        }
    }

    private function sendToESB(array $message): \Illuminate\Http\Client\Response
    {
        $headers = $this->getAuthHeaders();
        $headers['Content-Type'] = $this->config['message_format'] === 'xml' 
            ? 'application/xml' 
            : 'application/json';

        return Http::withHeaders($headers)
            ->timeout($this->config['timeout'])
            ->post($this->config['esb_endpoint'] . '/messages', $message);
    }

    private function getAuthHeaders(): array
    {
        return match($this->config['auth_type']) {
            'oauth2' => ['Authorization' => 'Bearer ' . $this->getOAuth2Token()],
            'basic' => ['Authorization' => 'Basic ' . $this->getBasicAuthToken()],
            'certificate' => [], // Certificate auth would be configured at HTTP client level
            default => []
        };
    }

    private function getOAuth2Token(): string
    {
        $cacheKey = 'esb_oauth2_token';
        $token = Cache::get($cacheKey);
        
        if (!$token) {
            $token = $this->fetchOAuth2Token();
            Cache::put($cacheKey, $token, 3000); // 50 minutes (tokens usually expire in 1 hour)
        }
        
        return $token;
    }

    private function fetchOAuth2Token(): string
    {
        $response = Http::post($this->config['esb_endpoint'] . '/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => config('esb.client_id'),
            'client_secret' => config('esb.client_secret'),
        ]);

        if (!$response->successful()) {
            throw new \Exception('Failed to obtain OAuth2 token: ' . $response->body());
        }

        return $response->json('access_token');
    }

    private function getBasicAuthToken(): string
    {
        $credentials = config('esb.username') . ':' . config('esb.password');
        return base64_encode($credentials);
    }

    private function pollForMessages(string $topic, callable $handler): void
    {
        // Simulate polling for incoming messages
        Log::info('Starting message polling for topic', ['topic' => $topic]);
        
        // In production, this would be an actual message consumer
        // For demonstration, we'll check for messages periodically
    }

    private function logMessage(EnterpriseMessage $message, $response, bool $success, ?string $error = null): void
    {
        DB::table('esb_message_logs')->insert([
            'message_id' => $message->getId(),
            'destination' => $message->getDestination(),
            'message_type' => $message->getType(),
            'success' => $success,
            'response' => $response ? json_encode($response->json()) : null,
            'error' => $error,
            'created_at' => now(),
        ]);
    }
}

// 2. Enterprise Message
class EnterpriseMessage
{
    public function __construct(
        private string $id,
        private string $type,
        private string $destination,
        private array $payload,
        private ?string $correlationId = null,
        private ?Carbon $timestamp = null
    ) {
        $this->timestamp = $timestamp ?: now();
        $this->correlationId = $correlationId ?: (string) \Str::uuid();
    }

    public static function fromWorkflowEvent(WorkflowTransitioned $event, string $destination): self
    {
        $subject = $event->getSubject();
        
        return new self(
            id: (string) \Str::uuid(),
            type: 'workflow.transitioned',
            destination: $destination,
            payload: [
                'workflow' => [
                    'name' => $event->getWorkflowName(),
                    'from_state' => $event->getFromState(),
                    'to_state' => $event->getToState(),
                    'event' => $event->getTransitionEvent(),
                ],
                'entity' => [
                    'type' => get_class($subject),
                    'id' => $subject->getKey(),
                    'attributes' => $subject->toArray(),
                ],
                'context' => $event->getContext(),
            ]
        );
    }

    // Getters
    public function getId(): string { return $this->id; }
    public function getType(): string { return $this->type; }
    public function getDestination(): string { return $this->destination; }
    public function getPayload(): array { return $this->payload; }
    public function getCorrelationId(): string { return $this->correlationId; }
    public function getTimestamp(): Carbon { return $this->timestamp; }
}

// 3. ESB Response
class ESBResponse
{
    public function __construct(
        private bool $success,
        private ?string $messageId,
        private ?string $correlationId,
        private mixed $response,
        private int $statusCode,
        private ?string $error = null
    ) {}

    public function isSuccessful(): bool { return $this->success; }
    public function getMessageId(): ?string { return $this->messageId; }
    public function getCorrelationId(): ?string { return $this->correlationId; }
    public function getResponse(): mixed { return $this->response; }
    public function getStatusCode(): int { return $this->statusCode; }
    public function getError(): ?string { return $this->error; }
}

// 4. SAP Integration
class SAPIntegration
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'rfc_host' => 'sap.enterprise.com',
            'rfc_system_number' => '00',
            'rfc_client' => '100',
            'rfc_user' => '',
            'rfc_password' => '',
            'rfc_language' => 'EN',
        ], $config);
    }

    public function createSalesOrder(array $orderData): SAPResponse
    {
        try {
            // Simulate SAP RFC call to create sales order
            $sapOrderNumber = $this->callSAPFunction('BAPI_SALESORDER_CREATEFROMDAT2', [
                'ORDER_HEADER_IN' => $this->formatOrderHeader($orderData),
                'ORDER_ITEMS_IN' => $this->formatOrderItems($orderData['items'] ?? []),
                'ORDER_PARTNERS' => $this->formatOrderPartners($orderData),
            ]);

            $this->logSAPCall('BAPI_SALESORDER_CREATEFROMDAT2', $orderData, $sapOrderNumber, true);

            return new SAPResponse(
                success: true,
                sapDocument: $sapOrderNumber,
                response: ['order_number' => $sapOrderNumber],
                message: 'Sales order created successfully'
            );

        } catch (\Exception $e) {
            $this->logSAPCall('BAPI_SALESORDER_CREATEFROMDAT2', $orderData, null, false, $e->getMessage());

            return new SAPResponse(
                success: false,
                sapDocument: null,
                response: null,
                message: 'Failed to create sales order',
                error: $e->getMessage()
            );
        }
    }

    public function updateOrderStatus(string $orderNumber, string $status): SAPResponse
    {
        try {
            $result = $this->callSAPFunction('BAPI_SALESORDER_CHANGE', [
                'SALESDOCUMENT' => $orderNumber,
                'ORDER_HEADER_INX' => [
                    'UPDATEFLAG' => 'U',
                ],
                'ORDER_STATUS' => $status,
            ]);

            $this->logSAPCall('BAPI_SALESORDER_CHANGE', ['order' => $orderNumber, 'status' => $status], $result, true);

            return new SAPResponse(
                success: true,
                sapDocument: $orderNumber,
                response: $result,
                message: 'Order status updated successfully'
            );

        } catch (\Exception $e) {
            $this->logSAPCall('BAPI_SALESORDER_CHANGE', ['order' => $orderNumber, 'status' => $status], null, false, $e->getMessage());

            return new SAPResponse(
                success: false,
                sapDocument: $orderNumber,
                response: null,
                message: 'Failed to update order status',
                error: $e->getMessage()
            );
        }
    }

    public function getCustomerInfo(string $customerCode): ?array
    {
        try {
            $result = $this->callSAPFunction('BAPI_CUSTOMER_GETDETAIL2', [
                'CUSTOMERNO' => $customerCode,
            ]);

            return $result['CUSTOMERADDRESS'] ?? null;

        } catch (\Exception $e) {
            Log::error('Failed to get SAP customer info', [
                'customer_code' => $customerCode,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function callSAPFunction(string $functionName, array $parameters): array
    {
        // In a real implementation, this would use SAP RFC connector
        // For demonstration, we'll simulate the call

        Log::info('SAP RFC call initiated', [
            'function' => $functionName,
            'parameters' => array_keys($parameters),
        ]);

        // Simulate processing time
        usleep(500000); // 500ms

        // Return simulated response
        return match($functionName) {
            'BAPI_SALESORDER_CREATEFROMDAT2' => [
                'SALESDOCUMENT' => 'SO' . rand(100000, 999999),
                'RETURN' => ['TYPE' => 'S', 'MESSAGE' => 'Sales order created'],
            ],
            'BAPI_SALESORDER_CHANGE' => [
                'RETURN' => ['TYPE' => 'S', 'MESSAGE' => 'Order updated'],
            ],
            'BAPI_CUSTOMER_GETDETAIL2' => [
                'CUSTOMERADDRESS' => [
                    'NAME' => 'Sample Customer',
                    'STREET' => '123 Main St',
                    'CITY' => 'Anytown',
                    'COUNTRY' => 'US',
                ],
            ],
            default => ['RETURN' => ['TYPE' => 'E', 'MESSAGE' => 'Function not implemented']],
        };
    }

    private function formatOrderHeader(array $orderData): array
    {
        return [
            'DOC_TYPE' => $orderData['document_type'] ?? 'OR',
            'SALES_ORG' => $orderData['sales_org'] ?? '1000',
            'DISTR_CHAN' => $orderData['distribution_channel'] ?? '10',
            'DIVISION' => $orderData['division'] ?? '00',
            'SOLD_TO_PARTY' => $orderData['customer_code'] ?? '',
            'PURCH_NO_C' => $orderData['purchase_order'] ?? '',
            'REQ_DATE_H' => $orderData['required_date'] ?? now()->format('Y-m-d'),
        ];
    }

    private function formatOrderItems(array $items): array
    {
        $sapItems = [];
        
        foreach ($items as $index => $item) {
            $sapItems[] = [
                'ITM_NUMBER' => str_pad($index + 1, 6, '0', STR_PAD_LEFT),
                'MATERIAL' => $item['sku'] ?? '',
                'TARGET_QTY' => $item['quantity'] ?? 1,
                'TARGET_QU' => $item['unit'] ?? 'EA',
                'ITEM_CATEG' => $item['category'] ?? 'TAN',
            ];
        }
        
        return $sapItems;
    }

    private function formatOrderPartners(array $orderData): array
    {
        return [
            [
                'PARTN_ROLE' => 'AG', // Sold-to party
                'PARTN_NUMB' => $orderData['customer_code'] ?? '',
            ],
            [
                'PARTN_ROLE' => 'WE', // Ship-to party
                'PARTN_NUMB' => $orderData['ship_to_code'] ?? $orderData['customer_code'] ?? '',
            ],
        ];
    }

    private function logSAPCall(string $function, array $input, $output, bool $success, ?string $error = null): void
    {
        DB::table('sap_integration_logs')->insert([
            'function_name' => $function,
            'input_data' => json_encode($input),
            'output_data' => json_encode($output),
            'success' => $success,
            'error_message' => $error,
            'execution_time' => 500, // Simulated
            'created_at' => now(),
        ]);
    }
}

// 5. SAP Response
class SAPResponse
{
    public function __construct(
        private bool $success,
        private ?string $sapDocument,
        private mixed $response,
        private string $message,
        private ?string $error = null
    ) {}

    public function isSuccessful(): bool { return $this->success; }
    public function getSapDocument(): ?string { return $this->sapDocument; }
    public function getResponse(): mixed { return $this->response; }
    public function getMessage(): string { return $this->message; }
    public function getError(): ?string { return $this->error; }
}

// 6. Legacy System Integration
class LegacySystemIntegration
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'connection_type' => 'file', // file, database, mq, socket
            'file_path' => '/var/legacy/interface',
            'polling_interval' => 60, // seconds
            'file_format' => 'fixed_width', // fixed_width, csv, xml
        ], $config);
    }

    public function sendToLegacySystem(array $data): bool
    {
        try {
            return match($this->config['connection_type']) {
                'file' => $this->writeToFile($data),
                'database' => $this->writeToDatabase($data),
                'mq' => $this->writeToMessageQueue($data),
                'socket' => $this->writeToSocket($data),
                default => throw new \InvalidArgumentException('Unsupported connection type'),
            };
        } catch (\Exception $e) {
            Log::error('Legacy system integration failed', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
            return false;
        }
    }

    public function readFromLegacySystem(): array
    {
        return match($this->config['connection_type']) {
            'file' => $this->readFromFile(),
            'database' => $this->readFromDatabase(),
            'mq' => $this->readFromMessageQueue(),
            'socket' => $this->readFromSocket(),
            default => [],
        };
    }

    private function writeToFile(array $data): bool
    {
        $filename = $this->config['file_path'] . '/outbound_' . date('YmdHis') . '.txt';
        $content = $this->formatForLegacySystem($data);
        
        return file_put_contents($filename, $content) !== false;
    }

    private function readFromFile(): array
    {
        $inboundPath = $this->config['file_path'] . '/inbound';
        $files = glob($inboundPath . '/*.txt');
        $results = [];
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $parsed = $this->parseFromLegacySystem($content);
            
            if ($parsed) {
                $results[] = $parsed;
                // Move processed file
                rename($file, $file . '.processed');
            }
        }
        
        return $results;
    }

    private function writeToDatabase(array $data): bool
    {
        // Write to legacy database table
        return DB::connection('legacy')->table('interface_outbound')->insert([
            'data' => json_encode($data),
            'status' => 'pending',
            'created_at' => now(),
        ]);
    }

    private function readFromDatabase(): array
    {
        return DB::connection('legacy')
            ->table('interface_inbound')
            ->where('status', 'pending')
            ->get()
            ->map(function ($record) {
                // Mark as processed
                DB::connection('legacy')
                    ->table('interface_inbound')
                    ->where('id', $record->id)
                    ->update(['status' => 'processed']);
                
                return json_decode($record->data, true);
            })
            ->toArray();
    }

    private function writeToMessageQueue(array $data): bool
    {
        // Implementation would depend on the specific MQ system (IBM MQ, MSMQ, etc.)
        Log::info('Writing to legacy message queue', ['data' => $data]);
        return true;
    }

    private function readFromMessageQueue(): array
    {
        // Implementation would depend on the specific MQ system
        Log::info('Reading from legacy message queue');
        return [];
    }

    private function writeToSocket(array $data): bool
    {
        // TCP/IP socket communication with legacy system
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        
        if (!$socket || !socket_connect($socket, $this->config['legacy_host'], $this->config['legacy_port'])) {
            return false;
        }
        
        $message = $this->formatForLegacySystem($data);
        socket_write($socket, $message);
        socket_close($socket);
        
        return true;
    }

    private function readFromSocket(): array
    {
        // Listen for incoming connections from legacy system
        return [];
    }

    private function formatForLegacySystem(array $data): string
    {
        return match($this->config['file_format']) {
            'fixed_width' => $this->formatFixedWidth($data),
            'csv' => $this->formatCSV($data),
            'xml' => $this->formatXML($data),
            default => json_encode($data),
        };
    }

    private function parseFromLegacySystem(string $content): ?array
    {
        return match($this->config['file_format']) {
            'fixed_width' => $this->parseFixedWidth($content),
            'csv' => $this->parseCSV($content),
            'xml' => $this->parseXML($content),
            default => json_decode($content, true),
        };
    }

    private function formatFixedWidth(array $data): string
    {
        // Format data according to fixed-width specification
        $lines = [];
        
        foreach ($data as $record) {
            $line = '';
            $line .= str_pad($record['id'] ?? '', 10, ' ', STR_PAD_LEFT);
            $line .= str_pad($record['type'] ?? '', 20, ' ', STR_PAD_RIGHT);
            $line .= str_pad($record['amount'] ?? '', 15, ' ', STR_PAD_LEFT);
            $line .= str_pad($record['date'] ?? '', 8, ' ', STR_PAD_RIGHT);
            $lines[] = $line;
        }
        
        return implode("\n", $lines);
    }

    private function parseFixedWidth(string $content): array
    {
        $lines = explode("\n", trim($content));
        $records = [];
        
        foreach ($lines as $line) {
            if (strlen($line) >= 53) { // Minimum expected length
                $records[] = [
                    'id' => trim(substr($line, 0, 10)),
                    'type' => trim(substr($line, 10, 20)),
                    'amount' => trim(substr($line, 30, 15)),
                    'date' => trim(substr($line, 45, 8)),
                ];
            }
        }
        
        return $records;
    }

    private function formatCSV(array $data): string
    {
        $output = fopen('php://temp', 'r+');
        
        foreach ($data as $record) {
            fputcsv($output, $record);
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }

    private function parseCSV(string $content): array
    {
        $lines = explode("\n", trim($content));
        $records = [];
        
        foreach ($lines as $line) {
            $parsed = str_getcsv($line);
            if (!empty($parsed)) {
                $records[] = $parsed;
            }
        }
        
        return $records;
    }

    private function formatXML(array $data): string
    {
        $xml = new \SimpleXMLElement('<root/>');
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $child = $xml->addChild('record');
                foreach ($value as $subKey => $subValue) {
                    $child->addChild($subKey, htmlspecialchars($subValue));
                }
            } else {
                $xml->addChild($key, htmlspecialchars($value));
            }
        }
        
        return $xml->asXML();
    }

    private function parseXML(string $content): array
    {
        try {
            $xml = simplexml_load_string($content);
            return json_decode(json_encode($xml), true);
        } catch (\Exception $e) {
            Log::error('Failed to parse XML from legacy system', ['error' => $e->getMessage()]);
            return [];
        }
    }
}

// 7. Enterprise Workflow Action
class EnterpriseWorkflowAction extends BaseAction
{
    public function __construct(
        private ESBIntegration $esb,
        private SAPIntegration $sap,
        private LegacySystemIntegration $legacy
    ) {}

    public function execute(array $context = []): ActionResult
    {
        $event = $context['event'];
        $integrations = $context['integrations'] ?? [];

        try {
            $results = [];

            foreach ($integrations as $integration) {
                $result = match($integration['type']) {
                    'esb' => $this->handleESBIntegration($event, $integration),
                    'sap' => $this->handleSAPIntegration($event, $integration),
                    'legacy' => $this->handleLegacyIntegration($event, $integration),
                    default => ['success' => false, 'error' => 'Unknown integration type'],
                };

                $results[] = array_merge($result, ['integration' => $integration['type']]);
            }

            $successCount = count(array_filter($results, fn($r) => $r['success']));
            $totalCount = count($results);

            return $this->success([
                'integrations_processed' => $totalCount,
                'successful_integrations' => $successCount,
                'failed_integrations' => $totalCount - $successCount,
                'results' => $results,
            ], "Processed {$totalCount} enterprise integrations");

        } catch (\Exception $e) {
            return $this->failure([
                'error' => $e->getMessage(),
                'integrations' => array_keys($integrations),
            ], 'Enterprise workflow action failed');
        }
    }

    private function handleESBIntegration($event, array $config): array
    {
        $message = EnterpriseMessage::fromWorkflowEvent($event, $config['destination']);
        $response = $this->esb->publishMessage($message);

        return [
            'success' => $response->isSuccessful(),
            'message_id' => $response->getMessageId(),
            'correlation_id' => $response->getCorrelationId(),
            'error' => $response->getError(),
        ];
    }

    private function handleSAPIntegration($event, array $config): array
    {
        $subject = $event->getSubject();
        
        return match($config['operation']) {
            'create_sales_order' => $this->createSAPSalesOrder($subject),
            'update_order_status' => $this->updateSAPOrderStatus($subject, $event->getToState()),
            default => ['success' => false, 'error' => 'Unknown SAP operation'],
        };
    }

    private function createSAPSalesOrder($order): array
    {
        $orderData = [
            'customer_code' => $order->customer_code,
            'purchase_order' => $order->purchase_order_number,
            'required_date' => $order->required_date,
            'items' => $order->items->map(function ($item) {
                return [
                    'sku' => $item->sku,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                ];
            })->toArray(),
        ];

        $response = $this->sap->createSalesOrder($orderData);

        if ($response->isSuccessful()) {
            // Update local order with SAP document number
            $order->update(['sap_order_number' => $response->getSapDocument()]);
        }

        return [
            'success' => $response->isSuccessful(),
            'sap_document' => $response->getSapDocument(),
            'message' => $response->getMessage(),
            'error' => $response->getError(),
        ];
    }

    private function updateSAPOrderStatus($order, string $newStatus): array
    {
        if (!$order->sap_order_number) {
            return [
                'success' => false,
                'error' => 'No SAP order number found',
            ];
        }

        $response = $this->sap->updateOrderStatus($order->sap_order_number, $newStatus);

        return [
            'success' => $response->isSuccessful(),
            'sap_document' => $response->getSapDocument(),
            'message' => $response->getMessage(),
            'error' => $response->getError(),
        ];
    }

    private function handleLegacyIntegration($event, array $config): array
    {
        $subject = $event->getSubject();
        
        $data = [
            'transaction_type' => $config['transaction_type'] ?? 'ORDER_UPDATE',
            'entity_id' => $subject->getKey(),
            'entity_type' => get_class($subject),
            'from_status' => $event->getFromState(),
            'to_status' => $event->getToState(),
            'timestamp' => now()->format('Y-m-d H:i:s'),
            'data' => $subject->toArray(),
        ];

        $success = $this->legacy->sendToLegacySystem([$data]);

        return [
            'success' => $success,
            'transaction_type' => $config['transaction_type'],
            'error' => $success ? null : 'Failed to send to legacy system',
        ];
    }

    protected function rules(): array
    {
        return [
            'event' => 'required',
            'integrations' => 'array',
        ];
    }
}

// 8. Usage Examples
class EnterpriseIntegrationUsageExamples
{
    public function __construct(
        private ESBIntegration $esb,
        private SAPIntegration $sap,
        private LegacySystemIntegration $legacy
    ) {}

    public function sendToESB(): void
    {
        $message = new EnterpriseMessage(
            id: (string) \Str::uuid(),
            type: 'order.created',
            destination: 'ORDER_PROCESSING_QUEUE',
            payload: [
                'order_id' => 12345,
                'customer_id' => 'CUST001',
                'total_amount' => 1500.00,
            ]
        );

        $response = $this->esb->publishMessage($message);

        if ($response->isSuccessful()) {
            echo "Message sent to ESB: {$response->getMessageId()}\n";
        } else {
            echo "ESB delivery failed: {$response->getError()}\n";
        }
    }

    public function createSAPOrder(): void
    {
        $orderData = [
            'customer_code' => 'CUST001',
            'purchase_order' => 'PO123456',
            'required_date' => '2024-01-15',
            'items' => [
                ['sku' => 'ITEM001', 'quantity' => 10, 'unit' => 'EA'],
                ['sku' => 'ITEM002', 'quantity' => 5, 'unit' => 'EA'],
            ],
        ];

        $response = $this->sap->createSalesOrder($orderData);

        if ($response->isSuccessful()) {
            echo "SAP Sales Order created: {$response->getSapDocument()}\n";
        } else {
            echo "SAP order creation failed: {$response->getError()}\n";
        }
    }

    public function sendToLegacySystem(): void
    {
        $data = [
            'transaction_type' => 'ORDER_CREATE',
            'order_id' => 12345,
            'customer_code' => 'CUST001',
            'order_total' => 1500.00,
            'order_date' => date('Y-m-d'),
        ];

        $success = $this->legacy->sendToLegacySystem([$data]);

        if ($success) {
            echo "Data sent to legacy system successfully\n";
        } else {
            echo "Failed to send data to legacy system\n";
        }
    }

    public function readFromLegacySystem(): void
    {
        $data = $this->legacy->readFromLegacySystem();

        echo "Read " . count($data) . " records from legacy system\n";
        
        foreach ($data as $record) {
            echo "Processed record: " . json_encode($record) . "\n";
        }
    }
}
