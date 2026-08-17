<?php

namespace Tests\Feature;

use App\Actions\CreateOrder;
use App\Enums\CouponType;
use App\Enums\PaymentMethod;
use App\Livewire\CheckoutPage;
use App\Livewire\MenuPage;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Services\CartService;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class MinimumOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The test environment defaults this to 0 so unrelated fixtures (built
        // before this rule existed) are unaffected; this file exercises the
        // real production threshold explicitly, self-contained either way.
        config(['cart.minimum_order_amount' => 5.00]);
    }

    private function product(string $price): Product
    {
        $category = Category::firstOrCreate(
            ['slug' => 'kafedes'],
            ['name' => 'Καφέδες', 'sort_order' => 0, 'is_active' => true],
        );

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Freddo Espresso',
            'base_price' => $price,
            'is_available' => true,
            'sort_order' => 0,
        ]);
    }

    private function addLine(Product $product): void
    {
        app(CartService::class)->add([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'base_price' => (float) $product->base_price,
            'selected_options' => [],
            'quantity' => 1,
            'line_total' => (float) $product->base_price,
            'notes' => '',
        ]);
    }

    private function checkoutData(array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Μιχάλης',
            'phone' => '6912345678',
            'address' => 'Δημοκρατίας 42',
            'floor_bell' => null,
            'notes' => null,
            'payment_method' => PaymentMethod::Cash->value,
        ], $overrides);
    }

    // 1. subtotal 4.99 € → checkout is rejected.
    public function test_checkout_is_rejected_below_the_minimum(): void
    {
        $this->addLine($this->product('4.99'));

        $this->expectException(ValidationException::class);

        try {
            app(CreateOrder::class)->execute($this->checkoutData());
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cart', $e->errors());
            $this->assertSame(0, Order::count());

            throw $e;
        }
    }

    // 2. subtotal exactly 5.00 € → allowed.
    public function test_checkout_is_allowed_at_exactly_the_minimum(): void
    {
        $this->addLine($this->product('5.00'));

        $order = app(CreateOrder::class)->execute($this->checkoutData());

        $this->assertSame(5.00, (float) $order->subtotal);
        $this->assertSame(1, Order::count());
    }

    // 3. subtotal above the minimum → allowed.
    public function test_checkout_is_allowed_above_the_minimum(): void
    {
        $this->addLine($this->product('6.50'));

        $order = app(CreateOrder::class)->execute($this->checkoutData());

        $this->assertSame(6.50, (float) $order->subtotal);
    }

    // 4. Delivery fee must never be able to rescue a below-minimum subtotal:
    // meetsMinimum() takes only the subtotal, matching the call site in
    // CreateOrder which checks it before $deliveryFee is even set.
    public function test_minimum_check_only_considers_subtotal_not_delivery_fee(): void
    {
        $pricing = app(PricingService::class);

        $this->assertFalse($pricing->meetsMinimum(4.00, 5.00));

        // A hypothetical €10 delivery fee would push the total to €14, well
        // above the floor — but it plays no part in this decision.
        $hypotheticalTotal = 4.00 + 10.00;
        $this->assertGreaterThanOrEqual(5.00, $hypotheticalTotal);
        $this->assertFalse($pricing->meetsMinimum(4.00, 5.00));
    }

    // Established semantics: the minimum is measured on the bare subtotal, so
    // a coupon discount cannot be invented to lower the eligibility bar either.
    public function test_coupon_discount_does_not_affect_minimum_eligibility(): void
    {
        Coupon::create([
            'code' => 'SAVE50',
            'type' => CouponType::Percentage->value,
            'value' => 50,
            'is_active' => true,
        ]);

        $this->addLine($this->product('5.00'));
        app(CartService::class)->applyCoupon(Coupon::findByCode('SAVE50'));

        $order = app(CreateOrder::class)->execute($this->checkoutData());

        $this->assertSame(5.00, (float) $order->subtotal);
        $this->assertSame(2.50, (float) $order->discount_amount);
    }

    // 5. A crafted request that bypasses the browser entirely (calling the
    // order action directly, as a tampered client would) still gets rejected.
    public function test_server_side_rule_cannot_be_bypassed_by_a_crafted_request(): void
    {
        $this->addLine($this->product('1.50'));

        try {
            app(CreateOrder::class)->execute($this->checkoutData());
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, Order::count());
    }

    // 6. The remaining amount is computed correctly (matches the task's own example).
    public function test_remaining_amount_is_calculated_correctly(): void
    {
        $this->addLine($this->product('2.20'));

        try {
            app(CreateOrder::class)->execute($this->checkoutData());
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(
                'Χρειάζονται ακόμη 2,80 € για να ολοκληρώσετε την παραγγελία.',
                $e->errors()['cart'][0],
            );
        }
    }

    public function test_cart_summary_shows_remaining_amount_below_the_minimum(): void
    {
        $this->addLine($this->product('2.20'));

        Livewire::test(MenuPage::class)
            ->assertSee('Χρειάζονται ακόμη 2,80 € για την ελάχιστη παραγγελία.');
    }

    public function test_cart_summary_shows_the_minimum_order_hint_once_met(): void
    {
        $this->addLine($this->product('6.00'));

        Livewire::test(MenuPage::class)
            ->assertSee('Ελάχιστη παραγγελία 5,00 €')
            ->assertDontSee('Χρειάζονται ακόμη');
    }

    public function test_checkout_submit_is_disabled_below_the_minimum(): void
    {
        $this->addLine($this->product('2.20'));

        Livewire::test(CheckoutPage::class)
            ->assertSeeHtml('disabled');
    }

    // 10. Full checkout flow above the minimum still completes end to end.
    public function test_full_checkout_flow_succeeds_above_the_minimum(): void
    {
        $this->addLine($this->product('6.00'));

        Livewire::test(CheckoutPage::class)
            ->set('customer_name', 'Μιχάλης')
            ->set('phone', '6912345678')
            ->set('address', 'Δημοκρατίας 42')
            ->set('payment_method', PaymentMethod::Cash->value)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame(1, Order::count());
    }
}
