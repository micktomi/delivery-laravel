<?php

namespace Tests\Feature;

use App\Actions\CreateOrder;
use App\Enums\PaymentMethod;
use App\Enums\SelectionType;
use App\Livewire\CheckoutPage;
use App\Livewire\MenuPage;
use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Order;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proves the option system generalized in Task 3 with non-coffee catalogues:
 * nothing here relies on Ζάχαρη/Γλυκαντικό/Σκέτος, or on any group/option
 * name at all — required/optional, single/multi, min/max and pricing all
 * come from the same option-group fields the coffee catalogue uses.
 */
class GeneralizedOptionSemanticsTest extends TestCase
{
    use RefreshDatabase;

    private function category(string $name = 'Grill'): Category
    {
        return Category::firstOrCreate(
            ['slug' => \Illuminate\Support\Str::slug($name)],
            ['name' => $name, 'sort_order' => 0, 'is_active' => true],
        );
    }

    private function product(string $name, string $price, Category $category): Product
    {
        return Product::create([
            'category_id' => $category->id,
            'name' => $name,
            'base_price' => $price,
            'is_available' => true,
            'sort_order' => 0,
        ]);
    }

    private function attachGroup(Product $product, OptionGroup $group): void
    {
        $product->optionGroups()->attach($group->id, ['sort_order' => 0]);
    }

    /** @return array{0: Product, 1: OptionGroup, 2: OptionGroup, 3: OptionGroup} */
    private function grillProduct(): array
    {
        $product = $this->product('Μπριζόλα', '9.00', $this->category('Grill'));

        $doneness = OptionGroup::create([
            'name' => 'Ψήσιμο',
            'selection' => SelectionType::Single->value,
            'is_required' => true,
            'sort_order' => 0,
        ]);
        OptionValue::create(['option_group_id' => $doneness->id, 'name' => 'Μέτριο', 'price_delta' => 0, 'is_default' => true, 'sort_order' => 0]);
        OptionValue::create(['option_group_id' => $doneness->id, 'name' => 'Καλοψημένο', 'price_delta' => 0, 'is_default' => false, 'sort_order' => 1]);

        $sauce = OptionGroup::create([
            'name' => 'Σάλτσα',
            'selection' => SelectionType::Single->value,
            'is_required' => false,
            'sort_order' => 1,
        ]);
        OptionValue::create(['option_group_id' => $sauce->id, 'name' => 'Χωρίς σάλτσα', 'price_delta' => 0, 'is_default' => true, 'sort_order' => 0]);
        OptionValue::create(['option_group_id' => $sauce->id, 'name' => 'Τζατζίκι', 'price_delta' => 0, 'is_default' => false, 'sort_order' => 1]);
        OptionValue::create(['option_group_id' => $sauce->id, 'name' => 'Μουστάρδα', 'price_delta' => 0, 'is_default' => false, 'sort_order' => 2]);

        $extras = OptionGroup::create([
            'name' => 'Extras',
            'selection' => SelectionType::Multi->value,
            'is_required' => false,
            'max_select' => 2,
            'sort_order' => 2,
        ]);
        OptionValue::create(['option_group_id' => $extras->id, 'name' => 'Τυρί', 'price_delta' => 0.50, 'is_default' => false, 'sort_order' => 0]);
        OptionValue::create(['option_group_id' => $extras->id, 'name' => 'Μπέικον', 'price_delta' => 0.80, 'is_default' => false, 'sort_order' => 1]);
        OptionValue::create(['option_group_id' => $extras->id, 'name' => 'Τηγανητά κρεμμύδια', 'price_delta' => 0.40, 'is_default' => false, 'sort_order' => 2]);

        foreach ([$doneness, $sauce, $extras] as $group) {
            $this->attachGroup($product, $group);
        }

        return [$product, $doneness, $sauce, $extras];
    }

    public function test_grill_product_requires_doneness_and_prices_extras_correctly(): void
    {
        [$product, $doneness, , $extras] = $this->grillProduct();
        $wellDone = OptionValue::where('option_group_id', $doneness->id)->where('name', 'Καλοψημένο')->firstOrFail();
        $cheese = OptionValue::where('option_group_id', $extras->id)->where('name', 'Τυρί')->firstOrFail();
        $bacon = OptionValue::where('option_group_id', $extras->id)->where('name', 'Μπέικον')->firstOrFail();

        // Missing the required Ψήσιμο pick is rejected client-side.
        Livewire::test(MenuPage::class)
            ->call('openProduct', $product->id)
            ->set('selectedOptions.'.$doneness->id, null)
            ->set('selectedOptions.'.$extras->id, [$cheese->id, $bacon->id])
            ->call('addToCart')
            ->assertHasErrors('options');
        $this->assertSame([], app(CartService::class)->items());

        Livewire::test(MenuPage::class)
            ->call('openProduct', $product->id)
            ->set('selectedOptions.'.$doneness->id, $wellDone->id)
            ->set('selectedOptions.'.$extras->id, [$cheese->id, $bacon->id])
            ->call('addToCart')
            ->assertDispatched('cart-updated', productId: $product->id);

        $line = app(CartService::class)->items()[0];
        // 9.00 base + 0.50 cheese + 0.80 bacon
        $this->assertEquals(10.30, $line['line_total']);
        $options = collect($line['selected_options']);
        $this->assertTrue($options->contains('value', 'Καλοψημένο'));
        $this->assertTrue($options->contains('value', 'Τυρί'));
        $this->assertTrue($options->contains('value', 'Μπέικον'));
    }

