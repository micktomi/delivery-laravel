<?php

namespace Tests\Feature;

use App\Actions\CreateOrder;
use App\Enums\PaymentMethod;
use App\Livewire\CheckoutPage;
use App\Livewire\OrderBoard;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class OrderCouponTest extends TestCase
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

    private function placeOrder(): Order
    {
        return app(CreateOrder::class)->execute($this->checkoutData());
    }

    public function test_a_percentage_coupon_is_applied_and_snapshotted(): void
    {
        $product = $this->product('12.40');
        $coupon = Coupon::factory()->percentage('15.00')->create(['code' => 'WELCOME15']);

        $this->addLine($product);
        $this->cart()->applyCoupon($coupon);

        $order = $this->placeOrder();

        $this->assertSame('12.40', $order->subtotal);
        $this->assertSame('1.86', $order->discount_amount);
        $this->assertSame('10.54', $order->total);
        $this->assertSame('WELCOME15', $order->coupon_code);
        $this->assertSame($coupon->id, $order->coupon_id);
        $this->assertSame('0.00', $order->delivery_fee);
    }

    public function test_a_fixed_coupon_is_applied_and_snapshotted(): void
    {
        $product = $this->product('10.00');
        $coupon = Coupon::factory()->fixed('2.50')->create(['code' => 'MINUS250']);

        $this->addLine($product);
        $this->cart()->applyCoupon($coupon);

        $order = $this->placeOrder();

        $this->assertSame('2.50', $order->discount_amount);
        $this->assertSame('7.50', $order->total);
        $this->assertSame('MINUS250', $order->coupon_code);
    }

    public function test_a_fixed_discount_never_exceeds_the_subtotal(): void
    {
        $product = $this->product('4.00');
        $coupon = Coupon::factory()->fixed('10.00')->create(['code' => 'MEGA']);

        $this->addLine($product);
        $this->cart()->applyCoupon($coupon);

        $order = $this->placeOrder();

        $this->assertSame('4.00', $order->discount_amount);
        $this->assertSame('0.00', $order->total);
    }

    public function test_an_order_without_a_coupon_stores_no_discount(): void
    {
        $this->addLine($this->product('10.00'));

        $order = $this->placeOrder();

        $this->assertNull($order->coupon_code);
        $this->assertNull($order->coupon_id);
        $this->assertSame('0.00', $order->discount_amount);
        $this->assertSame('10.00', $order->total);
    }

    public function test_used_count_moves_exactly_once_per_order(): void
    {
        $product = $this->product('10.00');
        $coupon = Coupon::factory()->fixed('1.00')->create(['max_uses' => 5]);

        $this->addLine($product);
        $this->cart()->applyCoupon($coupon);
        $this->placeOrder();

        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_a_coupon_that_expires_between_cart_and_submit_costs_the_discount_not_the_order(): void
    {
        $product = $this->product('10.00');
        $coupon = Coupon::factory()->fixed('2.00')->create(['code' => 'PALIO']);

        $this->addLine($product);
        $this->cart()->applyCoupon($coupon);

        // The minutes between typing the code and pressing the button.
        $coupon->forceFill(['expires_at' => now()->subMinute()])->save();

        $order = $this->placeOrder();

        $this->assertSame('0.00', $order->discount_amount);
        $this->assertNull($order->coupon_code);
        $this->assertNull($order->coupon_id);
        $this->assertSame('10.00', $order->total);
        $this->assertSame(0, $coupon->fresh()->used_count);
    }

    public function test_a_coupon_deactivated_between_cart_and_submit_costs_the_discount_not_the_order(): void
    {
        $product = $this->product('10.00');
        $coupon = Coupon::factory()->fixed('2.00')->create();

        $this->addLine($product);
        $this->cart()->applyCoupon($coupon);

        $coupon->forceFill(['is_active' => false])->save();

        $order = $this->placeOrder();

        $this->assertSame('0.00', $order->discount_amount);
        $this->assertNull($order->coupon_code);
        $this->assertSame('10.00', $order->total);
        $this->assertSame(0, $coupon->fresh()->used_count);
    }

    public function test_a_coupon_used_up_between_cart_and_submit_costs_the_discount_not_the_order(): void
    {
        $product = $this->product('10.00');
        $coupon = Coupon::factory()->fixed('2.00')->create(['max_uses' => 1]);

        $this->addLine($product);
        $this->cart()->applyCoupon($coupon);

        // Someone else redeemed the last use.
        $coupon->forceFill(['used_count' => 1])->save();

        $order = $this->placeOrder();

        $this->assertSame('0.00', $order->discount_amount);
        $this->assertNull($order->coupon_code);
        $this->assertSame('10.00', $order->total);
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_a_coupon_deleted_between_cart_and_submit_costs_the_discount_not_the_order(): void
    {
        $product = $this->product('10.00');
        $coupon = Coupon::factory()->fixed('2.00')->create();

        $this->addLine($product);
        $this->cart()->applyCoupon($coupon);

        $coupon->delete();

        $order = $this->placeOrder();

        $this->assertSame('0.00', $order->discount_amount);
        $this->assertNull($order->coupon_code);
        $this->assertSame('10.00', $order->total);
    }

    /**
     * The real race: both requests read a coupon with one use left, so both
     * pass validation. Only the UPDATE decides, and the loser pays full price.
     */
    public function test_losing_the_atomic_claim_costs_the_discount_not_the_order(): void
    {
        $product = $this->product('10.00');
        $coupon = Coupon::factory()->fixed('2.00')->create(['max_uses' => 1]);

        $this->addLine($product);
        $this->cart()->applyCoupon($coupon);

        // The other customer's claim lands after this request read the row and
        // before it writes: the model in memory still says one use is left.
        $raced = false;
        Coupon::retrieved(function (Coupon $loaded) use (&$raced) {
            if ($raced) {
                return;
            }
            $raced = true;
            DB::table('coupons')->where('id', $loaded->id)->update(['used_count' => 1]);
        });

        $order = $this->placeOrder();

        $this->assertTrue($raced, 'the simulated race never ran');
        $this->assertSame('0.00', $order->discount_amount);
        $this->assertNull($order->coupon_code);
        $this->assertNull($order->coupon_id);
        $this->assertSame('10.00', $order->total);
        // Still exactly the one use the other customer took.
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_the_minimum_is_checked_against_the_server_verified_subtotal(): void
    {
        $product = $this->product('10.00');
        $coupon = Coupon::factory()->fixed('3.00')->create(['code' => 'APO15', 'min_order_total' => '15.00']);

        $this->addLine($product, quantity: 2);
        $this->cart()->applyCoupon($coupon);

        // Cart says €20 and the coupon qualifies; the catalogue now says €6.
        $product->update(['base_price' => '6.00']);

        // The existing guard corrects the cart and asks for a resubmit.
        try {
            $this->placeOrder();
            $this->fail('the corrected cart should have been shown to the customer');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('τιμοκατάλογος', $e->validator->errors()->first('cart'));
        }

        $order = $this->placeOrder();

        // €12 is the authoritative subtotal, and it is under the €15 minimum.
        $this->assertSame('12.00', $order->subtotal);
        $this->assertSame('0.00', $order->discount_amount);
        $this->assertNull($order->coupon_code);
        $this->assertSame('12.00', $order->total);
        $this->assertSame(0, $coupon->fresh()->used_count);
    }

    public function test_the_discount_is_computed_on_the_current_server_price(): void
    {
        $product = $this->product('10.00');
        $coupon = Coupon::factory()->percentage('50.00')->create();

        $this->addLine($product);
        $this->cart()->applyCoupon($coupon);

        $product->update(['base_price' => '8.00']);

        try {
            $this->placeOrder();
        } catch (ValidationException) {
            // Expected: the cart is corrected first.
        }

        $order = $this->placeOrder();

        $this->assertSame('8.00', $order->subtotal);
        $this->assertSame('4.00', $order->discount_amount);
        $this->assertSame('4.00', $order->total);
    }

    public function test_a_successful_order_clears_the_cart_and_the_coupon(): void
    {
        $product = $this->product('10.00');
        $coupon = Coupon::factory()->fixed('2.00')->create();

        $this->addLine($product);
        $this->cart()->applyCoupon($coupon);

        $this->placeOrder();

        $this->assertTrue($this->cart()->isEmpty());
        $this->assertNull($this->cart()->couponCode());
    }

    /**
     * A rolled back order must leave the customer holding exactly what they
     * had — basket, coupon and the coupon's usage count.
     */
    public function test_a_failed_transaction_rolls_back_the_claim_and_keeps_the_cart(): void
    {
        $product = $this->product('10.00');
        $coupon = Coupon::factory()->fixed('2.00')->create(['max_uses' => 5]);

        $this->addLine($product);
        $this->cart()->applyCoupon($coupon);

        OrderItem::creating(function () {
            throw new RuntimeException('write failed');
        });

        try {
            $this->placeOrder();
            $this->fail('the order should have failed');
        } catch (RuntimeException $e) {
            $this->assertSame('write failed', $e->getMessage());
        }

        $this->assertSame(0, Order::count());
        $this->assertSame(0, $coupon->fresh()->used_count);
        $this->assertCount(1, $this->cart()->items());
        $this->assertSame($coupon->code, $this->cart()->couponCode());
    }

    public function test_a_double_submit_creates_one_order_and_claims_once(): void
    {
        $product = $this->product('10.00');
        $coupon = Coupon::factory()->fixed('2.00')->create(['max_uses' => 5]);

        $this->addLine($product);
        $this->cart()->applyCoupon($coupon);

        Livewire::test(CheckoutPage::class)
            ->set('customer_name', 'Μιχάλης')
            ->set('phone', '6912345678')
            ->set('address', 'Δημοκρατίας 42')
            ->call('submit')
            ->call('submit');

        $this->assertSame(1, Order::count());
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_the_confirmation_says_when_a_coupon_was_lost_at_submit(): void
    {
        $product = $this->product('10.00');
        $coupon = Coupon::factory()->fixed('2.00')->create(['code' => 'PALIO']);

        $this->addLine($product);
        $this->cart()->applyCoupon($coupon);
        $coupon->forceFill(['expires_at' => now()->subMinute()])->save();

        Livewire::test(CheckoutPage::class)
            ->set('customer_name', 'Μιχάλης')
            ->set('phone', '6912345678')
            ->set('address', 'Δημοκρατίας 42')
            ->call('submit')
            ->assertSet('droppedCouponCode', 'PALIO')
            ->assertSee('δεν ίσχυε πλέον');

        $this->assertSame(1, Order::count());
        $this->assertNull(Order::first()->coupon_code);
    }

    public function test_the_checkout_shows_the_applied_coupon_and_the_three_lines(): void
    {
        $product = $this->product('12.40');
        $coupon = Coupon::factory()->percentage('15.00')->create(['code' => 'WELCOME15']);

        $this->addLine($product);
        $this->cart()->applyCoupon($coupon);

        Livewire::test(CheckoutPage::class)
            ->assertSee('Υποσύνολο')
            ->assertSee('12,40 €')
            ->assertSee('Έκπτωση (WELCOME15)')
            ->assertSee('1,86 €')
            ->assertSee('Σύνολο')
            ->assertSee('10,54 €');
    }

    public function test_the_checkout_can_remove_the_coupon_and_takes_no_new_code(): void
    {
        $product = $this->product('10.00');
        $coupon = Coupon::factory()->fixed('2.00')->create(['code' => 'KALO']);

        $this->addLine($product);
        $this->cart()->applyCoupon($coupon);

        $component = Livewire::test(CheckoutPage::class)
            ->assertSee('Κουπόνι KALO')
            ->assertDontSee('Κωδικός κουπονιού');

        $component->call('removeCoupon');

        $this->assertNull($this->cart()->couponCode());
        $this->assertSame(10.00, $this->cart()->totals()['total']);

        $order = $this->placeOrder();

        $this->assertNull($order->coupon_code);
        $this->assertSame('10.00', $order->total);
    }

    public function test_the_kitchen_board_hides_financial_details(): void
    {
        $product = $this->product('12.40');
        $coupon = Coupon::factory()->percentage('15.00')->create(['code' => 'WELCOME15']);

        $this->addLine($product);
        $this->cart()->applyCoupon($coupon);
        $this->placeOrder();

        Livewire::test(OrderBoard::class)
            ->assertDontSee('Υποσύνολο')
            ->assertDontSee('Έκπτωση (WELCOME15)')
            ->assertDontSee('Προς είσπραξη')
            ->assertDontSee('12,40 €')
            ->assertDontSee('10,54 €');
    }

    public function test_the_tracking_page_shows_subtotal_discount_and_total(): void
    {
        $product = $this->product('12.40');
        $coupon = Coupon::factory()->percentage('15.00')->create(['code' => 'WELCOME15']);

        $this->addLine($product);
        $this->cart()->applyCoupon($coupon);
        $order = $this->placeOrder();

        $this->get(route('order.track', $order->getRouteKey()))
            ->assertOk()
            ->assertSee('Υποσύνολο')
            ->assertSee('12,40 €')
            ->assertSee('Έκπτωση (WELCOME15)')
            ->assertSee('1,86 €')
            ->assertSee('10,54 €');
    }

    /**
     * The snapshot principle: the order is what it was when it was placed, no
     * matter what happens to the coupon afterwards.
     */
    public function test_editing_or_deleting_the_coupon_later_leaves_the_order_untouched(): void
    {
        $product = $this->product('12.40');
        $coupon = Coupon::factory()->percentage('15.00')->create(['code' => 'WELCOME15']);

        $this->addLine($product);
        $this->cart()->applyCoupon($coupon);
        $order = $this->placeOrder();

        $coupon->update(['value' => '5.00', 'code' => 'ALLO', 'is_active' => false]);

        $order->refresh();
        $this->assertSame('1.86', $order->discount_amount);
        $this->assertSame('10.54', $order->total);
        $this->assertSame('WELCOME15', $order->coupon_code);

        $coupon->delete();

        $order->refresh();
        $this->assertSame('1.86', $order->discount_amount);
        $this->assertSame('10.54', $order->total);
        $this->assertSame('WELCOME15', $order->coupon_code);
        // Only the reporting convenience goes; the money does not.
        $this->assertNull($order->coupon_id);
    }
}
