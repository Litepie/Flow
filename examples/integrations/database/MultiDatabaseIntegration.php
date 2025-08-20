<?php

namespace App\Examples\Integrations\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Schema;
use Litepie\Flow\Events\WorkflowTransitioned;
use Litepie\Actions\BaseAction;
use Litepie\Actions\Contracts\ActionResult;
use Illuminate\Database\Eloquent\Model;

/**
 * Multi-Database Operations Integration with Litepie Flow
 * 
 * This example demonstrates how to integrate workflow transitions with
 * multi-database operations, cross-database transactions, and data synchronization.
 */

// 1. Multi-Database Transaction Manager
class MultiDatabaseTransactionManager
{
    private array $connections = [];
    private array $activeTransactions = [];

    public function __construct(array $connectionNames = [])
    {
        $this->connections = array_map(
            fn($name) => DB::connection($name),
            $connectionNames ?: ['mysql', 'pgsql', 'mongodb']
        );
    }

    public function beginTransactions(): void
    {
        foreach ($this->connections as $name => $connection) {
            try {
                $connection->beginTransaction();
                $this->activeTransactions[$name] = true;
            } catch (\Exception $e) {
                // Rollback any transactions that were started
                $this->rollbackTransactions();
                throw new DatabaseTransactionException(
                    "Failed to begin transaction on connection {$name}: " . $e->getMessage()
                );
            }
        }
    }

    public function commitTransactions(): void
    {
        $commitErrors = [];

        foreach ($this->connections as $name => $connection) {
            if ($this->activeTransactions[$name] ?? false) {
                try {
                    $connection->commit();
                    unset($this->activeTransactions[$name]);
                } catch (\Exception $e) {
                    $commitErrors[$name] = $e->getMessage();
                }
            }
        }

        if (!empty($commitErrors)) {
            // If any commits failed, we need to handle the inconsistent state
            $this->handlePartialCommitFailure($commitErrors);
            throw new DatabaseTransactionException(
                'Some database commits failed: ' . json_encode($commitErrors)
            );
        }
    }

    public function rollbackTransactions(): void
    {
        foreach ($this->connections as $name => $connection) {
            if ($this->activeTransactions[$name] ?? false) {
                try {
                    $connection->rollBack();
                } catch (\Exception $e) {
                    // Log rollback failure but continue with other connections
                    logger()->error("Failed to rollback transaction on {$name}", [
                        'error' => $e->getMessage(),
                    ]);
                }
                unset($this->activeTransactions[$name]);
            }
        }
    }

    private function handlePartialCommitFailure(array $commitErrors): void
    {
        // Log the partial failure for manual intervention
        logger()->critical('Partial commit failure in multi-database transaction', [
            'commit_errors' => $commitErrors,
            'active_transactions' => $this->activeTransactions,
            'timestamp' => now()->toISOString(),
        ]);

        // Could implement compensation logic here
        // For example, create compensating transactions
    }

    public function executeInTransaction(callable $callback)
    {
        $this->beginTransactions();

        try {
            $result = $callback($this->connections);
            $this->commitTransactions();
            return $result;
        } catch (\Exception $e) {
            $this->rollbackTransactions();
            throw $e;
        }
    }
}

// 2. Cross-Database Order Processing Action
class CrossDatabaseOrderProcessingAction extends BaseAction
{
    private MultiDatabaseTransactionManager $transactionManager;

    public function __construct()
    {
        $this->transactionManager = new MultiDatabaseTransactionManager([
            'orders', // Primary order database
            'inventory', // Inventory database
            'accounting', // Accounting database
            'analytics' // Analytics database
        ]);
    }

    public function execute(array $context = []): ActionResult
    {
        $order = $context['order'];

        try {
            $result = $this->transactionManager->executeInTransaction(
                function ($connections) use ($order) {
                    return $this->processOrderAcrossDatabases($order, $connections);
                }
            );

            return $this->success($result, 'Order processed across all databases successfully');

        } catch (DatabaseTransactionException $e) {
            return $this->failure([
                'error' => $e->getMessage(),
                'order_id' => $order->id,
            ], 'Cross-database transaction failed');

        } catch (\Exception $e) {
            return $this->failure([
                'error' => $e->getMessage(),
                'order_id' => $order->id,
            ], 'Order processing failed');
        }
    }

