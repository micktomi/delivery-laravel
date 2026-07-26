<?php

namespace Tests\Feature;

use App\Livewire\MenuPage;
use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Product;
use App\Services\CartService;
use App\Enums\SelectionType;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * B4: quantity and option ids arrive from the browser. Negative or oversized
 * values used to reach the database and either produce negative money or a
 * permanent 500 on every following checkout.
 */
class CartQuantityTest extends TestCase
{
    use RefreshDatabase;

    private function product(bool $available = true, bool $activeCategory = true): Product
    {
        $category = Category::create([
            'name' => 'Καφέδες '.uniqid(),
            'slug' => 'kafedes-'.uniqid(),
            'sort_order' => 0,
            'is_active' => $activeCategory,
        ]);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Freddo Espresso',
            'base_price' => '2.00',
            'is_available' => $available,
            'sort_order' => 0,
        ]);
    }

    public function test_negative_quantity_cannot_reach_the_cart(): void
    {
        $product = $this->product();

        Livewire::test(MenuPage::class)
            ->call('openProduct', $product->id)
            ->set('quantity', -5)
            ->call('addToCart');

        $line = app(CartService::class)->items()[0];

        $this->assertSame(1, $line['quantity']);
        $this->assertEquals(2.00, $line['line_total']);
        $this->assertGreaterThan(0, $line['line_total']);
    }

    public function test_oversized_quantity_is_clamped(): void
    {
        $product = $this->product();

        Livewire::test(MenuPage::class)
            ->call('openProduct', $product->id)
            ->set('quantity', 99999)
            ->call('addToCart');

        $line = app(CartService::class)->items()[0];

        $this->assertSame(CartService::MAX_QUANTITY, $line['quantity']);
        $this->assertEquals(198.00, $line['line_total']);
    }

    public function test_update_quantity_is_clamped_too(): void
    {
        $product = $this->product();

        $component = Livewire::test(MenuPage::class)
            ->call('addDirectly', $product->id);

        $component->call('updateQty', 0, 99999);
        $this->assertSame(CartService::MAX_QUANTITY, app(CartService::class)->items()[0]['quantity']);

        $component->call('updateQty', 0, -3);
        $this->assertSame([], app(CartService::class)->items(), 'qty < 1 removes the line');
    }

    public function test_unavailable_product_cannot_be_added(): void
    {
        $product = $this->product(available: false);

        // Route-model style failure: over HTTP this is a 404, never a cart line.
        $this->expectException(ModelNotFoundException::class);

        try {
            Livewire::test(MenuPage::class)->call('addDirectly', $product->id);
        } finally {
            $this->assertSame([], app(CartService::class)->items());
        }
    }

    public function test_product_of_an_inactive_category_cannot_be_added(): void
    {
        $product = $this->product(activeCategory: false);

        $this->expectException(ModelNotFoundException::class);

        try {
            Livewire::test(MenuPage::class)->call('addDirectly', $product->id);
        } finally {
            $this->assertSame([], app(CartService::class)->items());
        }
    }

    public function test_duplicate_multi_select_options_are_counted_once(): void
    {
        $product = $this->product();

        $group = OptionGroup::create([
            'name' => 'Extras καφέ',
            'selection' => SelectionType::Multi->value,
            'is_required' => false,
            'max_select' => 3,
            'sort_order' => 0,
        ]);
        $extra = OptionValue::create([
            'option_group_id' => $group->id,
            'name' => 'Σαντιγί',
            'price_delta' => '0.50',
            'is_default' => false,
            'sort_order' => 0,
        ]);
        $product->optionGroups()->attach($group->id, ['sort_order' => 0]);

        Livewire::test(MenuPage::class)
            ->call('openProduct', $product->id)
            ->set('selectedOptions.'.$group->id, [$extra->id, $extra->id, $extra->id])
            ->call('addToCart');

        $line = app(CartService::class)->items()[0];

        $this->assertCount(1, $line['selected_options']);
        $this->assertEquals(2.50, $line['line_total']);
    }

    public function test_more_options_than_max_select_are_refused(): void
    {
        $product = $this->product();

        $group = OptionGroup::create([
            'name' => 'Extras καφέ',
            'selection' => SelectionType::Multi->value,
            'is_required' => false,
            'max_select' => 1,
            'sort_order' => 0,
        ]);
        $values = collect(['Σαντιγί', 'Κανέλα'])->map(fn ($name) => OptionValue::create([
            'option_group_id' => $group->id,
            'name' => $name,
            'price_delta' => '0.50',
            'is_default' => false,
            'sort_order' => 0,
        ]));
        $product->optionGroups()->attach($group->id, ['sort_order' => 0]);

        Livewire::test(MenuPage::class)
            ->call('openProduct', $product->id)
            ->set('selectedOptions.'.$group->id, $values->pluck('id')->all())
            ->call('addToCart')
            ->assertHasErrors('options');

        $this->assertSame([], app(CartService::class)->items());
    }

    public function test_option_ids_are_stored_so_prices_can_be_re_checked(): void
    {
        $product = $this->product();

        $group = OptionGroup::create([
            'name' => 'Μέγεθος / Δόση',
            'selection' => SelectionType::Single->value,
            'is_required' => true,
            'sort_order' => 0,
        ]);
        $value = OptionValue::create([
            'option_group_id' => $group->id,
            'name' => 'Διπλός',
            'price_delta' => '0.70',
            'is_default' => true,
            'sort_order' => 0,
        ]);
        $product->optionGroups()->attach($group->id, ['sort_order' => 0]);

        Livewire::test(MenuPage::class)
            ->call('openProduct', $product->id)
            ->call('addToCart');

        $option = app(CartService::class)->items()[0]['selected_options'][0];

        $this->assertSame($value->id, $option['option_value_id']);
        $this->assertEquals(0.70, $option['price_delta']);
    }
}
