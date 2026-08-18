<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class MigrateSqliteBusinessDataToMariaDb extends Command
{
    protected $signature = 'data:migrate-sqlite-to-mariadb';

    protected $description = 'One-off migration of business data from SQLite to the empty MariaDB schema';

    private const SOURCE_CONNECTION = 'sqlite';

    private const TARGET_CONNECTION = 'migration_mysql';

    private const CHUNK_SIZE = 250;

    /**
     * Dependency-safe insertion order. The value is the table's identity key;
     * pivot tables use their existing composite unique key.
     *
     * @var array<string, list<string>>
     */
    private const TABLES = [
        'users' => ['id'],
        'categories' => ['id'],
        'option_groups' => ['id'],
        'option_values' => ['id'],
        'products' => ['id'],
        'category_option_group' => ['category_id', 'option_group_id'],
        'product_option_group' => ['product_id', 'option_group_id'],
        'coupons' => ['id'],
        'drivers' => ['id'],
        'orders' => ['id'],
        'order_items' => ['id'],
        'driver_shifts' => ['id'],
        'order_driver_transitions' => ['id'],
    ];

    /**
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private const RELATIONSHIPS = [
        ['products', 'category_id', 'categories', 'id'],
        ['option_values', 'option_group_id', 'option_groups', 'id'],
        ['category_option_group', 'category_id', 'categories', 'id'],
        ['category_option_group', 'option_group_id', 'option_groups', 'id'],
        ['product_option_group', 'product_id', 'products', 'id'],
        ['product_option_group', 'option_group_id', 'option_groups', 'id'],
        ['orders', 'coupon_id', 'coupons', 'id'],
        ['orders', 'driver_id', 'drivers', 'id'],
        ['order_items', 'order_id', 'orders', 'id'],
        ['order_items', 'product_id', 'products', 'id'],
        ['driver_shifts', 'driver_id', 'drivers', 'id'],
        ['order_driver_transitions', 'order_id', 'orders', 'id'],
        ['order_driver_transitions', 'driver_id', 'drivers', 'id'],
    ];

    private const ORDER_DECIMAL_COLUMNS = [
        'subtotal',
        'discount_amount',
        'delivery_fee',
        'total',
    ];

    public function handle(): int
    {
        $source = null;

        try {
            $this->assertTargetConfiguration();

            $source = DB::connection(self::SOURCE_CONNECTION);
            $target = DB::connection(self::TARGET_CONNECTION);

            if ($source->getDriverName() !== 'sqlite') {
                throw new RuntimeException('The [sqlite] source connection is not using the SQLite driver.');
            }

            if ($source === $target) {
                throw new RuntimeException('Source and target connections must be different.');
            }

            // This is connection-local and prevents accidental writes through
            // the source connection for the lifetime of this command.
            $source->statement('PRAGMA query_only = ON');
            $source->beginTransaction();

            $this->line('Source connection: '.self::SOURCE_CONNECTION.' (read-only)');
            $this->line('Target connection: '.self::TARGET_CONNECTION);

            try {
                $target->transaction(function () use ($source, $target): void {
                    $this->assertTargetIsEmpty($target);
                    $this->assertSchemasAreCompatible($source, $target);

                    $this->info('Target preflight passed: all business tables are empty.');

                    $sourceCounts = $this->sourceCounts($source);

                    foreach (array_keys(self::TABLES) as $table) {
                        $this->copyTable($source, $target, $table);
                    }

                    $this->verifyMigration($source, $target, $sourceCounts);
                }, 1);
            } finally {
                if ($source->transactionLevel() > 0) {
                    // End the consistent read snapshot without ever committing
                    // anything on the SQLite source connection.
                    $source->rollBack();
                }
            }

            $this->info('Migration completed successfully. Target transaction committed.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($source instanceof Connection && $source->transactionLevel() > 0) {
                try {
                    $source->rollBack();
                } catch (Throwable) {
                    // The source is query-only; there is no source write to recover.
                }
            }

            $this->error('Migration failed. Any active target transaction was rolled back.');
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function assertTargetConfiguration(): void
    {
        $config = config('database.connections.'.self::TARGET_CONNECTION);

        if (! is_array($config)) {
            throw new RuntimeException('The [migration_mysql] database connection is not configured.');
        }

        if (! in_array($config['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            if (! array_key_exists($key, $config) || $config[$key] === null) {
                throw new RuntimeException("Missing migration target database setting [{$key}].");
            }

            if ($key !== 'password' && trim((string) $config[$key]) === '') {
                throw new RuntimeException("Migration target database setting [{$key}] cannot be empty.");
            }
        }
    }

    private function assertTargetIsEmpty(Connection $target): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            $count = $target->table($table)->count();

            if ($count > 0) {
                throw new RuntimeException(
                    "Refusing migration: target business table [{$table}] is not empty ({$count} rows).",
                );
            }
        }
    }

    private function assertSchemasAreCompatible(Connection $source, Connection $target): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            $sourceColumns = $source->getSchemaBuilder()->getColumnListing($table);
            $targetColumns = $target->getSchemaBuilder()->getColumnListing($table);
            $missingColumns = array_values(array_diff($sourceColumns, $targetColumns));

            if ($missingColumns !== []) {
                throw new RuntimeException(sprintf(
                    'Target table [%s] is missing source columns: %s.',
                    $table,
                    implode(', ', $missingColumns),
                ));
            }
        }
    }

    /**
     * @return array<string, int>
     */
    private function sourceCounts(Connection $source): array
    {
        $counts = [];

        foreach (array_keys(self::TABLES) as $table) {
            $counts[$table] = $source->table($table)->count();
        }

        return $counts;
    }

    private function copyTable(Connection $source, Connection $target, string $table): void
    {
        $columns = $source->getSchemaBuilder()->getColumnListing($table);
        $query = $source->table($table)->select($columns);

        foreach (self::TABLES[$table] as $identityColumn) {
            $query->orderBy($identityColumn);
        }

        $query->chunk(self::CHUNK_SIZE, function ($rows) use ($target, $table): void {
            $target->table($table)->insert(
                $rows->map(fn (object $row): array => (array) $row)->all(),
            );
        });
    }

    /**
     * @param  array<string, int>  $sourceCounts
     */
    private function verifyMigration(
        Connection $source,
        Connection $target,
        array $sourceCounts,
    ): void {
        $countRows = [];
        $countMismatches = [];

        foreach (array_keys(self::TABLES) as $table) {
            $targetCount = $target->table($table)->count();
            $matches = $sourceCounts[$table] === $targetCount;

            $countRows[] = [
                $table,
                $sourceCounts[$table],
                $targetCount,
                $matches ? 'OK' : 'MISMATCH',
            ];

            if (! $matches) {
                $countMismatches[] = $table;
            }
        }

        $this->newLine();
        $this->table(['Table', 'Source count', 'Target count', 'Result'], $countRows);

        if ($countMismatches !== []) {
            throw new RuntimeException(
                'Count verification failed for: '.implode(', ', $countMismatches).'.',
            );
        }

        foreach (self::TABLES as $table => $identityColumns) {
            $sourceHash = $this->identityHash($source, $table, $identityColumns);
            $targetHash = $this->identityHash($target, $table, $identityColumns);

            if (! hash_equals($sourceHash, $targetHash)) {
                throw new RuntimeException("Identity verification failed for table [{$table}].");
            }
        }

        $this->info('Primary/composite identity verification: OK ('.count(self::TABLES).' tables).');

        foreach (self::RELATIONSHIPS as [$childTable, $childColumn, $parentTable, $parentColumn]) {
            $orphans = $target->table("{$childTable} as child")
                ->leftJoin(
                    "{$parentTable} as parent",
                    "parent.{$parentColumn}",
                    '=',
                    "child.{$childColumn}",
                )
                ->whereNotNull("child.{$childColumn}")
                ->whereNull("parent.{$parentColumn}")
                ->count();

            if ($orphans > 0) {
                throw new RuntimeException(sprintf(
                    'Referential-integrity verification failed: %s.%s has %d orphaned rows.',
                    $childTable,
                    $childColumn,
                    $orphans,
                ));
            }
        }

        $this->info('Referential-integrity verification: OK ('.count(self::RELATIONSHIPS).' relationships).');

        $this->verifyOrderSample($source, $target);
    }

    /**
     * @param  list<string>  $identityColumns
     */
    private function identityHash(
        Connection $connection,
        string $table,
        array $identityColumns,
    ): string {
        $query = $connection->table($table)->select($identityColumns);

        foreach ($identityColumns as $identityColumn) {
            $query->orderBy($identityColumn);
        }

        $hash = hash_init('sha256');

        foreach ($query->lazy(self::CHUNK_SIZE) as $row) {
            foreach ($identityColumns as $identityColumn) {
                $value = (string) $row->{$identityColumn};
                hash_update($hash, strlen($value).':'.$value.';');
            }

            hash_update($hash, "\n");
        }

        return hash_final($hash);
    }

    private function verifyOrderSample(Connection $source, Connection $target): void
    {
        $sourceOrder = $source->table('orders')
            ->whereNotNull('viva_transaction_id')
            ->orderBy('id')
            ->first()
            ?? $source->table('orders')->orderBy('id')->first();

        if ($sourceOrder === null) {
            throw new RuntimeException('Order sample verification failed: the SQLite source has no orders.');
        }

        $columns = $source->getSchemaBuilder()->getColumnListing('orders');
        $targetOrder = $target->table('orders')
            ->select($columns)
            ->where('id', $sourceOrder->id)
            ->first();

        if ($targetOrder === null) {
            throw new RuntimeException(
                "Order sample verification failed: target order [{$sourceOrder->id}] is missing.",
            );
        }

        foreach ($columns as $column) {
            if (! $this->orderValuesMatch($column, $sourceOrder->{$column}, $targetOrder->{$column})) {
                throw new RuntimeException(sprintf(
                    'Order sample verification failed for order [%s], column [%s].',
                    $sourceOrder->id,
                    $column,
                ));
            }
        }

        $this->info(sprintf(
            'Order sample #%s: OK (%d columns, including public/checkout tokens and Viva/payment fields).',
            $sourceOrder->id,
            count($columns),
        ));
    }

    private function orderValuesMatch(string $column, mixed $source, mixed $target): bool
    {
        if ($source === null || $target === null) {
            return $source === $target;
        }

        if ((string) $source === (string) $target) {
            return true;
        }

        if (! in_array($column, self::ORDER_DECIMAL_COLUMNS, true)) {
            return false;
        }

        return $this->normalizeDecimal($source) === $this->normalizeDecimal($target);
    }

    private function normalizeDecimal(mixed $value): string
    {
        $value = trim((string) $value);

        if (! preg_match('/^([+-]?)(\d+)(?:\.(\d*))?$/D', $value, $matches)) {
            return $value;
        }

        $integer = ltrim($matches[2], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($matches[3] ?? '', '0');
        $number = $fraction === '' ? $integer : $integer.'.'.$fraction;

        return $number === '0' ? '0' : ($matches[1] === '-' ? '-' : '').$number;
    }
}