    private function processOrderAcrossDatabases($order, array $connections): array
    {
        $results = [];

        // 1. Update order status in primary database
        $results['order_update'] = $this->updateOrderStatus($order, $connections['orders']);

        // 2. Reserve inventory in inventory database
        $results['inventory_reservation'] = $this->reserveInventory($order, $connections['inventory']);

        // 3. Create accounting entries
        $results['accounting_entries'] = $this->createAccountingEntries($order, $connections['accounting']);

        // 4. Update analytics data
        $results['analytics_update'] = $this->updateAnalytics($order, $connections['analytics']);

        return $results;
    }

    private function updateOrderStatus($order, Connection $connection): array
    {
        $updated = $connection->table('orders')
            ->where('id', $order->id)
            ->update([
                'status' => 'processing',
                'processing_started_at' => now(),
                'updated_at' => now(),
            ]);

        return [
            'updated_rows' => $updated,
            'new_status' => 'processing',
        ];
    }

    private function reserveInventory($order, Connection $connection): array
    {
        $reservations = [];

        foreach ($order->items as $item) {
            // Check current inventory
            $currentStock = $connection->table('inventory')
                ->where('sku', $item->sku)
                ->value('available_quantity');

            if ($currentStock < $item->quantity) {
                throw new InsufficientInventoryException(
                    "Insufficient inventory for SKU {$item->sku}"
                );
            }

            // Reserve inventory
            $connection->table('inventory')
                ->where('sku', $item->sku)
                ->decrement('available_quantity', $item->quantity);

            // Create reservation record
            $reservationId = $connection->table('inventory_reservations')->insertGetId([
                'order_id' => $order->id,
                'sku' => $item->sku,
                'quantity' => $item->quantity,
                'expires_at' => now()->addHours(2),
                'created_at' => now(),
            ]);

            $reservations[] = [
                'reservation_id' => $reservationId,
                'sku' => $item->sku,
                'quantity' => $item->quantity,
            ];
        }

        return $reservations;
    }

    private function createAccountingEntries($order, Connection $connection): array
    {
        $entries = [];

        // Revenue entry
        $revenueEntryId = $connection->table('accounting_entries')->insertGetId([
            'account_code' => '4000', // Revenue account
            'order_id' => $order->id,
            'description' => "Revenue for order #{$order->number}",
            'debit_amount' => 0,
            'credit_amount' => $order->total,
            'created_at' => now(),
        ]);

        $entries[] = [
            'type' => 'revenue',
            'entry_id' => $revenueEntryId,
            'amount' => $order->total,
        ];

        // Accounts receivable entry
        $arEntryId = $connection->table('accounting_entries')->insertGetId([
            'account_code' => '1200', // Accounts receivable
            'order_id' => $order->id,
            'description' => "A/R for order #{$order->number}",
            'debit_amount' => $order->total,
            'credit_amount' => 0,
            'created_at' => now(),
        ]);

        $entries[] = [
            'type' => 'accounts_receivable',
            'entry_id' => $arEntryId,
            'amount' => $order->total,
        ];

        return $entries;
    }

    private function updateAnalytics($order, Connection $connection): array
    {
        // Update daily sales summary
        $connection->table('daily_sales_summary')
            ->updateOrInsert(
                [
                    'date' => now()->toDateString(),
                    'customer_segment' => $order->customer->segment ?? 'regular',
                ],
                [
                    'order_count' => DB::raw('order_count + 1'),
                    'total_revenue' => DB::raw("total_revenue + {$order->total}"),
                    'updated_at' => now(),
                ]
            );

        // Update product performance metrics
        foreach ($order->items as $item) {
            $connection->table('product_performance')
                ->updateOrInsert(
                    [
                        'sku' => $item->sku,
                        'date' => now()->toDateString(),
                    ],
                    [
                        'quantity_sold' => DB::raw("quantity_sold + {$item->quantity}"),
                        'revenue' => DB::raw("revenue + {$item->total}"),
                        'updated_at' => now(),
                    ]
                );
        }

        return [
            'daily_summary_updated' => true,
            'product_metrics_updated' => count($order->items),
        ];
    }

    protected function rules(): array
    {
        return [
            'order' => 'required',
            'order.id' => 'required|integer',
            'order.items' => 'required|array|min:1',
        ];
    }
}

// 3. Data Warehouse Synchronization
class DataWarehouseSyncAction extends BaseAction
{
    private Connection $sourceConnection;
    private Connection $warehouseConnection;

