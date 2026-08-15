<?php

namespace Tests\Feature;

use App\Actions\CreateOrder;
use App\Enums\PaymentMethod;
use App\Enums\SelectionType;
use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Order;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreateOrderTest extends TestCase
{
    use RefreshDatabase;

    private function category(): Category
    {
        return Category::firstOrCreate(
            ['slug' => 'kafedes'],
            ['name' => 'Καφέδες', 'sort_order' => 0, 'is_active' => true],
        );
    }

    private function product(string $name = 'Freddo Espresso', string $price = '2.80', bool $available = true): Product
    {
        return Product::create([
            'category_id' => $this->category()->id,
            'name' => $name,
            'base_price' => $price,
            'is_available' => $available,
            'sort_order' => 0,
        ]);
    }

    private function optionValue(Product $product, string $name = 'Διπλός', string $delta = '0.70'): OptionValue
    {
        $group = OptionGroup::create([
            'name' => 'Μέγεθος / Δόση',
            'selection' => SelectionType::Single->value,
            'is_required' => true,
            'sort_order' => 0,
        ]);

        $value = OptionValue::create([
            'option_group_id' => $group->id,
            'name' => $name,
            'price_delta' => $delta,
            'is_default' => false,
            'sort_order' => 0,
        ]);

        $product->optionGroups()->attach($group->id, ['sort_order' => 0]);

        return $value;
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

    private function addCartLine(Product $product, array $overrides = []): array
    {
        $line = array_merge([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'base_price' => (float) $product->base_price,
            'selected_options' => [],
            'quantity' => 1,
            'line_total' => (float) $product->base_price,
            'notes' => '',
        ], $overrides);

        app(CartService::class)->add($line);

        return $line;
    }

    public function test_creates_order_with_order_items_from_cart_snapshot(): void
    {
        $freddo = $this->product('Freddo Espresso', '2.80');
        $cappuccino = $this->product('Cappuccino Freddo', '3.00');
        $double = $this->optionValue($freddo);

        $line1 = $this->addCartLine($freddo, [
            'selected_options' => [[
                'option_value_id' => $double->id,
                'group' => 'Μέγεθος / Δόση',
                'value' => 'Διπλός',
                'price_delta' => 0.70,
            ]],
            'line_total' => 3.50,
        ]);
        $line2 = $this->addCartLine($cappuccino, [
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
        $this->assertSame('Διπλός', $item1->selected_options[0]['value']);

        $item2 = $order->items->firstWhere('product_name', $line2['product_name']);
        $this->assertNotNull($item2);
        $this->assertEquals($line2['line_total'], $item2->line_total);
        $this->assertEquals([], $item2->selected_options);
    }

    public function test_display_number_increments_for_same_day(): void
    {
        $product = $this->product();

        $this->addCartLine($product);
        $firstOrder = app(CreateOrder::class)->execute($this->checkoutData());
        $this->assertEquals(1, $firstOrder->display_number);

        $this->addCartLine($product);
        $secondOrder = app(CreateOrder::class)->execute($this->checkoutData());
        $this->assertEquals(2, $secondOrder->display_number);

        $this->addCartLine($product);
        $thirdOrder = app(CreateOrder::class)->execute($this->checkoutData());
        $this->assertEquals(3, $thirdOrder->display_number);
    }

    public function test_display_number_resets_on_a_new_day(): void
    {
        $product = $this->product();

        $this->addCartLine($product);
        $yesterdaysOrder = app(CreateOrder::class)->execute($this->checkoutData());
        Order::where('id', $yesterdaysOrder->id)->update([
            'created_at' => now()->subDay(),
        ]);

        $this->addCartLine($product);
        $todaysOrder = app(CreateOrder::class)->execute($this->checkoutData());

        $this->assertEquals(1, $todaysOrder->display_number);
    }

    public function test_cart_is_cleared_after_successful_order_creation(): void
    {
        $this->addCartLine($this->product());
        $this->assertFalse(app(CartService::class)->isEmpty());

        app(CreateOrder::class)->execute($this->checkoutData());

        $this->assertTrue(app(CartService::class)->isEmpty());
        $this->assertSame([], app(CartService::class)->items());
    }

    public function test_every_order_gets_an_unguessable_public_token(): void
    {
        $product = $this->product();

        $this->addCartLine($product);
        $first = app(CreateOrder::class)->execute($this->checkoutData());
        $this->addCartLine($product);
        $second = app(CreateOrder::class)->execute($this->checkoutData());

        $this->assertSame(40, strlen($first->public_token));
        $this->assertNotSame($first->public_token, $second->public_token);
        $this->assertSame($first->public_token, $first->getRouteKey());
    }

    /** B3: a replayed submit must not create an empty order. */
    public function test_an_empty_cart_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        try {
            app(CreateOrder::class)->execute($this->checkoutData());
        } finally {
            $this->assertDatabaseCount('orders', 0);
        }
    }

    /** S2: product went unavailable while the cart sat in the session. */
    public function test_unavailable_product_is_refused_and_dropped_from_the_cart(): void
    {
        $available = $this->product('Freddo Espresso', '2.80');
        $soldOut = $this->product('Cold Brew', '3.50');

        $this->addCartLine($available);
        $this->addCartLine($soldOut);

        $soldOut->update(['is_available' => false]);

        try {
            app(CreateOrder::class)->execute($this->checkoutData());
            $this->fail('Expected the sold out product to be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Cold Brew', $e->validator->errors()->first('cart'));
        }

        $this->assertDatabaseCount('orders', 0);

        $cart = app(CartService::class)->items();
        $this->assertCount(1, $cart);
        $this->assertSame('Freddo Espresso', $cart[0]['product_name']);
    }

    /** S2: a deleted product cannot be ordered from a stale cart. */
    public function test_deleted_product_is_refused(): void
    {
        $product = $this->product();
        $this->addCartLine($product);
        $product->delete();

        $this->expectException(ValidationException::class);

        try {
            app(CreateOrder::class)->execute($this->checkoutData());
        } finally {
            $this->assertDatabaseCount('orders', 0);
            $this->assertSame([], app(CartService::class)->items());
        }
    }

    /** S2: the catalogue price wins over whatever the session was holding. */
    public function test_stale_base_price_is_refused_then_the_corrected_cart_succeeds(): void
    {
        $product = $this->product('Freddo Espresso', '2.80');
        $this->addCartLine($product);

        $product->update(['base_price' => '3.20']);

        try {
            app(CreateOrder::class)->execute($this->checkoutData());
            $this->fail('Expected the stale price to be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('τιμοκατάλογος', $e->validator->errors()->first('cart'));
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertEquals(3.20, app(CartService::class)->items()[0]['line_total']);

        $order = app(CreateOrder::class)->execute($this->checkoutData());
        $this->assertEquals(3.20, $order->total);
    }

    /** S2: option prices are re-resolved from the catalogue, not trusted. */
    public function test_option_price_is_taken_from_the_catalogue(): void
    {
        $product = $this->product('Freddo Espresso', '2.80');
        $double = $this->optionValue($product, 'Διπλός', '0.70');

        $this->addCartLine($product, [
            'selected_options' => [[
                'option_value_id' => $double->id,
                'group' => 'Μέγεθος / Δόση',
                'value' => 'Διπλός',
                'price_delta' => -2.80, // tampered: would make the coffee free
            ]],
            'line_total' => 0.0,
        ]);

        try {
            app(CreateOrder::class)->execute($this->checkoutData());
            $this->fail('Expected the tampered option price to be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('τιμοκατάλογος', $e->validator->errors()->first('cart'));
        }

        $order = app(CreateOrder::class)->execute($this->checkoutData());

        $this->assertEquals(3.50, $order->total);
        $this->assertEquals(0.70, $order->items->first()->selected_options[0]['price_delta']);
    }

    /** B4: a quantity injected into the session is clamped, never inserted raw. */
    public function test_tampered_quantity_is_clamped(): void
    {
        $product = $this->product('Freddo Espresso', '2.00');

        // replace() bypasses the entry-point clamp: this is a session poisoned
        // behind the cart API, which CreateOrder still has to survive.
        app(CartService::class)->replace([[
            'product_id' => $product->id,
            'product_name' => $product->name,
            'base_price' => 2.00,
            'selected_options' => [],
            'quantity' => 99999,
            'line_total' => 199998.0,
            'notes' => '',
        ]]);

        // The cart is corrected first, so the customer sees the real number.
        try {
            app(CreateOrder::class)->execute($this->checkoutData());
            $this->fail('Expected the tampered quantity to be corrected.');
        } catch (ValidationException $e) {
            $this->assertSame(CartService::MAX_QUANTITY, app(CartService::class)->items()[0]['quantity']);
        }

        $order = app(CreateOrder::class)->execute($this->checkoutData());

        $this->assertSame(CartService::MAX_QUANTITY, $order->items->first()->quantity);
        $this->assertEquals(198.00, $order->total);
    }
}
