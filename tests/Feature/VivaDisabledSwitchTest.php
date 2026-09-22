<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What VIVA_ENABLED=false actually means.
 *
 * The switch stops new payments, not payments already in flight. Turning
 * Viva off while a customer sits on the Smart Checkout page must not lose
 * their money: the webhook stays open so a late confirmation still lands.
 *
 * These tests exist to make that decision explicit and hard to reverse by
 * accident. The realistic shape of the switch is enabled=false with the
 * credentials still in place, which is what each test configures.
 */
class VivaDisabledSwitchTest extends TestCase
{
    use RefreshDatabase;

    private const ORDER_CODE = '7680701046572600';

    private const SESSION_ORDER_KEY = 'latest_public_order_route_key';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->configureViva(enabled: false);
        Http::preventStrayRequests();
    }

    public function test_a_late_payment_still_lands_while_viva_is_disabled(): void
    {
        $order = $this->vivaOrder();
        $transactionId = (string) Str::uuid();

        Http::fake([
            'https://demo-accounts.vivapayments.com/connect/token' => Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => 3600,
            ]),
            'https://demo-api.vivapayments.com/checkout/v2/transactions/'.$transactionId => Http::response([
                'amount' => 5.00,
                'orderCode' => self::ORDER_CODE,
                'statusId' => 'F',
                'currencyCode' => '978',
            ]),
        ]);

        $this->postJson(route('viva.webhook'), [
            'EventTypeId' => 1796,
            'EventData' => ['TransactionId' => $transactionId, 'OrderCode' => self::ORDER_CODE],
        ])->assertOk()->assertJson(['status' => 'paid']);

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame($transactionId, $order->viva_transaction_id);
        $this->assertNotNull($order->paid_at);
    }

    public function test_the_verification_handshake_stays_answerable_while_viva_is_disabled(): void
    {
        $this->get(route('viva.webhook.verify'))
            ->assertOk()
            ->assertExactJson(['Key' => 'test-verification-key']);
    }

    public function test_no_new_payment_order_is_created_while_viva_is_disabled(): void
    {
        $order = $this->vivaOrder(['viva_order_code' => null]);
        Http::fake();

        $this->withSession([self::SESSION_ORDER_KEY => $order->getRouteKey()])
            ->get(route('viva.start', $order))
            ->assertNotFound();

        $this->assertNull($order->fresh()->viva_order_code);
        $this->assertSame('pending', $order->fresh()->payment_status);
        Http::assertNothingSent();
    }

    public function test_an_existing_checkout_cannot_be_resumed_while_viva_is_disabled(): void
    {
        $order = $this->vivaOrder();
        Http::fake();

        $this->withSession([self::SESSION_ORDER_KEY => $order->getRouteKey()])
            ->get(route('viva.start', $order))
            ->assertNotFound();

        Http::assertNothingSent();
    }

    /**
     * A webhook lost while the switch was closed still has to be recoverable,
     * for the same reason the webhook itself stays open. Reconciliation only
     * ever looks at orders that already reached Smart Checkout, so it cannot
     * start a payment the switch was meant to prevent.
     */
    public function test_a_payment_already_in_flight_is_still_reconciled_while_viva_is_disabled(): void
    {
        $order = $this->vivaOrder([
            'created_at' => now()->subMinutes(30),
            'placed_at' => now()->subMinutes(30),
        ]);
        $transactionId = (string) Str::uuid();
        $this->fakeReconciliation($transactionId);

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame($transactionId, $order->viva_transaction_id);
        $this->assertNotNull($order->paid_at);

        // The switch still forbids anything that would start a new payment.
        Http::assertNotSent(fn ($request): bool => $request->url()
            === 'https://demo-api.vivapayments.com/checkout/v2/orders');
    }

    public function test_an_order_that_never_reached_smart_checkout_is_left_alone_while_viva_is_disabled(): void
    {
        $order = $this->vivaOrder([
            'viva_order_code' => null,
            'created_at' => now()->subMinutes(30),
            'placed_at' => now()->subMinutes(30),
        ]);
        Http::fake();

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $order->refresh();
        $this->assertSame('pending', $order->payment_status);
        $this->assertNull($order->viva_order_code);
        Http::assertNothingSent();
    }

    /**
     * Decommissioning: Viva off and the Merchant API credentials removed. A
     * scheduled command must not start failing every five minutes over a
     * integration nobody is using any more.
     */
    public function test_reconciliation_still_opts_out_when_the_credentials_are_gone(): void
    {
        config()->set('services.viva.reconciliation_merchant_id', '');
        config()->set('services.viva.reconciliation_api_key', '');
        $this->vivaOrder([
            'created_at' => now()->subMinutes(30),
            'placed_at' => now()->subMinutes(30),
        ]);
        Http::fake();

        $this->artisan('viva:reconcile-pending-payments')
            ->expectsOutput('Viva payments are disabled; reconciliation skipped.')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    /** Missing credentials while Viva is live stays the loud misconfiguration it was. */
    public function test_missing_credentials_while_viva_is_enabled_is_still_a_failure(): void
    {
        $this->configureViva(enabled: true);
        config()->set('services.viva.reconciliation_merchant_id', '');
        config()->set('services.viva.reconciliation_api_key', '');
        Http::fake();

        $this->artisan('viva:reconcile-pending-payments')
            ->expectsOutput('Viva reconciliation credentials are not configured.')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    private function fakeReconciliation(string $transactionId): void
    {
        Http::fake([
            'https://demo.vivapayments.com/api/transactions/?ordercode='.self::ORDER_CODE => Http::response([
                'Success' => true,
                'ErrorCode' => 0,
                'Transactions' => [[
                    'TransactionId' => $transactionId,
                    'Order' => ['OrderCode' => (int) self::ORDER_CODE],
                ]],
            ]),
            'https://demo-accounts.vivapayments.com/connect/token' => Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => 3600,
            ]),
            'https://demo-api.vivapayments.com/checkout/v2/transactions/'.$transactionId => Http::response([
                'amount' => 5.00,
                'orderCode' => self::ORDER_CODE,
                'statusId' => 'F',
                'currencyCode' => '978',
            ]),
        ]);
    }

    private function configureViva(bool $enabled): void
    {
        config()->set('services.viva', [
            'enabled' => $enabled,
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
            'viva_order_code' => self::ORDER_CODE,
            'viva_transaction_id' => null,
            'paid_at' => null,
            'subtotal' => '5.00',
            'total' => '5.00',
        ], $overrides));
    }
}
