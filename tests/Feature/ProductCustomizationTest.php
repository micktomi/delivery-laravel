<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Livewire\CheckoutPage;
use App\Livewire\MenuPage;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductCustomizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_select_size_dose_customization_and_add_to_cart(): void
    {
        $this->seed();

        $product = Product::where('name', 'Freddo Espresso')->firstOrFail();
        $sizeGroup = OptionGroup::where('name', 'Μέγεθος / Δόση')->firstOrFail();
        $doubleValue = OptionValue::where('option_group_id', $sizeGroup->id)
            ->where('name', 'Διπλός')
            ->firstOrFail();

        Livewire::test(MenuPage::class)
            ->call('openProduct', $product->id)
            ->set('selectedOptions.'.$sizeGroup->id, $doubleValue->id)
            ->call('addToCart')
            ->assertDispatched('cart-updated', productId: $product->id);

        $cart = app(CartService::class)->items();
        $this->assertCount(1, $cart);

        $item = $cart[0];
        $this->assertEquals($product->id, $item['product_id']);

        $options = collect($item['selected_options']);
        $this->assertTrue($options->contains('value', 'Διπλός'));

        // Freddo Espresso base price is 2.20, Double is +0.70, total should be 2.90
        $this->assertEquals(2.90, $item['line_total']);
    }

    public function test_direct_add_dispatches_the_added_product_for_ui_feedback(): void
    {
        $this->seed();

        $product = Product::whereDoesntHave('optionGroups')->firstOrFail();

        Livewire::test(MenuPage::class)
            ->call('addDirectly', $product->id)
            ->assertDispatched('cart-updated', productId: $product->id);
    }

    public function test_complete_order_flow_with_customization(): void
    {
        $this->seed();

        $product = Product::where('name', 'Freddo Espresso')->firstOrFail();
        $sizeGroup = OptionGroup::where('name', 'Μέγεθος / Δόση')->firstOrFail();
        $doubleValue = OptionValue::where('option_group_id', $sizeGroup->id)
            ->where('name', 'Διπλός')
            ->firstOrFail();

        // 1. Add Freddo Espresso with Double size to cart
        Livewire::test(MenuPage::class)
            ->call('openProduct', $product->id)
            ->set('selectedOptions.'.$sizeGroup->id, $doubleValue->id)
            ->call('addToCart');

        // 2. Submit the order via checkout page
        Livewire::test(CheckoutPage::class)
            ->set('customer_name', 'Μιχάλης')
            ->set('phone', '6912345678')
            ->set('address', 'Δημοκρατίας 42')
            ->set('payment_method', PaymentMethod::Cash->value)
            ->call('submit')
            ->assertHasNoErrors();

        // 3. Confirm order is in DB and values match
        $order = Order::firstOrFail();
        $this->assertEquals('Μιχάλης', $order->customer_name);
        $this->assertEquals(2.90, $order->total); // 2.20 base + 0.70 double

        $item = $order->items()->firstOrFail();
        $this->assertEquals('Freddo Espresso', $item->product_name);
        $this->assertEquals(2.90, $item->line_total);

        // Confirm options contains "Διπλός"
        $options = collect($item->selected_options);
        $this->assertTrue($options->contains('value', 'Διπλός'));

        // 4. Confirm tracking page shows the options and price
        $this->get(route('order.track', $order))
            ->assertOk()
            ->assertSee('Διπλός')
            ->assertSee('2.90€');

        // 5. Confirm kitchen board shows the size option
        $user = User::factory()->create();
        $this->actingAs($user)
            ->get(route('kitchen'))
            ->assertOk()
            ->assertSee('Διπλός');
    }
}
