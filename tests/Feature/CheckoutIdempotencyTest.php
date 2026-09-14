<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Livewire\CheckoutPage;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class CheckoutIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function seedCart(): void
    {
        $category = Category::firstOrCreate(['slug' => 'checkout-test'], [
            'name' => 'Καφέδες', 'is_active' => true,
        ]);
        $product = Product::firstOrCreate(['category_id' => $category->id, 'name' => 'Καφές'], [
            'base_price' => '5.00', 'is_available' => true,
        ]);
        app(CartService::class)->add([
            'product_id' => $product->id, 'product_name' => $product->name,
            'base_price' => 5.00, 'selected_options' => [], 'quantity' => 1, 'line_total' => 5.00,
        ]);
    }

    private function checkout()
    {
        return Livewire::test(CheckoutPage::class)
            ->set('customer_name', 'Μιχάλης')
            ->set('phone', '6912345678')
            ->set('address', 'Δημοκρατίας 42')
            ->set('payment_method', PaymentMethod::Cash->value);
    }

    public function test_two_checkout_mounts_and_refresh_reuse_the_cart_token(): void
    {
        $this->seedCart();
        $token = app(CartService::class)->checkoutToken();

        $this->assertTrue(Str::isUuid($token));
        $first = $this->checkout()->assertSet('checkoutToken', $token);
        $this->checkout()->assertSet('checkoutToken', $token);
        $first->call('$refresh')->assertSet('checkoutToken', $token);
        $this->get('/')->assertOk();
        $this->checkout()->assertSet('checkoutToken', $token);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_legacy_cart_snapshots_resolve_to_the_same_token_without_a_session_write_race(): void
    {
        $this->seedCart();
        $legacy = session('cart');
        unset($legacy['checkout_token']);
        session(['cart' => $legacy]);
        $first = $this->checkout()->get('checkoutToken');

        // A second request already read the same pre-token session contents.
        session(['cart' => $legacy]);
        $this->checkout()->assertSet('checkoutToken', $first);
    }

    public function test_browser_cannot_replace_the_checkout_token(): void
    {
        $this->seedCart();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $this->checkout()->set('checkoutToken', (string) Str::uuid());
    }

    public function test_second_tab_resolves_to_the_committed_order_with_an_empty_cart(): void
    {
        $this->seedCart();
        $first = $this->checkout();
        $second = $this->checkout();

        $first->call('submit')->assertHasNoErrors();
        $second->call('submit')->assertHasNoErrors()
            ->assertSet('confirmedOrderId', $first->get('confirmedOrderId'));

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_new_purchase_has_a_new_token_and_old_tab_does_not_clear_it(): void
    {
        $this->seedCart();
        $first = $this->checkout();
        $oldTab = $this->checkout();
        $token = $first->get('checkoutToken');
        $first->call('submit')->assertHasNoErrors();
        $this->assertTrue(app(CartService::class)->isEmpty());

        // Visiting checkout without a cart must not seed a reusable empty-cart token.
        Livewire::test(CheckoutPage::class)->assertRedirect('/');
        $this->seedCart();
        $new = $this->checkout();
        $this->assertNotSame($token, $new->get('checkoutToken'));
        $newCart = session('cart');

        $oldTab->call('submit')->assertHasNoErrors()
            ->assertSet('confirmedOrderId', $first->get('confirmedOrderId'));
        $this->assertSame($newCart, session('cart'));

        $new->call('submit')->assertHasNoErrors();
        $this->assertDatabaseCount('orders', 2);
        $this->assertNotSame($first->get('confirmedOrderId'), $new->get('confirmedOrderId'));
        $this->assertSame(2, Order::query()->distinct()->count('checkout_token'));
    }

    public function test_validation_failure_keeps_the_purchase_token(): void
    {
        $this->seedCart();
        $checkout = $this->checkout();
        $token = $checkout->get('checkoutToken');
        $checkout->set('address', '')->call('submit')->assertHasErrors(['address']);
        $this->checkout()->assertSet('checkoutToken', $token);
        $this->assertDatabaseCount('orders', 0);
    }
}
