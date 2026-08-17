<?php

namespace Tests\Feature;

use App\Actions\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Livewire\CheckoutPage;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class VivaWalletScaffoldTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION_ORDER_KEY = 'latest_public_order_route_key';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        RateLimiter::clear('checkout:127.0.0.1');
        $this->configureViva(false);
    }

    public function test_viva_disabled_keeps_the_existing_cash_checkout_unchanged(): void
    {
        $this->seedCart();

        $component = Livewire::test(CheckoutPage::class)
            ->assertDontSee('ΚΑΡΤΑ ONLINE (VIVA)')
            ->set('customer_name', 'Μιχάλης')
            ->set('phone', '6912345678')
            ->set('address', 'Δημοκρατίας 42')
            ->set('payment_method', PaymentMethod::Cash->value)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $order = Order::firstOrFail();

        $component->assertSet('confirmedOrderId', $order->getKey());
        $this->assertSame(PaymentMethod::Cash, $order->payment_method);
        $this->assertNull($order->payment_status);
    }

    public function test_viva_cannot_be_selected_or_started_when_disabled(): void
    {
        $this->seedCart();

        Livewire::test(CheckoutPage::class)
            ->set('customer_name', 'Μιχάλης')
            ->set('phone', '6912345678')
            ->set('address', 'Δημοκρατίας 42')
            ->set('payment_method', PaymentMethod::Viva->value)
            ->call('submit')
            ->assertHasErrors(['payment_method' => 'in']);

        $order = $this->vivaOrder(['viva_order_code' => null]);

        $this->withSession([self::SESSION_ORDER_KEY => $order->getRouteKey()])
            ->get(route('viva.start', $order))
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_enabled_checkout_creates_a_pending_viva_order_and_redirects_to_start(): void
    {
        $this->configureViva(true);
        $this->seedCart();

        $component = Livewire::test(CheckoutPage::class)
            ->assertSee('ΚΑΡΤΑ ONLINE (VIVA)')
            ->set('customer_name', 'Μιχάλης')
            ->set('phone', '6912345678')
            ->set('address', 'Δημοκρατίας 42')
            ->set('payment_method', PaymentMethod::Viva->value)
            ->call('submit')
            ->assertHasNoErrors();

        $order = Order::firstOrFail();

        $component->assertRedirect(route('viva.start', $order));
        $this->assertSame('pending', $order->payment_status);
        $this->assertNull($order->viva_order_code);
        $this->assertSame($order->getRouteKey(), session(self::SESSION_ORDER_KEY));
    }

    public function test_start_uses_the_server_total_and_redirects_to_smart_checkout(): void
    {
        $this->configureViva(true);
        $order = $this->vivaOrder(['viva_order_code' => null]);
        $orderCode = '7680701046572600';

        Http::fake([
            'https://demo-accounts.vivapayments.com/connect/token' => Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => 3600,
            ]),
            'https://demo-api.vivapayments.com/checkout/v2/orders' => Http::response([
                'orderCode' => $orderCode,
            ]),
        ]);

        $this->withSession([self::SESSION_ORDER_KEY => $order->getRouteKey()])
            ->get(route('viva.start', $order))
            ->assertRedirect('https://demo.vivapayments.com/web/checkout?ref='.$orderCode);

        $this->assertDatabaseHas('orders', [
            'id' => $order->getKey(),
            'payment_status' => 'pending',
            'viva_order_code' => $orderCode,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://demo-api.vivapayments.com/checkout/v2/orders'
            && $request['amount'] === 500
            && $request['sourceCode'] === 'test-source'
        );
    }

    public function test_start_requires_the_customer_session_even_with_a_valid_order_token(): void
    {
        $this->configureViva(true);
        $order = $this->vivaOrder(['viva_order_code' => null]);

        $this->withSession([self::SESSION_ORDER_KEY => Str::random(40)])
            ->get(route('viva.start', $order))
            ->assertNotFound();

        $this->assertSame('pending', $order->fresh()->payment_status);
        Http::assertNothingSent();
    }

    public function test_viva_api_failure_keeps_the_pending_order_and_reports_no_success(): void
    {
        $this->configureViva(true);
        $order = $this->vivaOrder(['viva_order_code' => null]);

        Http::fake([
            'https://demo-accounts.vivapayments.com/connect/token' => Http::response([
                'error' => 'temporarily_unavailable',
            ], 503),
        ]);

        $this->withSession([self::SESSION_ORDER_KEY => $order->getRouteKey()])
            ->get(route('viva.start', $order))
            ->assertRedirect(route('order.track', $order))
            ->assertSessionHas('viva_error');

        $order->refresh();
        $this->assertSame('pending', $order->payment_status);
        $this->assertNull($order->viva_order_code);
        $this->assertNull($order->viva_transaction_id);
        $this->assertNull($order->paid_at);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_success_redirect_alone_never_marks_an_order_paid(): void
    {
        $this->configureViva(true);
        $order = $this->vivaOrder();

        $this->withSession([self::SESSION_ORDER_KEY => $order->getRouteKey()])
            ->get(route('viva.success', [
                's' => $order->viva_order_code,
                't' => (string) Str::uuid(),
            ]))
            ->assertRedirect(route('order.track', $order));

        $order->refresh();
        $this->assertSame('pending', $order->payment_status);
        $this->assertNull($order->viva_transaction_id);
        $this->assertNull($order->paid_at);
        Http::assertNothingSent();
    }

    public function test_webhook_get_verification_returns_the_configured_key(): void
    {
        config()->set('services.viva.webhook_verification_key', 'test-verification-key');

        $this->get(route('viva.webhook.verify'))
            ->assertOk()
            ->assertExactJson(['Key' => 'test-verification-key']);
    }

    public function test_valid_server_side_confirmation_marks_the_matching_order_paid(): void
    {
        $this->configureViva(true);
        $order = $this->vivaOrder();
        $transactionId = (string) Str::uuid();
        $this->fakeVerifiedTransaction($transactionId, $order->viva_order_code, 5.00);

        $this->postJson(route('viva.webhook'), $this->webhookPayload($transactionId, $order->viva_order_code))
            ->assertOk()
            ->assertJson(['status' => 'paid']);

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame($transactionId, $order->viva_transaction_id);
        $this->assertNotNull($order->paid_at);
    }

    public function test_duplicate_webhook_is_idempotent(): void
    {
        $this->configureViva(true);
        $order = $this->vivaOrder();
        $transactionId = (string) Str::uuid();
        $payload = $this->webhookPayload($transactionId, $order->viva_order_code);
        $this->fakeVerifiedTransaction($transactionId, $order->viva_order_code, 5.00);

        $this->postJson(route('viva.webhook'), $payload)
            ->assertOk()
            ->assertJson(['status' => 'paid']);

        $order->refresh();
        $paidAt = $order->paid_at?->toDateTimeString();
        $updatedAt = $order->updated_at->toDateTimeString();

        $this->travel(1)->minute();

        $this->postJson(route('viva.webhook'), $payload)
            ->assertOk()
            ->assertJson(['status' => 'duplicate']);

        $order->refresh();
        $this->assertSame($paidAt, $order->paid_at?->toDateTimeString());
        $this->assertSame($updatedAt, $order->updated_at->toDateTimeString());
        $this->assertSame(1, Order::where('viva_transaction_id', $transactionId)->count());
    }

    public function test_wrong_order_or_server_verified_amount_is_not_marked_paid(): void
    {
        $this->configureViva(true);
        $wrongOrder = $this->vivaOrder(['viva_order_code' => '7680701046572601']);
        $wrongAmount = $this->vivaOrder(['viva_order_code' => '7680701046572602']);
        $wrongOrderTransaction = (string) Str::uuid();
        $wrongAmountTransaction = (string) Str::uuid();

        Http::fake([
            'https://demo-accounts.vivapayments.com/connect/token' => Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => 3600,
            ]),
            'https://demo-api.vivapayments.com/checkout/v2/transactions/'.$wrongOrderTransaction => Http::response([
                'amount' => 5.00,
                'orderCode' => '9999999999999999',
                'statusId' => 'F',
                'currencyCode' => '978',
            ]),
            'https://demo-api.vivapayments.com/checkout/v2/transactions/'.$wrongAmountTransaction => Http::response([
                'amount' => 4.99,
                'orderCode' => $wrongAmount->viva_order_code,
                'statusId' => 'F',
                'currencyCode' => '978',
            ]),
        ]);

        $this->postJson(
            route('viva.webhook'),
            $this->webhookPayload($wrongOrderTransaction, $wrongOrder->viva_order_code),
        )->assertOk()->assertJson(['status' => 'ignored']);

        $this->postJson(
            route('viva.webhook'),
            $this->webhookPayload($wrongAmountTransaction, $wrongAmount->viva_order_code),
        )->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame('pending', $wrongOrder->fresh()->payment_status);
        $this->assertSame('pending', $wrongAmount->fresh()->payment_status);
        $this->assertDatabaseMissing('orders', ['payment_status' => 'paid']);
    }

    public function test_cash_orders_still_work_when_viva_is_enabled(): void
    {
        $this->configureViva(true);
        $this->seedCart();

        Livewire::test(CheckoutPage::class)
            ->set('customer_name', 'Μιχάλης')
            ->set('phone', '6912345678')
            ->set('address', 'Δημοκρατίας 42')
            ->set('payment_method', PaymentMethod::Cash->value)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $order = Order::firstOrFail();
        $this->assertSame(PaymentMethod::Cash, $order->payment_method);
        $this->assertNull($order->payment_status);
        $this->assertTrue($order->isReadyForFulfilment());
    }

    public function test_pending_viva_orders_cannot_enter_fulfilment(): void
    {
        $pending = $this->vivaOrder();
        $paid = $this->vivaOrder([
            'viva_order_code' => '7680701046572609',
            'payment_status' => 'paid',
            'viva_transaction_id' => (string) Str::uuid(),
            'paid_at' => now(),
        ]);

        $this->assertFalse($pending->isReadyForFulfilment());
        $this->assertTrue($paid->isReadyForFulfilment());
        $this->assertFalse(Order::readyForFulfilment()->whereKey($pending)->exists());
        $this->assertTrue(Order::readyForFulfilment()->whereKey($paid)->exists());

        $this->expectException(ValidationException::class);
        app(TransitionOrderStatus::class)->execute($pending, OrderStatus::Nea);
    }

    private function configureViva(bool $enabled): void
    {
        config()->set('services.viva', [
            'enabled' => $enabled,
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'source_code' => 'test-source',
            'environment' => 'demo',
        ]);
    }

    private function seedCart(): void
    {
        $category = Category::create([
            'name' => 'Καφέδες',
            'slug' => 'kafedes',
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $product = Product::create([
            'category_id' => $category->getKey(),
            'name' => 'Freddo Espresso',
            'base_price' => '2.80',
            'is_available' => true,
            'sort_order' => 0,
        ]);

        app(CartService::class)->add([
            'product_id' => $product->getKey(),
            'product_name' => $product->name,
            'base_price' => 2.80,
            'selected_options' => [],
            'quantity' => 1,
            'line_total' => 2.80,
            'notes' => '',
        ]);
    }

    private function vivaOrder(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'payment_method' => PaymentMethod::Viva->value,
            'payment_status' => 'pending',
            'viva_order_code' => '7680701046572600',
            'subtotal' => '5.00',
            'total' => '5.00',
        ], $overrides));
    }

    private function fakeVerifiedTransaction(string $transactionId, string $orderCode, float $amount): void
    {
        Http::fake([
            'https://demo-accounts.vivapayments.com/connect/token' => Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => 3600,
            ]),
            'https://demo-api.vivapayments.com/checkout/v2/transactions/'.$transactionId => Http::response([
                'amount' => $amount,
                'orderCode' => $orderCode,
                'statusId' => 'F',
                'currencyCode' => '978',
            ]),
        ]);
    }

    private function webhookPayload(string $transactionId, string $orderCode): array
    {
        return [
            'EventTypeId' => 1796,
            'EventData' => [
                'TransactionId' => $transactionId,
                'OrderCode' => $orderCode,
                'Amount' => 5.00,
                'StatusId' => 'F',
                'CurrencyCode' => '978',
            ],
        ];
    }
}