    public function __construct()
    {
        $this->sourceConnection = DB::connection('mysql');
        $this->warehouseConnection = DB::connection('warehouse');
    }

    public function execute(array $context = []): ActionResult
    {
        $syncType = $context['sync_type'] ?? 'incremental';
        $lastSyncTime = $context['last_sync_time'] ?? now()->subHour();

        try {
            $result = match($syncType) {
                'full' => $this->performFullSync(),
                'incremental' => $this->performIncrementalSync($lastSyncTime),
                'delta' => $this->performDeltaSync($lastSyncTime),
                default => throw new \InvalidArgumentException("Invalid sync type: {$syncType}")
            };

            return $this->success($result, 'Data warehouse sync completed successfully');

        } catch (\Exception $e) {
            return $this->failure([
                'error' => $e->getMessage(),
                'sync_type' => $syncType,
            ], 'Data warehouse sync failed');
        }
    }

    private function performIncrementalSync($lastSyncTime): array
    {
        $results = [];

        // Sync orders
        $orders = $this->sourceConnection->table('orders')
            ->where('updated_at', '>', $lastSyncTime)
            ->get();

        foreach ($orders->chunk(100) as $chunk) {
            $this->syncOrdersToWarehouse($chunk);
        }
        $results['orders_synced'] = $orders->count();

        // Sync customers
        $customers = $this->sourceConnection->table('customers')
            ->where('updated_at', '>', $lastSyncTime)
            ->get();

        foreach ($customers->chunk(100) as $chunk) {
            $this->syncCustomersToWarehouse($chunk);
        }
        $results['customers_synced'] = $customers->count();

        // Sync inventory transactions
        $inventoryTransactions = $this->sourceConnection->table('inventory_transactions')
            ->where('created_at', '>', $lastSyncTime)
            ->get();

        foreach ($inventoryTransactions->chunk(100) as $chunk) {
            $this->syncInventoryToWarehouse($chunk);
        }
        $results['inventory_transactions_synced'] = $inventoryTransactions->count();

        return $results;
    }

    private function syncOrdersToWarehouse($orders): void
    {
        $warehouseOrders = $orders->map(function ($order) {
            return [
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'order_date' => $order->created_at,
                'order_amount' => $order->total,
                'order_status' => $order->status,
                'payment_method' => $order->payment_method,
                'shipping_method' => $order->shipping_method,
                'customer_segment' => $this->getCustomerSegment($order->customer_id),
                'synced_at' => now(),
            ];
        })->toArray();

        $this->warehouseConnection->table('fact_orders')->upsert(
            $warehouseOrders,
            ['order_id'],
            ['order_amount', 'order_status', 'payment_method', 'shipping_method', 'synced_at']
        );
    }

    private function syncCustomersToWarehouse($customers): void
    {
        $warehouseCustomers = $customers->map(function ($customer) {
            return [
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'customer_email' => $customer->email,
                'customer_segment' => $customer->segment ?? 'regular',
                'registration_date' => $customer->created_at,
                'last_order_date' => $this->getLastOrderDate($customer->id),
                'total_orders' => $this->getTotalOrders($customer->id),
                'lifetime_value' => $this->getLifetimeValue($customer->id),
                'synced_at' => now(),
            ];
        })->toArray();

        $this->warehouseConnection->table('dim_customers')->upsert(
            $warehouseCustomers,
            ['customer_id'],
            ['customer_name', 'customer_email', 'customer_segment', 'last_order_date', 'total_orders', 'lifetime_value', 'synced_at']
        );
    }

    private function syncInventoryToWarehouse($transactions): void
    {
        $warehouseTransactions = $transactions->map(function ($transaction) {
            return [
                'transaction_id' => $transaction->id,
                'sku' => $transaction->sku,
                'transaction_type' => $transaction->type,
                'quantity_change' => $transaction->quantity_change,
                'transaction_date' => $transaction->created_at,
                'reference_id' => $transaction->reference_id,
                'reference_type' => $transaction->reference_type,
                'synced_at' => now(),
            ];
        })->toArray();

        $this->warehouseConnection->table('fact_inventory_transactions')->insert(
            $warehouseTransactions
        );
    }

