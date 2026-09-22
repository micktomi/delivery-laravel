<?php

namespace Tests\Integration;

use App\Actions\CancelOrder;
use App\Actions\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\PrintJob;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Real cross-process races against MariaDB, with an explicitly named
 * disposable database. See the skip message for the exact command.
 *
 * Two different guarantees live here.
 *
 * The printer poll is serialised by InnoDB: it takes SELECT ... FOR UPDATE on
 * the order row, so an uncommitted cancellation blocks it and it must wait
 * rather than hand the ticket out. That test is unchanged.
 *
 * Viva payment start is not. It holds a per-order Laravel cache lock, which
 * only serialises starts against each other - CancelOrder never takes it - and
 * it issues no SELECT ... FOR UPDATE at all. Its ordering against a
 * cancellation rests entirely on two things: the cancellation committing its
 * row, and start's conditional UPDATE ... WHERE status NOT IN
 * ('cancelled','completed') re-evaluating against whatever is committed by the
 * time it runs. These tests drive exactly that.
 *
 * Because the cache lock has to be shared across forked workers, the cache
 * store is a file store rooted in this run's own temporary directory. The
 * array store the suite used before is process-local after pcntl_fork() and
 * cannot test cross-process locking at all.
 */
class TerminalOrderConcurrencyTest extends TestCase
{
    private const ORDER_CODE = '7680701046572600';

    private const CHECKOUT_URL = 'https://demo.vivapayments.com/web/checkout?ref='.self::ORDER_CODE;

    private const ORDER_URL = 'https://demo.vivapayments.com/api/orders/'.self::ORDER_CODE;

    private const CREATE_URL = 'https://demo-api.vivapayments.com/checkout/v2/orders';

    private const TOKEN_URL = 'https://demo-accounts.vivapayments.com/connect/token';

    private string $directory;

    /** @var list<int> */
    private array $children = [];

    protected function setUp(): void
    {
        $database = getenv('TERMINAL_TEST_DB_DATABASE');
        if (! $database || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped(
                'Real cross-process races for Viva start vs cancellation. Needs pcntl/posix and a'
                .' disposable MariaDB database whose name ends in _terminal_test. Run with:'
                .' TERMINAL_TEST_DB_DATABASE=leonidas_terminal_test'
                .' TERMINAL_TEST_DB_SOCKET=/var/run/mysqld/mysqld.sock'
                .' APP_CONFIG_CACHE=/tmp/no-config.php'
                .' php vendor/bin/phpunit tests/Integration/TerminalOrderConcurrencyTest.php',
            );
        }
        if (! preg_match('/^[a-zA-Z0-9_]+_terminal_test$/D', $database)) {
            throw new RuntimeException('Database name must end in _terminal_test.');
        }
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/terminal-concurrency-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->directory, 0700);