    public function test_grill_product_rejects_more_extras_than_the_group_allows(): void
    {
        [$product, $doneness, , $extras] = $this->grillProduct();
        $medium = OptionValue::where('option_group_id', $doneness->id)->where('is_default', true)->firstOrFail();
        $values = OptionValue::where('option_group_id', $extras->id)->pluck('id');

        // Tamper past the client's max_select=2 by writing the cart directly.
        app(CartService::class)->add([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'base_price' => (float) $product->base_price,
            'selected_options' => $values->map(fn (int $id) => [
                'option_value_id' => $id,
                'group' => 'Extras',
                'value' => OptionValue::find($id)->name,
                'price_delta' => (float) OptionValue::find($id)->price_delta,
            ])->push([
                'option_value_id' => $medium->id,
                'group' => 'Ψήσιμο',
                'value' => $medium->name,
                'price_delta' => 0.0,
            ])->all(),
            'quantity' => 1,
            'line_total' => (float) $product->base_price,
            'notes' => '',
        ]);

        $this->assertGrillCheckoutIsRejected();
    }

    public function test_grill_product_full_order_flow_persists_correctly(): void
    {
        [$product, $doneness] = $this->grillProduct();
        $medium = OptionValue::where('option_group_id', $doneness->id)->where('is_default', true)->firstOrFail();

        Livewire::test(MenuPage::class)
            ->call('openProduct', $product->id)
            ->set('selectedOptions.'.$doneness->id, $medium->id)
            ->call('addToCart');

        Livewire::test(CheckoutPage::class)
            ->set('customer_name', 'Μιχάλης')
            ->set('phone', '6912345678')
            ->set('address', 'Δημοκρατίας 42')
            ->set('payment_method', PaymentMethod::Cash->value)
            ->call('submit')
            ->assertHasNoErrors();

        $order = Order::firstOrFail();
        $item = $order->items()->firstOrFail();
        $this->assertEquals(9.00, $item->line_total);
        $this->assertTrue(collect($item->selected_options)->contains('value', 'Μέτριο'));
    }

    public function test_restaurant_product_requires_a_side_dish_choice(): void
    {
        $product = $this->product('Κοτόπουλο σχάρας', '8.50', $this->category('Restaurant'));

        $side = OptionGroup::create([
            'name' => 'Συνοδευτικό',
            'selection' => SelectionType::Single->value,
            'is_required' => true,
            'sort_order' => 0,
        ]);
        $potatoes = OptionValue::create(['option_group_id' => $side->id, 'name' => 'Πατάτες', 'price_delta' => 0, 'is_default' => true, 'sort_order' => 0]);
        OptionValue::create(['option_group_id' => $side->id, 'name' => 'Ρύζι', 'price_delta' => 0, 'is_default' => false, 'sort_order' => 1]);
        OptionValue::create(['option_group_id' => $side->id, 'name' => 'Σαλάτα', 'price_delta' => 0.30, 'is_default' => false, 'sort_order' => 2]);
        $this->attachGroup($product, $side);

        Livewire::test(MenuPage::class)
            ->call('openProduct', $product->id)
            ->set('selectedOptions.'.$side->id, null)
            ->call('addToCart')
            ->assertHasErrors('options');

        Livewire::test(MenuPage::class)
            ->call('openProduct', $product->id)
            ->set('selectedOptions.'.$side->id, $potatoes->id)
            ->call('addToCart')
            ->assertDispatched('cart-updated', productId: $product->id);

        Livewire::test(CheckoutPage::class)
            ->set('customer_name', 'Μιχάλης')
            ->set('phone', '6912345678')
            ->set('address', 'Δημοκρατίας 42')
            ->set('payment_method', PaymentMethod::Cash->value)
            ->call('submit')
            ->assertHasNoErrors();

        $item = Order::firstOrFail()->items()->firstOrFail();
        $this->assertTrue(collect($item->selected_options)->contains('value', 'Πατάτες'));
        $this->assertEquals(8.50, $item->line_total);
    }

    public function test_server_rejects_an_option_value_id_belonging_to_a_different_product(): void
    {
        [$grillProduct, $doneness] = $this->grillProduct();
        $foreignValue = OptionValue::where('option_group_id', $doneness->id)->firstOrFail();

        $otherProduct = $this->product('Σαλάτα εποχής', '5.00', $this->category('Restaurant'));

        app(CartService::class)->add([
            'product_id' => $otherProduct->id,
            'product_name' => $otherProduct->name,
            'base_price' => (float) $otherProduct->base_price,
            'selected_options' => [[
                'option_value_id' => $foreignValue->id,
                'group' => 'Ψήσιμο',
                'value' => $foreignValue->name,
                'price_delta' => 0.0,
            ]],
            'quantity' => 1,
            'line_total' => (float) $otherProduct->base_price,
            'notes' => '',
        ]);

        try {
            app(CreateOrder::class)->execute($this->checkoutData());
            $this->fail('Expected an option value from another product to be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cart', $e->validator->errors()->toArray());
        }

        $this->assertDatabaseCount('orders', 0);
    }

    private function assertGrillCheckoutIsRejected(): void
    {
        try {
            app(CreateOrder::class)->execute($this->checkoutData());
            $this->fail('Expected the tampered cart to be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cart', $e->validator->errors()->toArray());
        }

        $this->assertDatabaseCount('orders', 0);
    }

    private function checkoutData(): array
    {
        return [
            'customer_name' => 'Μιχάλης',
            'phone' => '6912345678',
            'address' => 'Δημοκρατίας 42',
            'floor_bell' => null,
            'notes' => null,
            'payment_method' => PaymentMethod::Cash->value,
        ];
    }
}
