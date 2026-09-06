<?php

namespace Tests\Feature;

use App\Actions\CreateOrder;
use App\Enums\PaymentMethod;
use App\Livewire\MenuPage;
use App\Models\Category;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CartStateIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_cart_line_corruption_is_replaced_before_render_without_changing_session(): void
    {
        $product = $this->product();
        $line = $this->storeLine($product);
        $storedCart = session('cart');

        $component = Livewire::test(MenuPage::class)
            ->set('cart.0', 7)
            ->assertSee($product->name);

        $this->assertSame($line, $component->get('cart')[0]);
        $this->assertSame($storedCart, session('cart'));
    }

    public function test_client_cart_line_corruption_is_replaced_after_an_action_that_does_not_reload_it(): void
    {
        $product = $this->product();
        $line = $this->storeLine($product);
        $storedCart = session('cart');
        $component = Livewire::test(MenuPage::class);

        $component->update(
            calls: [[
                'method' => 'openProduct',
                'params' => [$product->id],
                'path' => '',
            ]],
            updates: ['cart.0' => 7],
        )
            ->assertSet('openProductId', $product->id)
            ->assertSee($product->name);

        $this->assertSame($line, $component->get('cart')[0]);
        $this->assertSame($storedCart, session('cart'));
    }

    public function test_malformed_current_session_lines_are_discarded_without_losing_valid_lines_or_coupon(): void
    {
        $line = $this->line($this->product());
        session(['cart' => [
            'lines' => [7, $line, 'invalid'],
            'coupon_code' => 'WELCOME10',
        ]]);

        $cart = app(CartService::class);

        $this->assertSame([$line], $cart->items());
        $this->assertSame('WELCOME10', $cart->couponCode());
    }

    public function test_legacy_line_list_remains_supported_and_discards_scalar_entries(): void
    {
        $line = $this->line($this->product());
        session(['cart' => [7, $line, 'invalid']]);

        $cart = app(CartService::class);

        $this->assertSame([$line], $cart->items());
        $this->assertNull($cart->couponCode());
    }

    public function test_malformed_session_cart_cannot_create_an_order_or_order_item(): void
    {
        session(['cart' => [
            'lines' => [7, 'invalid'],
            'coupon_code' => null,
        ]]);

        try {
            app(CreateOrder::class)->execute([
                'customer_name' => 'Μιχάλης',
                'phone' => '6912345678',
                'address' => 'Δημοκρατίας 42',
                'floor_bell' => null,
                'notes' => null,
                'payment_method' => PaymentMethod::Cash->value,
            ]);

            $this->fail('Malformed cart data must not create an order.');
        } catch (ValidationException $e) {
            $this->assertSame('Το καλάθι σας είναι άδειο.', $e->validator->errors()->first('cart'));
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    private function storeLine(Product $product): array
    {
        $line = $this->line($product);
        app(CartService::class)->add($line);

        return app(CartService::class)->items()[0];
    }

    private function line(Product $product): array
    {
        return [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'base_price' => (float) $product->base_price,
            'selected_options' => [],
            'quantity' => 1,
            'line_total' => (float) $product->base_price,
            'notes' => '',
        ];
    }

    private function product(): Product
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
            'base_price' => '2.80',
            'is_available' => true,
            'sort_order' => 0,
        ]);
    }
}