        config(['database.default' => 'terminal_concurrency', 'database.connections.terminal_concurrency' => [
            'driver' => 'mysql', 'host' => getenv('TERMINAL_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('TERMINAL_TEST_DB_PORT') ?: '3306',
            'unix_socket' => getenv('TERMINAL_TEST_DB_SOCKET') ?: '', 'database' => $database,
            'username' => getenv('TERMINAL_TEST_DB_USERNAME') ?: 'root',
            'password' => getenv('TERMINAL_TEST_DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ], 'session.driver' => 'array', 'logging.default' => 'null',
            // Kept per run so a failing race can say what the payment path
            // actually decided, including an exception the controller swallows.
            'logging.channels.payments' => [
                'driver' => 'single',
                'path' => $this->directory.'/payments.log',
                'level' => 'debug',
            ],
            'printing.token' => 'test-token', 'services.viva.enabled' => true,
            'services.viva.client_id' => 'test-client-id',
            'services.viva.client_secret' => 'test-client-secret',
            'services.viva.source_code' => 'test-source',
            'services.viva.environment' => 'demo',
            'services.viva.reconciliation_merchant_id' => 'test-merchant-id',
            'services.viva.reconciliation_api_key' => 'test-merchant-api-key',
            // Shared by every forked worker, so Cache::lock() is a real
            // cross-process lock. FileStore::add() takes an exclusive flock.
            'cache.default' => 'file',
            'cache.stores.file' => [
                'driver' => 'file',
                'path' => $this->directory.'/cache',
                'lock_path' => $this->directory.'/cache',
            ]]);
        Artisan::call('migrate:fresh', ['--database' => 'terminal_concurrency', '--force' => true]);
        Http::preventStrayRequests();
        // Cancelling a pending Viva order that reached Smart Checkout makes the
        // payment order unpayable at Viva first: GET the state, then DELETE.
        Http::fake([
            self::ORDER_URL => fn ($request) => $request->method() === 'DELETE'
                ? Http::response(['OrderCode' => 7680701046572600, 'ErrorCode' => 0, 'EventId' => 0, 'Success' => true])
                : Http::response(['OrderCode' => 7680701046572600, 'StateId' => 0]),
        ]);
    }

    protected function tearDown(): void
    {
        $this->reapChildren();

        // Only set once parent::setUp() ran, so this also skips the cleanup
        // when the test skipped itself before the application existed.
        if (isset($this->directory)) {
            DB::purge();
            File::deleteDirectory($this->directory);
        }

        parent::tearDown();
    }

    /**
     * Start has read the order and is waiting on Viva, holding the cache lock
     * and no database lock at all, when the cancellation commits. The payment
     * order Viva hands back must be thrown away: storing it would send a
     * customer to pay for an order that no longer exists.
     */
    public function test_a_cancellation_committed_during_the_viva_call_is_never_overwritten(): void
    {
        $order = $this->vivaOrder(code: null);

        $results = $this->race(
            cancelling: function () use ($order): array {
                // Only unblocks once start is inside the create-order call.
                $this->waitForFile($this->directory.'/viva-call-entered');
                app(CancelOrder::class)->execute($order);
                $this->signal('cancel-committed');

                return ['cancelled' => true];
            },
            starting: function () use ($order): array {
                Http::fake([
                    self::TOKEN_URL => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
                    // No return type: Http::response() hands back a promise.
                    self::CREATE_URL => function () {
                        $this->signal('viva-call-entered');
                        $this->waitForFile($this->directory.'/cancel-committed');

                        return Http::response(['orderCode' => self::ORDER_CODE]);
                    },
                ]);

                return $this->startPayment($order);
            },
        );

        $this->assertTrue($results[0]['cancelled']);
        $this->assertSame([], $results[0]['sent'], 'An order with no payment order code has nothing to cancel at Viva.');

        $this->assertSame(409, $results[1]['status'], json_encode($results[1]).' '.$this->paymentLog());
        $this->assertNull($results[1]['location'], json_encode($results[1]));
        // The race was real: Viva was asked for a payment order and answered.
        $this->assertContains('POST '.self::CREATE_URL, $results[1]['sent']);

        $order = $order->fresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertNull($order->viva_order_code, 'A cancelled order must never carry a Viva payment order code.');
        $this->assertSame('pending', $order->payment_status);
        $this->assertNull($order->paid_at);
    }

    /**
     * The cancellation gets there first and commits. Start must refuse on the
     * freshly read row rather than resume the stored checkout.
     */
    public function test_a_committed_cancellation_refuses_to_resume_an_existing_checkout(): void
    {
        $order = $this->vivaOrder(code: self::ORDER_CODE);

        $results = $this->race(
            cancelling: function () use ($order): array {
                app(CancelOrder::class)->execute($order);
                $this->signal('cancel-committed');

                return ['cancelled' => true];
            },
            starting: function () use ($order): array {
                $this->waitForFile($this->directory.'/cancel-committed');

                return $this->startPayment($order);
            },
        );

        $this->assertTrue($results[0]['cancelled']);
        // The payment order was made unpayable at Viva before the local commit.
        $this->assertSame(['GET '.self::ORDER_URL, 'DELETE '.self::ORDER_URL], $results[0]['sent']);

        $this->assertSame(409, $results[1]['status']);
        $this->assertNull($results[1]['location'], 'A cancelled order must never redirect to Smart Checkout.');
        $this->assertSame([], $results[1]['sent'], 'A refused start talks to Viva not at all.');

        $order = $order->fresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(self::ORDER_CODE, $order->viva_order_code, 'Cancelling keeps the audit trail back to Viva.');
        $this->assertSame('pending', $order->payment_status);
    }

    /**
     * The other ordering. Start reads the order before the cancellation
     * commits, so it does hand back the stored checkout URL - there is no way
     * to cancel a remote payment order and a local order atomically. What has
     * to hold is that the URL is not payable: CancelOrder deletes the payment
     * order at Viva before it commits anything locally.
     */
    public function test_a_checkout_resumed_before_the_commit_is_already_dead_at_viva(): void
    {
        $order = $this->vivaOrder(code: self::ORDER_CODE);

        $results = $this->race(
            cancelling: function () use ($order): array {
                // QueryExecuted fires after the row has actually been read, so
                // start is past its status check before this cancels.
                $this->waitForFile($this->directory.'/order-read');
                app(CancelOrder::class)->execute($order);

                return ['cancelled' => true];
            },
            starting: function () use ($order): array {
                DB::listen(function (QueryExecuted $query): void {
                    if (str_contains($query->sql, 'from `orders`') && str_contains($query->sql, '`id` = ?')) {
                        $this->signal('order-read');
                    }
                });

                return $this->startPayment($order);
            },
        );

        $this->assertTrue($results[0]['cancelled']);
        $this->assertContains('DELETE '.self::ORDER_URL, $results[0]['sent'], 'The payment order must be killed at Viva.');

        $this->assertSame(302, $results[1]['status']);
        $this->assertSame(self::CHECKOUT_URL, $results[1]['location']);
        $this->assertSame([], $results[1]['sent'], 'Resuming a stored checkout is a redirect, not a Viva call.');

        $order = $order->fresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status, 'The cancellation is never overwritten by the start.');
        $this->assertSame(self::ORDER_CODE, $order->viva_order_code);
        $this->assertSame('pending', $order->payment_status);
        $this->assertNull($order->paid_at, 'The redirect alone never moves money.');
    }

    /**
     * Unchanged: the printer poll really does take SELECT ... FOR UPDATE, so an
     * uncommitted cancellation must block it rather than let it hand the ticket
     * out. This is the one case in this file that InnoDB itself serialises.
     */
    public function test_committing_cancellation_blocks_a_concurrent_printer_handoff(): void
    {
        $order = Order::factory()->create();
        app(TransitionOrderStatus::class)->execute($order, OrderStatus::Nea);

        $directory = $this->directory;
        $results = $this->race(
            cancelling: function () use ($order, $directory): array {
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

                return ['cancelled' => true];
            },
            starting: function () use ($directory): array {
                $this->waitForFile($directory.'/cancel-locked');
                DB::connection()->beforeExecuting(function (string $sql) use ($directory): void {
                    if (str_contains($sql, 'from `orders`') && str_contains($sql, 'for update')) {
                        touch($directory.'/handoff-lock-attempt');
                    }
                });
                $response = $this->withToken('test-token')->postJson('/api/printing/next');

                return [
                    'status' => $response->status(),
                    'location' => $response->headers->get('Location'),
                    'sent' => $this->sentRequests(),
                ];
            },
        );

        $this->assertTrue($results[0]['cancelled']);
        $this->assertSame(204, $results[1]['status']);
        $this->assertNull($results[1]['location']);
        $this->assertSame([], $results[1]['sent']);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame('cancelled', PrintJob::sole()->status);
        $this->assertSame(0, PrintJob::sole()->attempts);
    }

    /**
     * Forks worker 0 (cancelling) and worker 1 (starting), waits for both and
     * returns their decoded results. Each worker owns its own database
     * connection and its own recorded HTTP traffic.
     *
     * @return array<int, array<string, mixed>>
     */
    private function race(callable $cancelling, callable $starting): array
    {
        $workers = [$cancelling, $starting];
        DB::disconnect();

        foreach ($workers as $index => $worker) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('Cannot fork worker.');
            }

            if ($pid === 0) {
                try {
                    DB::purge();
                    DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
                    $result = $worker();
                    $result += ['sent' => $this->sentRequests()];
                } catch (Throwable $e) {
                    $result = ['exception' => $e::class, 'message' => $e->getMessage()];
                }

                file_put_contents($this->directory.'/result-'.$index, json_encode($result, JSON_THROW_ON_ERROR));
                exit(0);
            }

            $this->children[] = $pid;
        }