    private function getCustomerSegment(int $customerId): string
    {
        // This could be a complex calculation
        $totalSpent = $this->sourceConnection->table('orders')
            ->where('customer_id', $customerId)
            ->sum('total');

        return match(true) {
            $totalSpent > 10000 => 'vip',
            $totalSpent > 5000 => 'premium',
            $totalSpent > 1000 => 'regular',
            default => 'new'
        };
    }

    private function getLastOrderDate(int $customerId)
    {
        return $this->sourceConnection->table('orders')
            ->where('customer_id', $customerId)
            ->max('created_at');
    }

    private function getTotalOrders(int $customerId): int
    {
        return $this->sourceConnection->table('orders')
            ->where('customer_id', $customerId)
            ->count();
    }

    private function getLifetimeValue(int $customerId): float
    {
        return $this->sourceConnection->table('orders')
            ->where('customer_id', $customerId)
            ->sum('total') ?? 0.0;
    }

    protected function rules(): array
    {
        return [
            'sync_type' => 'required|string|in:full,incremental,delta',
            'last_sync_time' => 'nullable|date',
        ];
    }
}

// 4. Database Schema Sync Manager
class DatabaseSchemaSyncManager
{
    private array $connections;

    public function __construct(array $connectionNames = [])
    {
        $this->connections = $connectionNames ?: ['primary', 'replica1', 'replica2'];
    }

