<?php

namespace Tests\Integration;

use App\Actions\CreateOrder;
use App\Livewire\CheckoutPage;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreSetting;
use App\Services\CartService;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector as DetectorContract;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Opt-in, destructive only to the explicitly named disposable database:
 * CHECKOUT_TEST_DB_DATABASE=leonidas_checkout_test CHECKOUT_TEST_DB_SOCKET=/tmp/mysql.sock
 * php vendor/bin/phpunit tests/Integration/CheckoutConcurrencyTest.php
 * Also supports CHECKOUT_TEST_DB_HOST/PORT/USERNAME/PASSWORD. Requires pcntl.
 * Bypass any cached app configuration when invoking, as for the normal suite.
 */
class CheckoutConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        $database = getenv('CHECKOUT_TEST_DB_DATABASE');
        if (! $database || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped(
                'Real InnoDB row locking for concurrent checkout. Needs pcntl/posix and a'
                .' disposable MariaDB database whose name ends in _checkout_test. Run with:'
                .' CHECKOUT_TEST_DB_DATABASE=leonidas_checkout_test'
                .' CHECKOUT_TEST_DB_SOCKET=/var/run/mysqld/mysqld.sock'
                .' APP_CONFIG_CACHE=/tmp/no-config.php'
                .' php vendor/bin/phpunit tests/Integration/CheckoutConcurrencyTest.php',
            );
        }
        if (! preg_match('/^[a-zA-Z0-9_]+_checkout_test$/D', $database)) {
            throw new RuntimeException('Database name must end in _checkout_test.');
        }

        parent::setUp();

        config(['database.default' => 'checkout_concurrency', 'database.connections.checkout_concurrency' => [
            'driver' => 'mysql',
            'host' => getenv('CHECKOUT_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('CHECKOUT_TEST_DB_PORT') ?: '3306',
            'unix_socket' => getenv('CHECKOUT_TEST_DB_SOCKET') ?: '',
            'database' => $database,
            'username' => getenv('CHECKOUT_TEST_DB_USERNAME') ?: 'root',
            'password' => getenv('CHECKOUT_TEST_DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '', 'strict' => true,
        ], 'cache.default' => 'array', 'session.driver' => 'array', 'logging.default' => 'null']);

        Artisan::call('migrate:fresh', ['--database' => 'checkout_concurrency', '--force' => true]);
        StoreSetting::current()->update(['accepting_orders' => true, 'opening_hours' => null]);
    }

    public function test_concurrent_checkout_requests_retry_a_real_deadlock_and_confirm_the_same_order(): void
    {
        $this->seedCart();
        $token = app(CartService::class)->checkoutToken();
        $snapshot = session('cart');
        $directory = sys_get_temp_dir().'/checkout-concurrency-'.bin2hex(random_bytes(8));
        File::makeDirectory($directory, 0700);
        $children = [];
        DB::disconnect();

        try {
            for ($worker = 0; $worker < 2; $worker++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Cannot fork checkout worker.');
                }
                if ($pid === 0) {
                    $this->runCheckout($worker, $directory, $snapshot);
                    exit(0);
                }
                $children[] = $pid;
            }

            $results = [];
            foreach ($children as $worker => $pid) {
                $this->waitForFile($directory.'/result-'.$worker);
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $results[] = json_decode(file_get_contents($directory.'/result-'.$worker), true, flags: JSON_THROW_ON_ERROR);
            }
            DB::purge();

            foreach ($results as $result) {
                $this->assertArrayNotHasKey('exception', $result, json_encode($result));
                $this->assertSame([], $result['errors'], json_encode($result));
                $this->assertSame($token, $result['token']);
                $this->assertNotNull($result['order_id']);
            }
            $this->assertSame($results[0]['order_id'], $results[1]['order_id']);
            $this->assertGreaterThanOrEqual(1, array_sum(array_column($results, 'deadlocks')),
                'The test must exercise an actual database deadlock, not just sequential replay. '.json_encode($results));
            $this->assertDatabaseCount('orders', 1);
            $this->assertDatabaseCount('order_items', 1);
            $this->assertSame($token, Order::sole()->checkout_token);
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

    public function test_serialization_retry_budget_is_three_attempts_with_the_same_token(): void
    {
        $this->seedCart();
        $token = app(CartService::class)->checkoutToken();
        $attemptedTokens = [];
        // Fault injection checks the finite retry budget. The separate test
        // above proves recovery from an actual two-connection InnoDB deadlock.
        Order::creating(function (Order $order) use (&$attemptedTokens): void {
            $attemptedTokens[] = $order->checkout_token;
            throw new PDOException('Serialization failure', 40001);
        });

        try {
            app(CreateOrder::class)->execute($this->checkoutData());
            $this->fail('Persistent serialization failure must exhaust the retry budget.');
        } catch (PDOException $e) {
            $this->assertSame(40001, $e->getCode());
        }

        $this->assertSame([$token, $token, $token], $attemptedTokens);
        $this->assertDatabaseCount('orders', 0);
        $this->assertFalse(app(CartService::class)->isEmpty());
        $this->assertSame($token, app(CartService::class)->checkoutToken());
    }

    public function test_arbitrary_failure_is_not_retried(): void
    {
        $this->seedCart();
        $attempts = 0;
        Order::creating(function () use (&$attempts): void {
            $attempts++;
            throw new RuntimeException('Non-concurrency failure');
        });

        try {
            app(CreateOrder::class)->execute($this->checkoutData());
            $this->fail('Non-concurrency errors must propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('Non-concurrency failure', $e->getMessage());
        }

        $this->assertSame(1, $attempts);
        $this->assertDatabaseCount('orders', 0);
        $this->assertFalse(app(CartService::class)->isEmpty());
    }

    private function seedCart(): void
    {
        $category = Category::create(['name' => 'Καφέδες', 'slug' => 'checkout-test', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Καφές', 'base_price' => '5.00', 'is_available' => true,
        ]);
        app(CartService::class)->add([
            'product_id' => $product->id, 'product_name' => $product->name,
            'base_price' => 5.00, 'selected_options' => [], 'quantity' => 1, 'line_total' => 5.00,
        ]);
    }

    private function checkoutData(): array
    {
        return [
            'customer_name' => 'Μιχάλης', 'phone' => '6912345678',
            'address' => 'Δημοκρατίας 42', 'payment_method' => 'cash',
        ];
    }

    private function runCheckout(int $worker, string $directory, array $snapshot): void
    {
        try {
            DB::purge();
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
            session(['cart' => $snapshot]);
            $detector = new class extends ConcurrencyErrorDetector
            {
                public int $deadlocks = 0;

                public function causedByConcurrencyError(Throwable $e): bool
                {
                    $conflict = parent::causedByConcurrencyError($e);
                    if ($conflict && (int) ($e->errorInfo[1] ?? 0) === 1213) {
                        $this->deadlocks++;
                    }

                    return $conflict;
                }
            };
            app()->instance(DetectorContract::class, $detector);

            $firstCount = true;
            DB::listen(function (QueryExecuted $query) use (&$firstCount, $worker, $directory): void {
                if ($firstCount && str_starts_with($query->sql, 'select count(*) as `aggregate` from `orders`')) {
                    $firstCount = false;
                    // Both transactions hold the empty order-range lock before
                    // either INSERT: InnoDB must select a deadlock victim.
                    touch($directory.'/count-'.$worker);
                    $this->waitForFile($directory.'/count-'.(1 - $worker));
                }
            });

            $checkout = Livewire::test(CheckoutPage::class)
                ->set('customer_name', 'Μιχάλης')
                ->set('phone', '6912345678')
                ->set('address', 'Δημοκρατίας 42')
                ->set('payment_method', 'cash')
                ->call('submit');
            $result = [
                'order_id' => $checkout->get('confirmedOrderId'),
                'token' => $checkout->get('checkoutToken'),
                'errors' => $checkout->instance()->getErrorBag()->toArray(),
                'deadlocks' => $detector->deadlocks,
            ];
        } catch (Throwable $e) {
            $result = ['exception' => $e::class, 'message' => $e->getMessage()];
        }
        file_put_contents($directory.'/result-'.$worker, json_encode($result, JSON_THROW_ON_ERROR));
    }

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 20;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Timed out waiting for concurrent checkout.');
            }
            usleep(1000);
        }
    }
}
