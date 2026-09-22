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
     * The asymmetry worth knowing about: a webhook that never arrives while
     * Viva is disabled is not recovered automatically, because the scheduled
     * reconciliation opts out of a disabled integration entirely.
     */
    public function test_reconciliation_opts_out_entirely_while_viva_is_disabled(): void
    {
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
