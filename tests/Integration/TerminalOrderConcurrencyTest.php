<?php

namespace Tests\Integration;

use App\Actions\CancelOrder;
use App\Actions\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\PrintJob;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/**
 * Real InnoDB locking; uses only an explicitly named disposable database.
 * TERMINAL_TEST_DB_DATABASE=leonidas_terminal_test TERMINAL_TEST_DB_SOCKET=/tmp/mysql.sock
 * APP_CONFIG_CACHE=/tmp/no-config.php php vendor/bin/phpunit tests/Integration/TerminalOrderConcurrencyTest.php
 */
class TerminalOrderConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        $database = getenv('TERMINAL_TEST_DB_DATABASE');
        if (! $database || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Requires pcntl and an explicit disposable MariaDB database.');
        }
        if (! preg_match('/^[a-zA-Z0-9_]+_terminal_test$/D', $database)) {
            throw new RuntimeException('Database name must end in _terminal_test.');
        }
        parent::setUp();
        config(['database.default' => 'terminal_concurrency', 'database.connections.terminal_concurrency' => [
            'driver' => 'mysql', 'host' => getenv('TERMINAL_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('TERMINAL_TEST_DB_PORT') ?: '3306',
            'unix_socket' => getenv('TERMINAL_TEST_DB_SOCKET') ?: '', 'database' => $database,
            'username' => getenv('TERMINAL_TEST_DB_USERNAME') ?: 'root',
            'password' => getenv('TERMINAL_TEST_DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ], 'cache.default' => 'array', 'session.driver' => 'array', 'logging.default' => 'null',
            'printing.token' => 'test-token', 'services.viva.enabled' => true,
            'services.viva.reconciliation_merchant_id' => 'test-merchant-id',
            'services.viva.reconciliation_api_key' => 'test-merchant-api-key']);
        Artisan::call('migrate:fresh', ['--database' => 'terminal_concurrency', '--force' => true]);
        Http::preventStrayRequests();
        // Cancelling a pending Viva order that reached Smart Checkout now makes
        // the payment order unpayable at Viva first. Only the cancelling worker
        // reaches this; the worker racing it still sends nothing, which it
        // asserts for itself.
        Http::fake([
            'https://demo.vivapayments.com/api/orders/*' => fn ($request) => $request->method() === 'DELETE'
                ? Http::response(['OrderCode' => 7680701046572600, 'ErrorCode' => 0, 'EventId' => 0, 'Success' => true])
                : Http::response(['OrderCode' => 7680701046572600, 'StateId' => 0]),
        ]);
    }

    public static function endpoints(): array
    {
        return ['Viva creation' => ['viva', null], 'Viva reuse' => ['viva', '7680701046572600'], 'printer poll' => ['print', null]];
    }

    #[DataProvider('endpoints')]
    public function test_committing_cancellation_blocks_concurrent_handoff(string $endpoint, ?string $code): void
    {
        $order = Order::factory()->create();
        if ($endpoint === 'viva') {
            $order->forceFill(['payment_method' => PaymentMethod::Viva, 'payment_status' => 'pending', 'viva_order_code' => $code])->save();
        } else {
            app(TransitionOrderStatus::class)->execute($order, OrderStatus::Nea);
        }
        $directory = sys_get_temp_dir().'/terminal-concurrency-'.bin2hex(random_bytes(8));
        File::makeDirectory($directory, 0700);
        $children = [];
        DB::disconnect();
        try {
            for ($worker = 0; $worker < 2; $worker++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Cannot fork worker.');
                }
                if ($pid === 0) {
                    try {
                        DB::purge();
                        DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
                        if ($worker === 0) {
                            DB::transaction(function () use ($order, $directory): void {
                                app(CancelOrder::class)->execute($order);
                                touch($directory.'/cancel-locked');
                                $this->waitForFile($directory.'/handoff-lock-attempt');
                                // The other process is entering SELECT FOR UPDATE while
                                // cancellation is uncommitted. It must wait, not hand off.
                                usleep(200000);
                                if (is_file($directory.'/result-1')) {
                                    throw new RuntimeException('Handoff did not wait for cancellation.');
                                }
                            });
                            $result = ['cancelled' => true];
                        } else {
                            $this->waitForFile($directory.'/cancel-locked');
                            DB::connection()->beforeExecuting(function (string $sql) use ($directory): void {
                                if (str_contains($sql, 'from `orders`') && str_contains($sql, 'for update')) {
                                    touch($directory.'/handoff-lock-attempt');
                                }
                            });
                            $response = $endpoint === 'viva'
                                ? $this->withSession(['latest_public_order_route_key' => $order->getRouteKey()])->get(route('viva.start', $order))
                                : $this->withToken('test-token')->postJson('/api/printing/next');
                            Http::assertNothingSent();
                            $result = ['status' => $response->status(), 'location' => $response->headers->get('Location')];
                        }
                    } catch (Throwable $e) {
                        $result = ['exception' => $e::class, 'message' => $e->getMessage()];
                    }
                    file_put_contents($directory.'/result-'.$worker, json_encode($result, JSON_THROW_ON_ERROR));
                    exit(0);
                }
                $children[] = $pid;
            }
            $results = [];
            foreach ($children as $worker => $pid) {
                $this->waitForFile($directory.'/result-'.$worker);
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $results[$worker] = json_decode(file_get_contents($directory.'/result-'.$worker), true, flags: JSON_THROW_ON_ERROR);
                $this->assertArrayNotHasKey('exception', $results[$worker], json_encode($results[$worker]));
            }
            DB::purge();
            $this->assertTrue($results[0]['cancelled']);
            $this->assertSame($endpoint === 'viva' ? 409 : 204, $results[1]['status']);
            $this->assertNull($results[1]['location']);
            $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
            $this->assertSame($code, $order->fresh()->viva_order_code);
            if ($endpoint === 'print') {
                $this->assertSame('cancelled', PrintJob::sole()->status);
                $this->assertSame(0, PrintJob::sole()->attempts);
            }
        } finally {
            foreach ($children as $pid) {
                if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                    posix_kill($pid, SIGKILL);
                    pcntl_waitpid($pid, $status);
                }
            }
            DB::purge();
            File::deleteDirectory($directory);
        }
    }

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Timed out waiting for terminal-state concurrency test.');
            }
            usleep(1000);
        }
    }
}
