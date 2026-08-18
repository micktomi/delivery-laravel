<?php

namespace Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MigrateSqliteBusinessDataToMariaDbTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const BUSINESS_TABLES = [
        'users',
        'categories',
        'option_groups',
        'option_values',
        'products',
        'category_option_group',
        'product_option_group',
        'coupons',
        'drivers',
        'orders',
        'order_items',
        'driver_shifts',
        'order_driver_transitions',
    ];

    private string $sourceDatabase;

    private string $targetDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceDatabase = (string) tempnam(sys_get_temp_dir(), 'delivery-source-');
        $this->targetDatabase = (string) tempnam(sys_get_temp_dir(), 'delivery-target-');

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', $this->sqliteConnection($this->sourceDatabase));
        config()->set(
            'database.connections.migration_mysql',
            $this->sqliteConnection($this->targetDatabase),
        );

        DB::purge('sqlite');
        DB::purge('migration_mysql');

        $this->artisan('migrate:fresh', [
            '--database' => 'sqlite',
            '--force' => true,
        ])->assertExitCode(Command::SUCCESS);

        $this->artisan('migrate:fresh', [
            '--database' => 'migration_mysql',
            '--force' => true,
        ])->assertExitCode(Command::SUCCESS);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        DB::purge('migration_mysql');

        if (isset($this->sourceDatabase)) {
            @unlink($this->sourceDatabase);
        }

        if (isset($this->targetDatabase)) {
            @unlink($this->targetDatabase);
        }

        parent::tearDown();
    }

    public function test_it_migrates_only_business_data_and_preserves_order_payment_fields(): void
    {
        $this->seedSourceBusinessData();

        $this->artisan('data:migrate-sqlite-to-mariadb')
            ->expectsOutputToContain('Target preflight passed')
            ->expectsOutputToContain('Primary/composite identity verification: OK (13 tables)')
            ->expectsOutputToContain('Referential-integrity verification: OK (13 relationships)')
            ->expectsOutputToContain('including public/checkout tokens and Viva/payment fields')
            ->expectsOutputToContain('Target transaction committed')
            ->assertExitCode(Command::SUCCESS);

        foreach (self::BUSINESS_TABLES as $table) {
            $this->assertSame(
                DB::connection('sqlite')->table($table)->count(),
                DB::connection('migration_mysql')->table($table)->count(),
                "Count mismatch for table [{$table}].",
            );
        }

        $sourceOrder = (array) DB::connection('sqlite')->table('orders')->where('id', 801)->first();
        $targetOrder = (array) DB::connection('migration_mysql')->table('orders')->where('id', 801)->first();

        $this->assertSame($sourceOrder, $targetOrder);
        $this->assertSame('paid', $targetOrder['payment_status']);
        $this->assertSame('7680701046572600', $targetOrder['viva_order_code']);
        $this->assertSame('11111111-2222-4333-8444-555555555555', $targetOrder['viva_transaction_id']);
        $this->assertSame('2026-08-18 12:34:56', $targetOrder['paid_at']);
        $this->assertSame('public-token-801', $targetOrder['public_token']);
        $this->assertSame('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $targetOrder['checkout_token']);

        $this->assertSame(1, DB::connection('sqlite')->table('sessions')->count());
        $this->assertSame(0, DB::connection('migration_mysql')->table('sessions')->count());
        $this->assertSame([], DB::connection('migration_mysql')->select('PRAGMA foreign_key_check'));
        $this->assertSame('sqlite', config('database.default'));
    }

    public function test_it_refuses_to_run_when_any_target_business_table_is_not_empty(): void
    {
        $this->seedSourceBusinessData();

        DB::connection('migration_mysql')->table('categories')->insert([
            'id' => 999,
            'name' => 'Existing target row',
            'slug' => 'existing-target-row',
            'sort_order' => 0,
            'is_active' => 1,
            'created_at' => '2026-08-18 12:34:56',
            'updated_at' => '2026-08-18 12:34:56',
        ]);

        $this->artisan('data:migrate-sqlite-to-mariadb')
            ->expectsOutputToContain('Refusing migration: target business table [categories] is not empty')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, DB::connection('migration_mysql')->table('users')->count());
        $this->assertSame(1, DB::connection('migration_mysql')->table('categories')->count());
        $this->assertSame(0, DB::connection('migration_mysql')->table('orders')->count());
    }

    public function test_it_rolls_back_all_target_rows_on_a_constraint_error(): void
    {
        $this->seedSourceBusinessData();

        $source = DB::connection('sqlite');
        $source->statement('PRAGMA foreign_keys = OFF');
        $source->table('order_items')->where('id', 901)->update(['product_id' => 999999]);
        $source->statement('PRAGMA foreign_keys = ON');

        $this->artisan('data:migrate-sqlite-to-mariadb')
            ->expectsOutputToContain('Any active target transaction was rolled back')
            ->assertExitCode(Command::FAILURE);

        foreach (self::BUSINESS_TABLES as $table) {
            $this->assertSame(
                0,
                DB::connection('migration_mysql')->table($table)->count(),
                "Target table [{$table}] was not rolled back.",
            );
        }

        $this->assertSame(
            999999,
            DB::connection('sqlite')->table('order_items')->where('id', 901)->value('product_id'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sqliteConnection(string $database): array
    {
        return [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 5000,
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
            'transaction_mode' => 'DEFERRED',
        ];
    }

    private function seedSourceBusinessData(): void
    {
        $source = DB::connection('sqlite');
        $timestamp = '2026-08-18 12:34:56';

        $source->table('users')->insert([
            'id' => 101,
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'email_verified_at' => $timestamp,
            'password' => 'hashed-password',
            'remember_token' => 'remember-token',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'is_admin' => 1,
        ]);

        $source->table('categories')->insert([
            'id' => 201,
            'name' => 'Coffee',
            'slug' => 'coffee',
            'sort_order' => 3,
            'is_active' => 1,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $source->table('option_groups')->insert([
            'id' => 301,
            'name' => 'Sugar',
            'selection' => 'single',
            'is_required' => 1,
            'min_select' => 1,
            'max_select' => 1,
            'sort_order' => 4,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $source->table('option_values')->insert([
            'id' => 401,
            'option_group_id' => 301,
            'name' => 'Medium',
            'price_delta' => 0.25,
            'is_default' => 1,
            'sort_order' => 2,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $source->table('products')->insert([
            'id' => 501,
            'category_id' => 201,
            'name' => 'Freddo Espresso',
            'description' => 'Double shot',
            'image' => 'products/freddo.webp',
            'base_price' => 3.50,
            'is_available' => 1,
            'sort_order' => 5,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $source->table('category_option_group')->insert([
            'category_id' => 201,
            'option_group_id' => 301,
            'sort_order' => 6,
        ]);

        $source->table('product_option_group')->insert([
            'product_id' => 501,
            'option_group_id' => 301,
            'sort_order' => 7,
        ]);

        $source->table('coupons')->insert([
            'id' => 601,
            'code' => 'WELCOME10',
            'type' => 'fixed',
            'value' => 1.00,
            'min_order_total' => 4.00,
            'starts_at' => '2026-08-01 00:00:00',
            'expires_at' => '2026-08-31 23:59:59',
            'max_uses' => 100,
            'used_count' => 8,
            'is_active' => 1,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $source->table('drivers')->insert([
            'id' => 701,
            'name' => 'Driver',
            'pin' => 'hashed-pin',
            'is_active' => 1,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $source->table('orders')->insert([
            'id' => 801,
            'display_number' => 42,
            'public_token' => 'public-token-801',
            'status' => 'out_for_delivery',
            'driver_id' => 701,
            'delivery_status' => 'out_for_delivery',
            'payment_method' => 'viva',
            'payment_status' => 'paid',
            'viva_order_code' => '7680701046572600',
            'viva_transaction_id' => '11111111-2222-4333-8444-555555555555',
            'paid_at' => $timestamp,
            'customer_name' => 'Customer',
            'phone' => '6912345678',
            'customer_email' => 'customer@example.test',
            'address' => 'Athens 1',
            'floor_bell' => '2A',
            'notes' => 'Ring once',
            'subtotal' => 5.00,
            'coupon_code' => 'WELCOME10',
            'discount_amount' => 1.00,
            'coupon_id' => 601,
            'delivery_fee' => 0.50,
            'total' => 4.50,
            'placed_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'checkout_token' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        ]);

        $source->table('order_items')->insert([
            'id' => 901,
            'order_id' => 801,
            'product_id' => 501,
            'product_name' => 'Freddo Espresso',
            'base_price' => 3.50,
            'quantity' => 1,
            'selected_options' => json_encode([
                ['group' => 'Sugar', 'value' => 'Medium', 'price_delta' => 0.25],
            ], JSON_THROW_ON_ERROR),
            'line_total' => 3.75,
            'notes' => 'Extra ice',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $source->table('driver_shifts')->insert([
            'id' => 1001,
            'driver_id' => 701,
            'started_at' => '2026-08-18 11:00:00',
            'ended_at' => null,
        ]);

        $source->table('order_driver_transitions')->insert([
            'id' => 1101,
            'order_id' => 801,
            'driver_id' => 701,
            'from_status' => 'ready',
            'to_status' => 'out_for_delivery',
            'transitioned_at' => $timestamp,
        ]);

        $source->table('sessions')->insert([
            'id' => 'excluded-session',
            'user_id' => 101,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'excluded',
            'last_activity' => 1770000000,
        ]);
    }
}
