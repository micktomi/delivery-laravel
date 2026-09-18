<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Models\Order;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

/**
 * Regression for the Viva start path on SQLite: the request to Viva used to
 * run inside a DEFERRED transaction that had already read the order. In WAL
 * mode that read pins a snapshot, and once any other connection commits — a
 * kitchen board poll writing its session row is enough — the transaction can
 * no longer be upgraded to a write. SQLite answers "database is locked" at
 * once, busy_timeout does not apply, and the payment order Viva had just
 * created was thrown away.
 *
 * RefreshDatabase's :memory: database cannot be shared with a second
 * connection, so this test owns a file-based WAL database instead.
 */
class VivaStartConcurrentWriteTest extends TestCase
{
    private const CONNECTION = 'viva_start_file';

    private const SESSION_ORDER_KEY = 'latest_public_order_route_key';

    private string $directory;

    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/viva-start-'.Str::lower(Str::random(8)));
        File::ensureDirectoryExists($this->directory);
        $this->databasePath = $this->directory.'/database.sqlite';
        touch($this->databasePath);

        config()->set('database.connections.'.self::CONNECTION, array_merge(
            config('database.connections.sqlite'),
            ['url' => null, 'database' => $this->databasePath],
        ));
        config()->set('database.default', self::CONNECTION);
        DB::purge(self::CONNECTION);

        $this->artisan('migrate', ['--database' => self::CONNECTION])->assertSuccessful();

        Cache::flush();
        config()->set('services.viva', [
            'enabled' => true,
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'source_code' => 'test-source',
            'environment' => 'demo',
        ]);
    }

    protected function tearDown(): void
    {
        DB::disconnect(self::CONNECTION);

        parent::tearDown();

        File::deleteDirectory($this->directory);
    }

    public function test_start_persists_the_viva_order_code_even_when_another_request_commits_during_the_viva_call(): void
    {
        $this->assertSame('wal', strtolower((string) DB::selectOne('pragma journal_mode')->journal_mode));

        $order = Order::factory()->create([
            'payment_method' => PaymentMethod::Viva->value,
            'payment_status' => 'pending',
            'viva_order_code' => null,
            'subtotal' => '5.00',
            'total' => '5.00',
        ]);
        $orderCode = '7680701046572600';
        $databasePath = $this->databasePath;

        Http::fake([
            'https://demo-accounts.vivapayments.com/connect/token' => Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => 3600,
            ]),
            'https://demo-api.vivapayments.com/checkout/v2/orders' => function () use ($orderCode, $databasePath) {
                // While Viva is still answering, a kitchen board poll on its
                // own connection commits its session row.
                $boardPoll = new PDO('sqlite:'.$databasePath);
                $boardPoll->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $boardPoll->exec('pragma busy_timeout = 5000');
                $boardPoll->exec("insert into sessions (id, payload, last_activity) values ('kitchen-board-poll', 'e30=', ".time().')');

                return Http::response(['orderCode' => $orderCode]);
            },
        ]);

        $this->withSession([self::SESSION_ORDER_KEY => $order->getRouteKey()])
            ->get(route('viva.start', $order))
            ->assertRedirect('https://demo.vivapayments.com/web/checkout?ref='.$orderCode);

        $this->assertDatabaseHas('orders', [
            'id' => $order->getKey(),
            'payment_status' => 'pending',
            'viva_order_code' => $orderCode,
        ]);

        // Exactly one payment order was created at Viva: no orphan from a retry.
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://demo-api.vivapayments.com/checkout/v2/orders');
    }
}
