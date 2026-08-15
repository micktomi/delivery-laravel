<?php

namespace Tests\Feature;

use App\Actions\CreateOrder;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Livewire\CheckoutPage;
use App\Livewire\OrderBoard;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ProductionHighRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_previous_day_unfinished_order_remains_on_the_kitchen_board(): void
    {
        $order = Order::factory()->status(OrderStatus::Preparing)->create([
            'customer_name' => 'Παραγγελία πριν τα μεσάνυχτα',
            'placed_at' => now()->subDay(),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        Livewire::actingAs(User::factory()->create())
            ->test(OrderBoard::class)
            ->assertSee($order->customer_name);
    }

    public function test_replayed_checkout_snapshot_with_the_same_key_returns_one_order(): void
    {
        $line = $this->seedCart();
        $checkout = $this->checkoutData([
            'checkout_token' => (string) Str::uuid(),
        ]);

        $first = app(CreateOrder::class)->execute($checkout);

        // Recreate the cart state carried by a concurrent request that started
        // before the first response cleared the session cart.
        app(CartService::class)->add($line);
        $second = app(CreateOrder::class)->execute($checkout);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame($checkout['checkout_token'], $first->checkout_token);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertTrue(app(CartService::class)->isEmpty());
    }

    public function test_post_commit_logging_failure_still_returns_checkout_confirmation(): void
    {
        $this->seedCart();

        Log::shouldReceive('info')
            ->once()
            ->with('order.created', Mockery::type('array'))
            ->andThrow(new RuntimeException('log destination unavailable'));
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $component = Livewire::test(CheckoutPage::class)
            ->set('customer_name', 'Μιχάλης')
            ->set('phone', '6912345678')
            ->set('address', 'Δημοκρατίας 42')
            ->set('payment_method', PaymentMethod::Cash->value)
            ->call('submit');

        $order = Order::firstOrFail();

        $component
            ->assertHasNoErrors()
            ->assertSet('confirmedOrderId', $order->getKey())
            ->assertSet('confirmedOrderToken', $order->getRouteKey());

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertTrue(app(CartService::class)->isEmpty());
    }

    private function seedCart(): array
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
        $line = [
            'product_id' => $product->getKey(),
            'product_name' => $product->name,
            'base_price' => 2.80,
            'selected_options' => [],
            'quantity' => 1,
            'line_total' => 2.80,
            'notes' => '',
        ];

        app(CartService::class)->add($line);

        return $line;
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
}
