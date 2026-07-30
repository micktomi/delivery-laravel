<?php

namespace Tests\Feature;

use App\Actions\CreateOrder;
use App\Enums\PaymentMethod;
use App\Livewire\CheckoutPage;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The catalogue can move under a cart that has been sitting in a session. The
 * existing guard corrects the cart and asks for a resubmit; the coupon must
 * come through that correction with it, and any discount it stops earning must
 * be explained rather than silently dropped.
 */
class CouponPriceCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $price = '10.00'): Product
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

    private function cart(): CartService
    {
        return app(CartService::class);
    }

    private function addLine(Product $product, int $quantity = 1): void
    {
        $this->cart()->add([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'base_price' => (float) $product->base_price,
            'selected_options' => [],
            'quantity' => $quantity,
            'line_total' => (float) $product->base_price * $quantity,
            'notes' => '',
        ]);
    }

    private function submit(): void
    {
        app(CreateOrder::class)->execute([
            'customer_name' => 'Μιχάλης',
            'phone' => '6912345678',
            'address' => 'Δημοκρατίας 42',
            'floor_bell' => null,
            'notes' => null,
            'payment_method' => PaymentMethod::Cash->value,
        ]);
    }

    /** The guard fires and the cart is rewritten; assert it did both. */
    private function correctCart(): void
    {
        try {
            $this->submit();
            $this->fail('the price change should have corrected the cart');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('τιμοκατάλογος', $e->validator->errors()->first('cart'));
        }
    }

    public function test_the_coupon_survives_a_price_correction_and_is_recomputed(): void
    {
        $product = $this->product('10.00');
        $coupon = Coupon::factory()->percentage('50.00')->create(['code' => 'MISO']);

        $this->addLine($product);
        $this->cart()->applyCoupon($coupon);

        $this->assertSame(5.00, $this->cart()->totals()['discount']);

        $product->update(['base_price' => '8.00']);

        $this->correctCart();

        // replace() must not have overwritten the session payload wholesale.
        $this->assertSame('MISO', $this->cart()->couponCode());
        $this->assertSame(8.00, $this->cart()->items()[0]['line_total']);

        // The corrected screen prices the same coupon against the new subtotal.
        Livewire::test(CheckoutPage::class)
            ->assertSee('Κουπόνι MISO')
            ->assertSee('Έκπτωση (MISO)')
            ->assertSee('8,00 €')
            ->assertSee('4,00 €');

        $this->assertSame(
            ['subtotal' => 8.00, 'discount' => 4.00, 'total' => 4.00],
            $this->cart()->totals(),
        );

        $this->submit();

        $order = Order::first();
        $this->assertSame('MISO', $order->coupon_code);
        $this->assertSame('4.00', $order->discount_amount);
        $this->assertSame('4.00', $order->total);
    }

    public function test_a_correction_below_the_minimum_says_so_instead_of_dropping_the_discount_silently(): void
    {
        $product = $this->product('10.00');
        $coupon = Coupon::factory()->fixed('3.00')->create([
            'code' => 'APO15',
            'min_order_total' => '15.00',
        ]);

        $this->addLine($product, quantity: 2);
        $this->cart()->applyCoupon($coupon);

        // €20 clears the €15 minimum comfortably.
        $this->assertSame(3.00, $this->cart()->totals()['discount']);

        // The catalogue now says €6, so the verified subtotal is €12.
        $product->update(['base_price' => '6.00']);

        $this->correctCart();

        $component = Livewire::test(CheckoutPage::class);

        // The reason is on screen, in the customer's words.
        $component->assertSee('APO15')
            ->assertSee('ισχύει για παραγγελίες από')
            ->assertSee('15,00€')
            // ...and no discount line pretending otherwise.
            ->assertDontSee('Έκπτωση (APO15)');

        $this->assertSame(
            ['subtotal' => 12.00, 'discount' => 0.00, 'total' => 12.00],
            $this->cart()->totals(),
        );

        // The code is still held, so it starts working again if the basket grows.
        $this->assertSame('APO15', $this->cart()->couponCode());

        $this->addLine($product->fresh());

        $this->assertSame(
            ['subtotal' => 18.00, 'discount' => 3.00, 'total' => 15.00],
            $this->cart()->totals(),
        );

        Livewire::test(CheckoutPage::class)->assertSee('Έκπτωση (APO15)');
    }

    public function test_an_order_placed_after_the_minimum_stops_being_met_carries_no_discount(): void
    {
        $product = $this->product('10.00');
        $coupon = Coupon::factory()->fixed('3.00')->create([
            'code' => 'APO15',
            'min_order_total' => '15.00',
        ]);

        $this->addLine($product, quantity: 2);
        $this->cart()->applyCoupon($coupon);

        $product->update(['base_price' => '6.00']);

        $this->correctCart();
        $this->submit();

        $order = Order::first();

        $this->assertSame('12.00', $order->subtotal);
        $this->assertSame('0.00', $order->discount_amount);
        $this->assertNull($order->coupon_code);
        $this->assertSame('12.00', $order->total);
        $this->assertSame(0, $coupon->fresh()->used_count);
    }

    public function test_a_removed_product_corrects_the_cart_without_losing_the_coupon(): void
    {
        $product = $this->product('10.00');
        $other = $this->product('4.00');
        $coupon = Coupon::factory()->fixed('2.00')->create(['code' => 'KALO']);

        $this->addLine($product);
        $this->addLine($other);
        $this->cart()->applyCoupon($coupon);

        $other->update(['is_available' => false]);

        try {
            $this->submit();
            $this->fail('the unavailable product should have corrected the cart');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Δεν είναι πλέον διαθέσιμα', $e->validator->errors()->first('cart'));
        }

        $this->assertSame('KALO', $this->cart()->couponCode());
        $this->assertCount(1, $this->cart()->items());
        $this->assertSame(
            ['subtotal' => 10.00, 'discount' => 2.00, 'total' => 8.00],
            $this->cart()->totals(),
        );
    }
}
