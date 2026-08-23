<?php

namespace Tests\Feature;

use App\Actions\CreateOrder;
use App\Enums\PaymentMethod;
use App\Livewire\CheckoutPage;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreSetting;
use App\Services\CartService;
use App\Services\PricingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CheckoutPageSubmitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('checkout:127.0.0.1');
    }

    private function product(): Product
    {
        $category = Category::firstOrCreate(
            ['slug' => 'kafedes'],
            ['name' => 'Καφέδες', 'sort_order' => 0, 'is_active' => true],
        );

        return Product::firstOrCreate(
            ['category_id' => $category->id, 'name' => 'Freddo Espresso'],
            ['base_price' => '2.80', 'is_available' => true, 'sort_order' => 0],
        );
    }

    private function seedCart(): void
    {
        $product = $this->product();

        app(CartService::class)->add([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'base_price' => 2.80,
            'selected_options' => [],
            'quantity' => 1,
            'line_total' => 2.80,
            'notes' => '',
        ]);
    }

    private function validForm(): array
    {
        return [
            'customer_name' => 'Μιχάλης',
            'phone' => '6912345678',
            'address' => 'Δημοκρατίας 42',
            'payment_method' => PaymentMethod::Cash->value,
        ];
    }

    private function fill(array $overrides = [])
    {
        $component = Livewire::test(CheckoutPage::class);

        foreach (array_merge($this->validForm(), $overrides) as $field => $value) {
            $component->set($field, $value);
        }

        return $component;
    }

    public static function paymentVisualStates(): array
    {
        return [
            'cash' => [PaymentMethod::Cash, 'Μετρητά'],
            'courier POS' => [PaymentMethod::PosCourier, 'POS στον courier'],
            'Viva' => [PaymentMethod::Viva, 'Viva Wallet'],
        ];
    }

    #[DataProvider('paymentVisualStates')]
    public function test_payment_selector_updates_visual_state_and_summary(
        PaymentMethod $method,
        string $label,
    ): void {
        config()->set('services.viva.enabled', true);
        $this->seedCart();

        $component = Livewire::test(CheckoutPage::class)
            ->set('payment_method', $method->value)
            ->assertSee($label);

        $html = $component->html();

        $this->assertMatchesRegularExpression(
            '/data-payment-method="'.preg_quote($method->value, '/').'"\\s+data-payment-selected="true"/',
            $html,
        );
        $this->assertStringContainsString(
            'data-payment-summary="'.$method->value.'"',
            $html,
        );
    }

    public function test_submit_validates_required_customer_fields(): void
    {
        $this->seedCart();

        Livewire::test(CheckoutPage::class)
            ->set('customer_name', '')
            ->set('phone', '')
            ->set('address', '')
            ->call('submit')
            ->assertHasErrors(['customer_name' => 'required'])
            ->assertHasErrors(['phone' => 'required'])
            ->assertHasErrors(['address' => 'required']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_submit_rejects_invalid_payment_method(): void
    {
        $this->seedCart();

        $this->fill(['payment_method' => 'bitcoin'])
            ->call('submit')
            ->assertHasErrors(['payment_method' => 'in']);

        $this->assertDatabaseCount('orders', 0);
    }

    /**
     * NOTE: CheckoutPage::submit() does not perform a Livewire redirect to the
     * tracking page. It only sets $confirmedOrderNumber / $confirmedOrderToken,
     * which switches the Blade view into an in-page confirmation screen
     * containing a manual link to the tracking route. This test documents
     * that actual behavior rather than a redirect.
     */
    public function test_submit_shows_confirmation_state_with_tracking_link_after_success(): void
    {
        $this->seedCart();

        $component = $this->fill()->call('submit')->assertHasNoErrors();

        $order = Order::firstOrFail();

        $this->assertSame($order->getRouteKey(), session('latest_public_order_route_key'));

        $component
            ->assertNoRedirect()
            ->assertSet('confirmedOrderId', $order->id)
            ->assertSet('confirmedOrderNumber', $order->display_number)
            ->assertSet('confirmedOrderToken', $order->public_token)
            ->assertSee(route('order.track', $order));
    }

    /** B5: a delivery order is worthless without a reachable phone and address. */
    public static function invalidCustomerDetails(): array
    {
        return [
            'letters instead of a phone' => [['phone' => 'abcdefgh'], 'phone'],
            'one digit phone' => [['phone' => '1'], 'phone'],
            'too short phone' => [['phone' => '691234567'], 'phone'],
            'foreign phone' => [['phone' => '4915112345678'], 'phone'],
            'address is a dot' => [['address' => '.'], 'address'],
            'address too short' => [['address' => 'Ερμ'], 'address'],
            'single character name' => [['customer_name' => 'x'], 'customer_name'],
            'whitespace only name' => [['customer_name' => '   '], 'customer_name'],
        ];
    }

    #[DataProvider('invalidCustomerDetails')]
    public function test_submit_rejects_undeliverable_customer_details(array $overrides, string $field): void
    {
        $this->seedCart();

        $this->fill($overrides)
            ->call('submit')
            ->assertHasErrors([$field]);

        $this->assertDatabaseCount('orders', 0);
    }

    public static function acceptedPhoneFormats(): array
    {
        return [
            'mobile' => ['6912345678', '6912345678'],
            'landline' => ['2101234567', '2101234567'],
            'with spaces' => ['694 123 4567', '6941234567'],
            'with dashes' => ['694-123-4567', '6941234567'],
            'with country code' => ['+30 6941234567', '6941234567'],
            'with 0030 prefix' => ['00306941234567', '6941234567'],
        ];
    }

    #[DataProvider('acceptedPhoneFormats')]
    public function test_phone_numbers_are_normalised(string $typed, string $stored): void
    {
        $this->seedCart();

        $this->fill(['phone' => $typed])->call('submit')->assertHasNoErrors();

        $this->assertSame($stored, Order::firstOrFail()->phone);
    }

    /** B3: double tap / replayed request must not produce a second order. */
    public function test_submitting_twice_creates_only_one_order(): void
    {
        $this->seedCart();

        $component = $this->fill()->call('submit')->assertHasNoErrors();

        $component->call('submit');
        $component->call('submit');

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
    }

    /** B3: a submit against an emptied cart must not create a 0.00 order. */
    public function test_submitting_with_an_empty_cart_creates_nothing(): void
    {
        $this->seedCart();

        // The page is opened with a cart, which is then emptied underneath it -
        // exactly what a replayed request after a successful order looks like.
        $component = $this->fill();
        app(CartService::class)->clear();

        $component->call('submit')->assertHasErrors(['cart']);

        $this->assertDatabaseCount('orders', 0);
    }

    /** S1: order flooding is throttled per IP. */
    public function test_orders_are_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->seedCart();
            $this->fill()->call('submit')->assertHasNoErrors();
        }

        $this->assertDatabaseCount('orders', 5);

        $this->seedCart();
        $this->fill()->call('submit')->assertHasErrors(['checkout']);

        $this->assertDatabaseCount('orders', 5);
    }

    public function test_closed_store_rejects_checkout_without_order_or_viva_flow(): void
    {
        StoreSetting::current()->update([
            'accepting_orders' => false,
            'closed_message' => 'Το κατάστημα έκλεισε για σήμερα.',
        ]);
        config()->set('services.viva.enabled', true);
        Http::fake();
        $this->seedCart();

        $this->fill(['payment_method' => PaymentMethod::Viva->value])
            ->call('submit')
            ->assertHasErrors(['checkout'])
            ->assertNoRedirect()
            ->assertSee('Το κατάστημα έκλεισε για σήμερα.');

        $this->assertDatabaseCount('orders', 0);
        $this->assertFalse(app(CartService::class)->isEmpty());
        Http::assertNothingSent();
    }

    public function test_open_schedule_preserves_current_checkout_behaviour(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-08-17 10:00', 'Europe/Athens'),
        );
        StoreSetting::current()->update([
            'accepting_orders' => true,
            'opening_hours' => [
                'monday' => [['09:00', '11:00']],
            ],
        ]);
        $this->seedCart();

        try {
            $this->fill(['payment_method' => PaymentMethod::PosCourier->value])
                ->call('submit')
                ->assertHasNoErrors()
                ->assertNoRedirect();

            $this->assertSame(PaymentMethod::PosCourier, Order::firstOrFail()->payment_method);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /** S6: an unexpected failure is logged, shown safely, and keeps the cart. */
    public function test_unexpected_failures_do_not_leak_details_and_keep_the_cart(): void
    {
        $this->seedCart();

        $this->swap(CreateOrder::class, new class(app(CartService::class), app(PricingService::class)) extends CreateOrder
        {
            public function execute(array $checkoutData): Order
            {
                throw new RuntimeException('SQLSTATE[HY000] connection refused to 10.0.0.5');
            }
        });

        $component = $this->fill()->call('submit')->assertHasErrors(['checkout']);

        $component->assertDontSee('SQLSTATE');
        $component->assertDontSee('10.0.0.5');

        $this->assertDatabaseCount('orders', 0);
        $this->assertFalse(app(CartService::class)->isEmpty());
    }
}
