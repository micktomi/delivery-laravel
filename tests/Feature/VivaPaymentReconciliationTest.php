<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Services\VivaWalletService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class VivaPaymentReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-28 12:00:00');
        $this->configureViva();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_successful_reconciliation_confirms_a_recent_pending_payment(): void
    {
        $order = $this->vivaOrder();
        $transactionId = (string) Str::uuid();
        $this->fakeReconciliation($order, $transactionId);

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame($transactionId, $order->viva_transaction_id);
        $this->assertNotNull($order->paid_at);
    }

    public function test_pending_payment_without_a_viva_transaction_remains_pending(): void
    {
        $order = $this->vivaOrder();
        $this->fakeReconciliation($order, null);

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->viva_transaction_id);
    }

    public function test_amount_mismatch_is_not_paid_and_is_logged_for_review(): void
    {
        $order = $this->vivaOrder();
        $transactionId = (string) Str::uuid();
        $this->fakeReconciliation($order, $transactionId, ['amount' => 6.00]);

        $this->expectPaymentLog('viva.transaction_amount_mismatch', function (array $context) use ($order, $transactionId): bool {
            return $context['order_id'] === $order->id
                && $context['transaction_id'] === $transactionId
                && $context['paid_cents'] === 600
                && $context['expected_cents'] === 500;
        });

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_currency_mismatch_is_not_paid_and_is_logged_for_review(): void
    {
        $order = $this->vivaOrder();
        $transactionId = (string) Str::uuid();
        $this->fakeReconciliation($order, $transactionId, ['currencyCode' => '840']);

        $this->expectPaymentLog('viva.transaction_not_payable', function (array $context) use ($transactionId): bool {
            return $context['transaction_id'] === $transactionId
                && $context['currency'] === '840';
        });

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_duplicate_reconciliation_is_idempotent(): void
    {
        $order = $this->vivaOrder();
        $transactionId = (string) Str::uuid();
        $this->fakeReconciliation($order, $transactionId);

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);
        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(1, Order::query()->where('viva_transaction_id', $transactionId)->count());
        Http::assertSentCount(3);
    }

    public function test_webhook_and_reconciliation_race_leaves_one_paid_transaction(): void
    {
        $order = $this->vivaOrder();
        $transactionId = (string) Str::uuid();
        $webhookProcessed = false;
        $transaction = $this->verifiedTransaction($order, ['transactionId' => $transactionId]);

        Http::fake([
            'https://demo.vivapayments.com/api/orders/'.$order->viva_order_code => Http::response([
                'OrderCode' => $order->viva_order_code,
                'TransactionId' => $transactionId,
            ]),
            'https://demo-accounts.vivapayments.com/connect/token' => Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => 3600,
            ]),
            'https://demo-api.vivapayments.com/checkout/v2/transactions/'.$transactionId => function (Request $request) use (&$webhookProcessed, $order, $transactionId, $transaction) {
                if (! $webhookProcessed) {
                    $webhookProcessed = true;
                    app(VivaWalletService::class)->processWebhook($this->webhookPayload($transactionId, $order->viva_order_code));
                }

                return Http::response($transaction);
            },
        ]);

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $this->assertTrue($webhookProcessed);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame($transactionId, $order->fresh()->viva_transaction_id);
        $this->assertSame(1, Order::query()->where('viva_transaction_id', $transactionId)->count());
    }

    public function test_already_paid_order_is_not_reconciled_again(): void
    {
        $order = $this->vivaOrder([
            'payment_status' => 'paid',
            'viva_transaction_id' => (string) Str::uuid(),
            'paid_at' => now(),
        ]);
        Http::fake();

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame('paid', $order->fresh()->payment_status);
        Http::assertNothingSent();
    }

    public function test_cancelled_order_is_not_reconciled(): void
    {
        $order = $this->vivaOrder(['status' => OrderStatus::Cancelled->value]);
        Http::fake();

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame('pending', $order->fresh()->payment_status);
        Http::assertNothingSent();
    }

    public function test_reconciliation_is_scheduled_every_five_minutes_without_overlap(): void
    {
        $event = collect(app('Illuminate\\Console\\Scheduling\\Schedule')->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'viva:reconcile-pending-payments'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_viva_webhook_has_no_fixed_throttle_middleware(): void
    {
        $webhook = Route::getRoutes()->getByName('viva.webhook');

        $this->assertNotNull($webhook);
        $this->assertNotContains('throttle:60,1', $webhook->gatherMiddleware());
    }

    private function configureViva(): void
    {
        config()->set('services.viva', [
            'enabled' => true,
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'source_code' => 'test-source',
            'environment' => 'demo',
            'reconciliation_merchant_id' => 'test-merchant-id',
            'reconciliation_api_key' => 'test-merchant-api-key',
        ]);
    }

    private function vivaOrder(array $overrides = []): Order
    {
        static $orderCode = 7680701046572600;

        return Order::factory()->create(array_merge([
            'payment_method' => PaymentMethod::Viva->value,
            'payment_status' => 'pending',
            'viva_order_code' => (string) $orderCode++,
            'subtotal' => '5.00',
            'total' => '5.00',
            'created_at' => now()->subMinutes(10),
            'placed_at' => now()->subMinutes(10),
        ], $overrides));
    }

    private function fakeReconciliation(Order $order, ?string $transactionId, array $overrides = []): void
    {
        $responses = [
            'https://demo.vivapayments.com/api/orders/'.$order->viva_order_code => Http::response([
                'OrderCode' => $order->viva_order_code,
                'TransactionId' => $transactionId,
            ]),
        ];

        if ($transactionId !== null) {
            $responses['https://demo-accounts.vivapayments.com/connect/token'] = Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => 3600,
            ]);
            $responses['https://demo-api.vivapayments.com/checkout/v2/transactions/'.$transactionId] = Http::response(
                $this->verifiedTransaction($order, array_merge(['transactionId' => $transactionId], $overrides)),
            );
        }

        Http::fake($responses);
    }

    private function verifiedTransaction(Order $order, array $overrides = []): array
    {
        return array_merge([
            'amount' => 5.00,
            'orderCode' => $order->viva_order_code,
            'statusId' => 'F',
            'currencyCode' => '978',
        ], $overrides);
    }

    private function webhookPayload(string $transactionId, string $orderCode): array
    {
        return [
            'EventTypeId' => 1796,
            'EventData' => [
                'TransactionId' => $transactionId,
                'OrderCode' => $orderCode,
            ],
        ];
    }

    private function expectPaymentLog(string $event, callable $contextMatches): void
    {
        $paymentLogger = Mockery::mock(LoggerInterface::class);
        $paymentLogger->shouldReceive('log')
            ->once()
            ->with('warning', $event, Mockery::on($contextMatches));

        Log::shouldReceive('channel')->once()->with('payments')->andReturn($paymentLogger);
    }
}