    public function syncSchemaChanges(string $migrationPath): array
    {
        $results = [];

        foreach ($this->connections as $connectionName) {
            try {
                $connection = DB::connection($connectionName);
                $result = $this->applyMigration($connection, $migrationPath);
                $results[$connectionName] = [
                    'success' => true,
                    'result' => $result,
                ];
            } catch (\Exception $e) {
                $results[$connectionName] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    private function applyMigration(Connection $connection, string $migrationPath): array
    {
        // This is a simplified example
        // In practice, you'd use Laravel's migration system
        
        $sql = file_get_contents($migrationPath);
        $statements = array_filter(array_map('trim', explode(';', $sql)));

        $executed = [];
        foreach ($statements as $statement) {
            if (!empty($statement)) {
                $connection->statement($statement);
                $executed[] = $statement;
            }
        }

        return [
            'executed_statements' => count($executed),
            'statements' => $executed,
        ];
    }

    public function verifySchemaConsistency(): array
    {
        $schemas = [];

        foreach ($this->connections as $connectionName) {
            $connection = DB::connection($connectionName);
            $schemas[$connectionName] = $this->getSchemaInfo($connection);
        }

        return $this->compareSchemas($schemas);
    }

    private function getSchemaInfo(Connection $connection): array
    {
        $tables = [];
        
        $tableNames = Schema::connection($connection->getName())->getTableListing();
        
        foreach ($tableNames as $tableName) {
            $columns = Schema::connection($connection->getName())->getColumnListing($tableName);
            $tables[$tableName] = $columns;
        }

        return $tables;
    }

    private function compareSchemas(array $schemas): array
    {
        $inconsistencies = [];
        $baseSchema = reset($schemas);
        $baseConnection = key($schemas);

        foreach ($schemas as $connectionName => $schema) {
            if ($connectionName === $baseConnection) continue;

            $diff = array_diff_assoc($schema, $baseSchema);
            if (!empty($diff)) {
                $inconsistencies[$connectionName] = $diff;
            }
        }

        return [
            'consistent' => empty($inconsistencies),
            'base_connection' => $baseConnection,
            'inconsistencies' => $inconsistencies,
        ];
    }
}

// 5. Workflow Event Listener for Database Operations
class DatabaseWorkflowListener
{
    private MultiDatabaseTransactionManager $transactionManager;

    public function __construct()
    {
        $this->transactionManager = new MultiDatabaseTransactionManager();
    }

    public function handle(WorkflowTransitioned $event): void
    {
        $subject = $event->getSubject();
        $toState = $event->getToState();
        $context = $event->getContext();

        try {
            $this->performDatabaseOperations($subject, $toState, $context);
        } catch (\Exception $e) {
            logger()->error('Database workflow operations failed', [
                'subject_type' => get_class($subject),
                'subject_id' => $subject->getKey(),
                'to_state' => $toState,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function performDatabaseOperations($subject, string $toState, array $context): void
    {
        // Execute different database operations based on state
        match($toState) {
            'processing' => $this->handleProcessingState($subject, $context),
            'shipped' => $this->handleShippedState($subject, $context),
            'delivered' => $this->handleDeliveredState($subject, $context),
            'cancelled' => $this->handleCancelledState($subject, $context),
            default => null
        };
    }

    private function handleProcessingState($subject, array $context): void
    {
        if ($subject instanceof Order) {
            $action = new CrossDatabaseOrderProcessingAction();
            $action->execute(['order' => $subject]);
        }
    }

    private function handleShippedState($subject, array $context): void
    {
        if ($subject instanceof Order) {
            // Update shipping database
            DB::connection('shipping')->table('shipments')->insert([
                'order_id' => $subject->id,
                'tracking_number' => $subject->tracking_number,
                'carrier' => $subject->carrier,
                'shipped_at' => now(),
            ]);

            // Sync to data warehouse
            $syncAction = new DataWarehouseSyncAction();
            $syncAction->execute([
                'sync_type' => 'incremental',
                'last_sync_time' => now()->subMinutes(5),
            ]);
        }
    }

    private function handleDeliveredState($subject, array $context): void
    {
        if ($subject instanceof Order) {
            // Update delivery confirmation in multiple databases
            $this->transactionManager->executeInTransaction(function ($connections) use ($subject) {
                // Update order status
                $connections['orders']->table('orders')
                    ->where('id', $subject->id)
                    ->update([
                        'delivered_at' => now(),
                        'status' => 'delivered',
                    ]);

                // Update customer satisfaction database
                $connections['crm']->table('customer_interactions')->insert([
                    'customer_id' => $subject->customer_id,
                    'type' => 'order_delivered',
                    'order_id' => $subject->id,
                    'created_at' => now(),
                ]);

                // Update analytics
                $connections['analytics']->table('delivery_metrics')
                    ->updateOrInsert(
                        ['date' => now()->toDateString()],
                        [
                            'deliveries_count' => DB::raw('deliveries_count + 1'),
                            'updated_at' => now(),
                        ]
                    );
            });
        }
    }

    private function handleCancelledState($subject, array $context): void
    {
        if ($subject instanceof Order) {
            // Handle cancellation across databases
            $this->transactionManager->executeInTransaction(function ($connections) use ($subject) {
                // Release inventory
                foreach ($subject->items as $item) {
                    $connections['inventory']->table('inventory')
                        ->where('sku', $item->sku)
                        ->increment('available_quantity', $item->quantity);
                }

                // Create refund entry
                $connections['accounting']->table('refunds')->insert([
                    'order_id' => $subject->id,
                    'amount' => $subject->total,
                    'reason' => $context['cancellation_reason'] ?? 'Customer request',
                    'created_at' => now(),
                ]);

                // Update analytics
                $connections['analytics']->table('cancellation_metrics')
                    ->updateOrInsert(
                        ['date' => now()->toDateString()],
                        [
                            'cancellations_count' => DB::raw('cancellations_count + 1'),
                            'cancelled_revenue' => DB::raw("cancelled_revenue + {$subject->total}"),
                            'updated_at' => now(),
                        ]
                    );
            });
        }
    }
}

// 6. Custom Exceptions
class DatabaseTransactionException extends \Exception {}
class InsufficientInventoryException extends \Exception {}

// 7. Usage Examples
class DatabaseIntegrationUsageExamples
{
    public function processOrderWithMultipleDatabases($order): void
    {
        $action = new CrossDatabaseOrderProcessingAction();
        $result = $action->execute(['order' => $order]);

        if ($result->isSuccessful()) {
            logger()->info('Cross-database order processing completed', [
                'order_id' => $order->id,
                'results' => $result->getData(),
            ]);
        }
    }

    public function syncToDataWarehouse(): void
    {
        $action = new DataWarehouseSyncAction();
        $result = $action->execute([
            'sync_type' => 'incremental',
            'last_sync_time' => now()->subHour(),
        ]);

        if ($result->isSuccessful()) {
            logger()->info('Data warehouse sync completed', $result->getData());
        }
    }

    public function verifyDatabaseConsistency(): void
    {
        $schemaManager = new DatabaseSchemaSyncManager(['primary', 'replica1', 'replica2']);
        $consistencyCheck = $schemaManager->verifySchemaConsistency();

        if (!$consistencyCheck['consistent']) {
            logger()->warning('Database schema inconsistencies detected', $consistencyCheck);
        }
    }
}