        $results = [];
        foreach ($this->children as $index => $pid) {
            $this->waitForFile($this->directory.'/result-'.$index);
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status), 'Worker '.$index.' did not exit cleanly.');
            $results[$index] = json_decode(
                file_get_contents($this->directory.'/result-'.$index),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $this->assertArrayNotHasKey('exception', $results[$index], json_encode($results[$index]));
        }

        $this->children = [];
        DB::purge();

        return $results;
    }

    /** @return array<string, mixed> */
    private function startPayment(Order $order): array
    {
        $response = $this->withSession(['latest_public_order_route_key' => $order->getRouteKey()])
            ->get(route('viva.start', $order));

        return [
            'status' => $response->status(),
            'location' => $response->headers->get('Location'),
            'sent' => $this->sentRequests(),
        ];
    }

    private function vivaOrder(?string $code): Order
    {
        $order = Order::factory()->create();
        $order->forceFill([
            'payment_method' => PaymentMethod::Viva,
            'payment_status' => 'pending',
            'viva_order_code' => $code,
        ])->save();

        return $order;
    }

    /** @return list<string> */
    private function sentRequests(): array
    {
        return Http::recorded()
            ->map(fn (array $pair): string => $pair[0]->method().' '.$pair[0]->url())
            ->values()
            ->all();
    }

    /** Atomic for a reader: the file only appears once it is complete. */
    private function signal(string $name): void
    {
        $temporary = $this->directory.'/.'.$name.'.'.getmypid();
        file_put_contents($temporary, '1');
        rename($temporary, $this->directory.'/'.$name);
    }

    /** Whatever the payment path logged during the race, for failure messages. */
    private function paymentLog(): string
    {
        $path = $this->directory.'/payments.log';

        return is_file($path) ? file_get_contents($path) : '(no payment events)';
    }

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Timed out waiting for '.basename($path).'.');
            }
            usleep(1000);
        }
    }

    private function reapChildren(): void
    {
        foreach ($this->children as $pid) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
        }

        $this->children = [];
    }
}
