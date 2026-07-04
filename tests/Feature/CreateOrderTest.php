<?php

namespace Tests\Feature;

use App\Actions\CreateOrder;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateOrderTest extends TestCase
{
    use RefreshDatabase;

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

    private function addCartLine(array $overrides = []): array
    {
        $line = array_merge([
            'product_id' => 1,
            'product_name' => 'Freddo Espresso',
            'base_price' => 2.80,
            'selected_options' => [
                ['group' => 'Μέγεθος / Δόση', 'value' => 'Διπλός', 'price_delta' => 0.70],
            ],
            'quantity' => 1,
            'line_total' => 3.50,
            'notes' => '',
        ], $overrides);

        app(CartService::class)->add($line);

        return $line;
    }

    public function test_creates_order_with_order_items_from_cart_snapshot(): void
    {
        $line1 = $this->addCartLine();
        $line2 = $this->addCartLine([
            'product_id' => 2,
            'product_name' => 'Cappuccino Freddo',
            'base_price' => 3.00,
            'selected_options' => [],
            'quantity' => 2,
            'line_total' => 6.00,
        ]);

        $order = app(CreateOrder::class)->execute($this->checkoutData());

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'customer_name' => 'Μιχάλης',
            'phone' => '6912345678',
            'address' => 'Δημοκρατίας 42',
            'payment_method' => PaymentMethod::Cash->value,
        ]);

        $this->assertEquals(9.50, $order->subtotal);
        $this->assertEquals(0.00, $order->delivery_fee);
        $this->assertEquals(9.50, $order->total);

        $this->assertCount(2, $order->items);

        $item1 = $order->items->firstWhere('product_name', $line1['product_name']);
        $this->assertNotNull($item1);
        $this->assertEquals($line1['base_price'], $item1->base_price);
        $this->assertEquals($line1['quantity'], $item1->quantity);
        $this->assertEquals($line1['line_total'], $item1->line_total);
        $this->assertEquals($line1['selected_options'], $item1->selected_options);

        $item2 = $order->items->firstWhere('product_name', $line2['product_name']);
        $this->assertNotNull($item2);
        $this->assertEquals($line2['line_total'], $item2->line_total);
        $this->assertEquals([], $item2->selected_options);
    }

    public function test_display_number_increments_for_same_day(): void
    {
        $this->addCartLine();
        $firstOrder = app(CreateOrder::class)->execute($this->checkoutData());
        $this->assertEquals(1, $firstOrder->display_number);

        $this->addCartLine();
        $secondOrder = app(CreateOrder::class)->execute($this->checkoutData());
        $this->assertEquals(2, $secondOrder->display_number);

        $this->addCartLine();
        $thirdOrder = app(CreateOrder::class)->execute($this->checkoutData());
        $this->assertEquals(3, $thirdOrder->display_number);
    }

    public function test_display_number_resets_on_a_new_day(): void
    {
        $this->addCartLine();
        $yesterdaysOrder = app(CreateOrder::class)->execute($this->checkoutData());
        Order::where('id', $yesterdaysOrder->id)->update([
            'created_at' => now()->subDay(),
        ]);

        $this->addCartLine();
        $todaysOrder = app(CreateOrder::class)->execute($this->checkoutData());

        $this->assertEquals(1, $todaysOrder->display_number);
    }

    public function test_cart_is_cleared_after_successful_order_creation(): void
    {
        $this->addCartLine();
        $this->assertFalse(app(CartService::class)->isEmpty());

        app(CreateOrder::class)->execute($this->checkoutData());

        $this->assertTrue(app(CartService::class)->isEmpty());
        $this->assertSame([], app(CartService::class)->items());
    }
}
