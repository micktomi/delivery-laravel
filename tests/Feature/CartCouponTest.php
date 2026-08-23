<?php

namespace Tests\Feature;

use App\Livewire\MenuPage;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class CartCouponTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION_ONE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const SESSION_TWO = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private function product(string $price = '10.00'): Product
    {
        $category = Category::create([
            'name' => 'Καφέδες '.uniqid(),
            'slug' => 'kafedes-'.uniqid(),
            'sort_order' => 0,
            'is_active' => true,
        ]);

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

    public function test_a_valid_code_discounts_the_cart(): void
    {
        $product = $this->product('10.00');
        Coupon::factory()->percentage('15.00')->create(['code' => 'WELCOME15']);

        $component = Livewire::test(MenuPage::class)
            ->call('addDirectly', $product->id)
            ->set('couponInput', 'WELCOME15')
            ->call('applyCoupon');

        $component->assertSet('couponError', null)
            // The field empties once the code is applied and shown as a chip.
            ->assertSet('couponInput', '')
            ->assertSee('WELCOME15');

        $this->assertSame('WELCOME15', $this->cart()->couponCode());
        $this->assertSame(
            ['subtotal' => 10.00, 'discount' => 1.50, 'total' => 8.50],
            $this->cart()->totals(),
        );
    }

    public function test_a_lowercase_code_matches_the_stored_uppercase_one(): void
    {
        $product = $this->product();
        Coupon::factory()->fixed('2.00')->create(['code' => 'ANOIGMA']);

        Livewire::test(MenuPage::class)
            ->call('addDirectly', $product->id)
            ->set('couponInput', ' anoigma ')
            ->call('applyCoupon')
            ->assertSet('couponError', null);

        $this->assertSame('ANOIGMA', $this->cart()->couponCode());
        $this->assertSame(2.00, $this->cart()->totals()['discount']);
    }

    public function test_an_unknown_code_is_rejected_and_leaves_the_cart_untouched(): void
    {
        $product = $this->product('10.00');

        $component = Livewire::test(MenuPage::class)
            ->call('addDirectly', $product->id)
            ->set('couponInput', 'NOPE')
            ->call('applyCoupon');

        $component->assertSet('couponError', 'Άγνωστος κωδικός κουπονιού.');

        $this->assertNull($this->cart()->couponCode());
        $this->assertSame(0.00, $this->cart()->totals()['discount']);
        $this->assertCount(1, $this->cart()->items());
    }

    public function test_guessing_unknown_codes_is_cut_off_after_ten_tries(): void
    {
        $product = $this->product('10.00');
        $this->realCoupon();

        $component = Livewire::test(MenuPage::class)->call('addDirectly', $product->id);

        $this->guess($component, 10);

        // The eleventh is refused without the code being looked up at all, so a
        // code that does exist cannot be confirmed while the window is open.
        $component->set('couponInput', 'REAL10')->call('applyCoupon');

        $this->assertStringStartsWith('Πολλές δοκιμές κωδικού.', (string) $component->get('couponError'));
        $this->assertNull($this->cart()->couponCode());
    }

    public function test_a_code_that_exists_but_no_longer_qualifies_is_not_counted_as_a_guess(): void
    {
        $product = $this->product('10.00');
        Coupon::create([
            'code' => 'EXPIRED',
            'type' => 'fixed',
            'value' => '1.00',
            'expires_at' => now()->subDay(),
            'is_active' => true,
        ]);
        $this->realCoupon();

        $component = Livewire::test(MenuPage::class)->call('addDirectly', $product->id);

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $component->set('couponInput', 'EXPIRED')->call('applyCoupon');
        }

        $component->set('couponInput', 'REAL10')
            ->call('applyCoupon')
            ->assertSet('couponError', null);

        $this->assertSame('REAL10', $this->cart()->couponCode());
    }

    public function test_a_real_code_does_not_reset_the_guesses_that_came_before_it(): void
    {
        $product = $this->product('10.00');
        $this->realCoupon();

        $component = Livewire::test(MenuPage::class)->call('addDirectly', $product->id);

        $this->guess($component, 9);

        $component->set('couponInput', 'REAL10')
            ->call('applyCoupon')
            ->assertSet('couponError', null);

        $this->assertSame('REAL10', $this->cart()->couponCode());

        // Landing a real code spent none of the budget back: one guess is left,
        // and the one after it is refused.
        $this->guess($component, 1);

        $component->set('couponInput', 'STILLNOPE')->call('applyCoupon');

        $this->assertStringStartsWith('Πολλές δοκιμές κωδικού.', (string) $component->get('couponError'));
    }

    public function test_a_second_session_keeps_its_own_budget_on_the_same_address(): void
    {
        $product = $this->product('10.00');
        $this->realCoupon();

        // Session ids are only accepted at 40 alphanumeric characters; anything
        // else is silently swapped for a fresh random one.
        $this->useSession(self::SESSION_ONE);

        $component = Livewire::test(MenuPage::class)->call('addDirectly', $product->id);
        $this->guess($component, 10);

        $component->set('couponInput', 'NOPE')->call('applyCoupon');
        $this->assertStringStartsWith('Πολλές δοκιμές κωδικού.', (string) $component->get('couponError'));

        // Same test client, same 127.0.0.1, different browser session.
        $this->useSession(self::SESSION_TWO);

        Livewire::test(MenuPage::class)
            ->set('couponInput', 'NOPE')
            ->call('applyCoupon')
            ->assertSet('couponError', 'Άγνωστος κωδικός κουπονιού.');
    }

    public function test_replacing_sessions_cannot_bypass_the_shared_ip_backstop(): void
    {
        $this->realCoupon();

        // Ten fresh sessions can each spend their deliberately small personal
        // budget, but all one hundred guesses still count against this address.
        for ($session = 0; $session < 10; $session++) {
            $this->useSession(str_repeat((string) $session, 40));
            $this->guess(Livewire::test(MenuPage::class), 10);
        }

        $this->useSession(str_repeat('z', 40));

        $component = Livewire::test(MenuPage::class)
            ->set('couponInput', 'REAL10')
            ->call('applyCoupon');

        $this->assertStringStartsWith('Πολλές δοκιμές κωδικού.', (string) $component->get('couponError'));
        $this->assertNull($this->cart()->couponCode());
    }

    public function test_the_rate_limit_key_does_not_carry_the_raw_session_id(): void
    {
        $product = $this->product('10.00');
        $this->useSession(self::SESSION_ONE);

        Livewire::test(MenuPage::class)
            ->call('addDirectly', $product->id)
            ->set('couponInput', 'NOPE')
            ->call('applyCoupon');

        $this->assertSame(1, RateLimiter::attempts('coupon-attempts:'.hash('sha256', self::SESSION_ONE)));
        $this->assertSame(0, RateLimiter::attempts('coupon-attempts:'.self::SESSION_ONE));
        $this->assertSame(1, RateLimiter::attempts('coupon-attempts-ip:'.hash('sha256', '127.0.0.1')));
        $this->assertSame(0, RateLimiter::attempts('coupon-attempts-ip:127.0.0.1'));
    }

    private function useSession(string $id): void
    {
        session()->setId($id);

        $this->assertSame($id, session()->getId());
    }

    private function realCoupon(): Coupon
    {
        return Coupon::create([
            'code' => 'REAL10',
            'type' => 'fixed',
            'value' => '1.00',
            'is_active' => true,
        ]);
    }

    private function guess(Testable $component, int $times): void
    {
        foreach (range(1, $times) as $attempt) {
            $component->set('couponInput', 'GUESS'.$attempt.'-'.uniqid())
                ->call('applyCoupon')
                ->assertSet('couponError', 'Άγνωστος κωδικός κουπονιού.');
        }
    }

    public function test_an_empty_code_is_rejected(): void
    {
        $product = $this->product();

        Livewire::test(MenuPage::class)
            ->call('addDirectly', $product->id)
            ->set('couponInput', '   ')
            ->call('applyCoupon')
            ->assertSet('couponError', 'Γράψτε έναν κωδικό κουπονιού.');

        $this->assertNull($this->cart()->couponCode());
    }

    public function test_an_expired_code_is_rejected_with_its_reason(): void
    {
        $product = $this->product();
        Coupon::factory()->create(['code' => 'PALIO', 'expires_at' => now()->subDay()]);

        Livewire::test(MenuPage::class)
            ->call('addDirectly', $product->id)
            ->set('couponInput', 'PALIO')
            ->call('applyCoupon')
            ->assertSet('couponError', 'Ο κωδικός έχει λήξει.');

        $this->assertNull($this->cart()->couponCode());
    }

    public function test_a_code_below_its_minimum_is_rejected_with_the_minimum_named(): void
    {
        $product = $this->product('10.00');
        Coupon::factory()->create(['code' => 'MEGALO', 'min_order_total' => '15.00']);

        $component = Livewire::test(MenuPage::class)
            ->call('addDirectly', $product->id)
            ->set('couponInput', 'MEGALO')
            ->call('applyCoupon');

        $this->assertStringContainsString('15,00€', $component->get('couponError'));
        $this->assertNull($this->cart()->couponCode());
    }

    public function test_a_rejected_code_does_not_disturb_the_coupon_already_applied(): void
    {
        $product = $this->product('10.00');
        Coupon::factory()->fixed('2.00')->create(['code' => 'KALO']);

        $component = Livewire::test(MenuPage::class)
            ->call('addDirectly', $product->id)
            ->set('couponInput', 'KALO')
            ->call('applyCoupon')
            ->set('couponInput', 'ANYPARKTO')
            ->call('applyCoupon');

        $component->assertSet('couponError', 'Άγνωστος κωδικός κουπονιού.');

        $this->assertSame('KALO', $this->cart()->couponCode());
        $this->assertSame(2.00, $this->cart()->totals()['discount']);
    }

    public function test_the_customer_can_remove_an_applied_coupon(): void
    {
        $product = $this->product('10.00');
        Coupon::factory()->fixed('2.00')->create(['code' => 'KALO']);

        Livewire::test(MenuPage::class)
            ->call('addDirectly', $product->id)
            ->set('couponInput', 'KALO')
            ->call('applyCoupon')
            ->call('removeCoupon')
            ->assertSet('couponError', null);

        $this->assertNull($this->cart()->couponCode());
        $this->assertSame(0.00, $this->cart()->totals()['discount']);
        $this->assertSame(10.00, $this->cart()->totals()['total']);
    }

    public function test_the_cart_shows_subtotal_discount_and_total_together(): void
    {
        $product = $this->product('12.40');
        Coupon::factory()->percentage('15.00')->create(['code' => 'WELCOME15']);

        Livewire::test(MenuPage::class)
            ->call('addDirectly', $product->id)
            ->set('couponInput', 'WELCOME15')
            ->call('applyCoupon')
            ->assertSee('Υποσύνολο')
            ->assertSee('12,40 €')
            ->assertSee('Έκπτωση (WELCOME15)')
            ->assertSee('1,86 €')
            ->assertSee('Σύνολο')
            ->assertSee('10,54 €');
    }

    public function test_a_shrinking_basket_that_falls_under_the_minimum_says_so(): void
    {
        $product = $this->product('10.00');
        Coupon::factory()->fixed('3.00')->create(['code' => 'MEGALO', 'min_order_total' => '15.00']);

        $component = Livewire::test(MenuPage::class)
            ->call('addDirectly', $product->id)
            ->call('addDirectly', $product->id)
            ->set('couponInput', 'MEGALO')
            ->call('applyCoupon');

        $this->assertSame(3.00, $this->cart()->totals()['discount']);

        // Half the basket goes away and the coupon stops qualifying: the
        // discount must not linger, and the reason must be visible.
        $component->call('removeFromCart', 0)
            ->assertSee('MEGALO')
            ->assertSee('ισχύει για παραγγελίες από');

        $this->assertSame(
            ['subtotal' => 10.00, 'discount' => 0.00, 'total' => 10.00],
            $this->cart()->totals(),
        );
    }

    public function test_a_coupon_deleted_after_it_was_applied_stops_discounting(): void
    {
        $product = $this->product('10.00');
        $coupon = Coupon::factory()->fixed('2.00')->create(['code' => 'SVISMENO']);

        $component = Livewire::test(MenuPage::class)
            ->call('addDirectly', $product->id)
            ->set('couponInput', 'SVISMENO')
            ->call('applyCoupon');

        $coupon->delete();

        $component->call('updateQty', 0, 1)
            ->assertSee('δεν είναι πλέον διαθέσιμος');

        $this->assertSame(0.00, $this->cart()->totals()['discount']);
    }

    /**
     * The coupon and the lines are one session value, so a discount cannot be
     * carried into an order the customer never earned it on.
     */
    public function test_clearing_the_cart_drops_the_coupon_with_it(): void
    {
        $product = $this->product('10.00');
        Coupon::factory()->fixed('2.00')->create(['code' => 'KALO']);

        Livewire::test(MenuPage::class)
            ->call('addDirectly', $product->id)
            ->set('couponInput', 'KALO')
            ->call('applyCoupon');

        $this->assertGreaterThan(0, $this->cart()->totals()['discount']);

        $this->cart()->clear();

        // A fresh basket, same session — as after a completed order.
        Livewire::test(MenuPage::class)->call('addDirectly', $product->id);

        $totals = $this->cart()->totals();

        $this->assertNull($this->cart()->couponCode());
        $this->assertSame(0.00, $totals['discount']);
        $this->assertSame($totals['subtotal'], $totals['total']);
        $this->assertSame(10.00, $totals['total']);
    }

    public function test_cart_edits_keep_the_applied_coupon(): void
    {
        $product = $this->product('10.00');
        Coupon::factory()->percentage('10.00')->create(['code' => 'DEKA']);

        $component = Livewire::test(MenuPage::class)
            ->call('addDirectly', $product->id)
            ->set('couponInput', 'DEKA')
            ->call('applyCoupon');

        $component->call('updateQty', 0, 3);

        $this->assertSame('DEKA', $this->cart()->couponCode());
        $this->assertSame(
            ['subtotal' => 30.00, 'discount' => 3.00, 'total' => 27.00],
            $this->cart()->totals(),
        );
    }

    /** Carts stored before coupons existed are a bare list of lines. */
    public function test_a_legacy_flat_cart_still_reads(): void
    {
        session(['cart' => [[
            'product_id' => 1,
            'product_name' => 'Freddo Espresso',
            'base_price' => 10.00,
            'selected_options' => [],
            'quantity' => 1,
            'line_total' => 10.00,
            'notes' => '',
        ]]]);

        $this->assertCount(1, $this->cart()->items());
        $this->assertNull($this->cart()->couponCode());
        $this->assertSame(10.00, $this->cart()->totals()['total']);
    }
}
