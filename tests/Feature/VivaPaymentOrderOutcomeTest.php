<?php

namespace Tests\Feature;

use App\Actions\CancelOrder;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Services\VivaWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VivaPaymentOrderOutcomeTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION_ORDER_KEY = 'latest_public_order_route_key';

    private const ORDER_CODE = '7680701046572600';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->configureViva();
        Http::preventStrayRequests();
    }

    public function test_definite_create_order_http_rejection_remains_pending_and_can_be_retried(): void
    {
        $order = $this->vivaOrder();
        Cache::put($this->tokenCacheKey(), 'cached-token', 3600);
        Http::fake([
            'https://demo-api.vivapayments.com/checkout/v2/orders' => Http::sequence()
                ->push(['error' => 'invalid_request'], 422)
                ->push(['orderCode' => self::ORDER_CODE]),
        ]);

        $this->startPayment($order)
            ->assertRedirect(route('order.track', $order))
            ->assertSessionHas('viva_error');

        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->viva_order_code);

        $this->startPayment($order)
            ->assertRedirect('https://demo.vivapayments.com/web/checkout?ref='.self::ORDER_CODE);

        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame(self::ORDER_CODE, $order->fresh()->viva_order_code);
        Http::assertSentCount(2);
    }

    public function test_create_order_connection_failure_persists_an_ambiguous_outcome(): void
    {
        $order = $this->vivaOrder();
        $createAttempts = 0;
        Cache::put($this->tokenCacheKey(), 'cached-token', 3600);
        Http::fake([
            'https://demo-api.vivapayments.com/checkout/v2/orders' => static function () use (&$createAttempts): never {
                $createAttempts++;

                throw new ConnectionException('response-lost');
            },
        ]);

        $this->startPayment($order)
            ->assertRedirect(route('order.track', $order))
            ->assertSessionHas('viva_status')
            ->assertSessionMissing('viva_error');

        $order->refresh();
        $this->assertSame(VivaWalletService::PAYMENT_ORDER_OUTCOME_UNKNOWN, $order->payment_status);
        $this->assertNull($order->viva_order_code);
        $this->assertNull($order->viva_transaction_id);
        $this->assertNull($order->paid_at);

        $this->startPayment($order)
            ->assertRedirect(route('order.track', $order))
            ->assertSessionHas('viva_status');

        $this->assertSame(1, $createAttempts);
    }

    public function test_second_payment_start_is_blocked_while_the_outcome_is_ambiguous(): void
    {
        $order = $this->vivaOrder([
            'payment_status' => VivaWalletService::PAYMENT_ORDER_OUTCOME_UNKNOWN,
        ]);
        Http::fake();

        $this->startPayment($order)
            ->assertRedirect(route('order.track', $order))
            ->assertSessionHas('viva_status');

        $this->assertSame(VivaWalletService::PAYMENT_ORDER_OUTCOME_UNKNOWN, $order->fresh()->payment_status);
        Http::assertNothingSent();
    }

    public function test_concurrent_local_cancellation_does_not_erase_a_lost_create_outcome(): void
    {
        $order = $this->vivaOrder();
        Cache::put($this->tokenCacheKey(), 'cached-token', 3600);
        Http::fake([
            'https://demo-api.vivapayments.com/checkout/v2/orders' => function () use ($order): never {
                app(CancelOrder::class)->execute($order);

                throw new ConnectionException('response-lost-after-cancellation');
            },
        ]);

        $this->startPayment($order)
            ->assertRedirect(route('order.track', $order))
            ->assertSessionHas('viva_status');

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(VivaWalletService::PAYMENT_ORDER_OUTCOME_UNKNOWN, $order->payment_status);
        $this->assertNull($order->viva_order_code);
    }

    public function test_oauth_connection_failure_before_create_does_not_mark_the_outcome_ambiguous(): void
    {
        $order = $this->vivaOrder();
        $tokenAttempts = 0;
        Http::fake([
            'https://demo-accounts.vivapayments.com/connect/token' => static function () use (&$tokenAttempts): never {
                $tokenAttempts++;

                throw new ConnectionException('token-unreachable');
            },
        ]);

        $this->startPayment($order)
            ->assertRedirect(route('order.track', $order))
            ->assertSessionHas('viva_error')
            ->assertSessionMissing('viva_status');

        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->viva_order_code);
        $this->assertSame(1, $tokenAttempts);
    }

    public function test_normal_successful_create_is_unchanged(): void
    {
        $order = $this->vivaOrder();
        Cache::put($this->tokenCacheKey(), 'cached-token', 3600);
        Http::fake([
            'https://demo-api.vivapayments.com/checkout/v2/orders' => Http::response([
                'orderCode' => self::ORDER_CODE,
            ]),
        ]);

        $this->startPayment($order)
            ->assertRedirect('https://demo.vivapayments.com/web/checkout?ref='.self::ORDER_CODE);

        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame(self::ORDER_CODE, $order->fresh()->viva_order_code);
    }

    public function test_manual_order_code_resolution_reenters_the_existing_reconciliation_path(): void
    {
        $order = $this->vivaOrder([
            'payment_status' => VivaWalletService::PAYMENT_ORDER_OUTCOME_UNKNOWN,
            'created_at' => now()->subMinutes(10),
            'placed_at' => now()->subMinutes(10),
        ]);
        $transactionId = (string) Str::uuid();

        $this->artisan('viva:resolve-ambiguous-payment', [
            'order' => $order->getKey(),
            '--order-code' => self::ORDER_CODE,
        ])->assertExitCode(0);

        $order->refresh();
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame(self::ORDER_CODE, $order->viva_order_code);

        Cache::put($this->tokenCacheKey(), 'cached-token', 3600);
        Http::fake([
            'https://demo.vivapayments.com/api/transactions/?ordercode='.self::ORDER_CODE => Http::response([
                'Success' => true,
                'ErrorCode' => 0,
                'Transactions' => [[
                    'TransactionId' => $transactionId,
                    'Order' => ['OrderCode' => (int) self::ORDER_CODE],
                ]],
            ]),
            'https://demo-api.vivapayments.com/checkout/v2/transactions/'.$transactionId => Http::response([
                'amount' => 5.00,
                'orderCode' => (int) self::ORDER_CODE,
                'statusId' => 'F',
                'currencyCode' => '978',
            ]),
        ]);

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame($transactionId, $order->viva_transaction_id);
        $this->assertNotNull($order->paid_at);
        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'https://demo.vivapayments.com/api/transactions/?ordercode='.self::ORDER_CODE);
    }

    public function test_manual_not_created_resolution_allows_one_fresh_payment_start(): void
    {
        $order = $this->vivaOrder([
            'payment_status' => VivaWalletService::PAYMENT_ORDER_OUTCOME_UNKNOWN,
        ]);

        $this->artisan('viva:resolve-ambiguous-payment', [
            'order' => $order->getKey(),
            '--not-created' => true,
        ])->assertExitCode(0);

        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->viva_order_code);

        Cache::put($this->tokenCacheKey(), 'cached-token', 3600);
        Http::fake([
            'https://demo-api.vivapayments.com/checkout/v2/orders' => Http::response([
                'orderCode' => self::ORDER_CODE,
            ]),
        ]);

        $this->startPayment($order)
            ->assertRedirect('https://demo.vivapayments.com/web/checkout?ref='.self::ORDER_CODE);

        $this->assertSame(self::ORDER_CODE, $order->fresh()->viva_order_code);
        Http::assertSentCount(1);
    }

    public function test_an_expired_payment_order_never_sends_the_customer_back_to_a_dead_checkout(): void
    {
        $order = $this->vivaOrder([
            'payment_status' => 'expired',
            'viva_order_code' => self::ORDER_CODE,
        ]);
        Http::fake();

        $this->startPayment($order)
            ->assertRedirect(route('order.track', $order))
            ->assertSessionHas('viva_error');

        $order->refresh();
        $this->assertSame('expired', $order->payment_status);
        $this->assertSame(self::ORDER_CODE, $order->viva_order_code);
        Http::assertNothingSent();
    }

    public function test_an_ambiguous_order_cannot_be_cancelled_before_manual_resolution(): void
    {
        $order = $this->vivaOrder([
            'payment_status' => VivaWalletService::PAYMENT_ORDER_OUTCOME_UNKNOWN,
        ]);
        Http::fake();

        try {
            app(CancelOrder::class)->execute($order);
            $this->fail('Expected cancellation to be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('δεν έχει επιβεβαιωθεί', $exception->errors()['status'][0]);
        }

        $order->refresh();
        $this->assertSame(OrderStatus::Nea, $order->status);
        $this->assertSame(VivaWalletService::PAYMENT_ORDER_OUTCOME_UNKNOWN, $order->payment_status);
        Http::assertNothingSent();
    }

    private function configureViva(): void
    {
        config()->set('services.viva', [
            'enabled' => true,
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'source_code' => 'test-source',
            'environment' => 'demo',
            'webhook_verification_key' => 'test-verification-key',
            'reconciliation_merchant_id' => 'test-merchant-id',
            'reconciliation_api_key' => 'test-merchant-api-key',
        ]);
    }

    private function vivaOrder(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'payment_method' => PaymentMethod::Viva->value,
            'payment_status' => 'pending',
            'viva_order_code' => null,
            'viva_transaction_id' => null,
            'paid_at' => null,
            'subtotal' => '5.00',
            'total' => '5.00',
        ], $overrides));
    }

    private function startPayment(Order $order)
    {
        return $this->withSession([
            self::SESSION_ORDER_KEY => $order->getRouteKey(),
        ])->get(route('viva.start', $order));
    }

    private function tokenCacheKey(): string
    {
        return 'viva.oauth.'.hash('sha256', 'demo:test-client-id');
    }
}
