<?php

namespace Tests\Feature;

use App\Enums\SelectionType;
use App\Livewire\MenuPage;
use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_uploaded_image_resolves_to_a_public_disk_url(): void
    {
        Storage::fake('public');

        $product = $this->product('Espresso', 'products/custom.jpg');

        $this->assertSame(Storage::disk('public')->url('products/custom.jpg'), $product->image_url);
    }

    public function test_a_product_without_an_image_has_a_null_url(): void
    {
        $product = $this->product('Espresso', null);

        $this->assertNull($product->image_url);
    }

    public function test_a_mixed_image_category_renders_one_unified_product_grid(): void
    {
        $category = $this->category('Καφέδες');
        $this->product('Espresso', 'products/one.jpg', $category);
        $this->product('Cappuccino', null, $category);

        $html = Livewire::test(MenuPage::class)->html();

        $this->assertSame(1, substr_count($html, 'data-product-grid'));
        $this->assertSame(2, substr_count($html, 'data-product-card='));
        $this->assertStringNotContainsString('wire:key="row-', $html);
        $this->assertStringNotContainsString('<ul', $html);
    }

    public function test_a_product_with_an_image_renders_its_public_url(): void
    {
        Storage::fake('public');

        $category = $this->category('Καφέδες');
        $product = $this->product('Espresso', 'products/one.jpg', $category);

        Livewire::test(MenuPage::class)
            ->assertSeeHtml('data-product-image')
            ->assertSeeHtml('src="'.Storage::disk('public')->url($product->image).'"');
    }

    public function test_a_product_without_an_image_renders_a_placeholder_without_an_empty_source(): void
    {
        $category = $this->category('Καφέδες');
        $this->product('Espresso', null, $category);

        $html = Livewire::test(MenuPage::class)->html();

        $this->assertStringContainsString('data-product-placeholder aria-hidden="true"', $html);
        $this->assertStringNotContainsString('src=""', $html);
    }

    public function test_the_card_preserves_description_price_and_unavailable_state(): void
    {
        $category = $this->category('Καφέδες');
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Cold Brew',
            'description' => 'Με πάγο και βανίλια',
            'base_price' => '3.40',
            'is_available' => false,
            'sort_order' => 0,
        ]);
        $product->setRelation('optionGroups', collect());

        $this->view('livewire.partials.product-card', compact('product', 'category'))
            ->assertSee('Με πάγο και βανίλια')
            ->assertSee('3,40 €')
            ->assertSee('Εξαντλήθηκε')
            ->assertSeeHtml('clamp-2 text-[12px]');
    }

    public function test_products_with_options_keep_the_modal_action_and_direct_products_keep_the_add_action(): void
    {
        $category = $this->category('Καφέδες');
        $configured = $this->product('Espresso', null, $category);
        $direct = $this->product('Νερό', null, $category);
        $group = OptionGroup::create([
            'name' => 'Μέγεθος',
            'selection' => SelectionType::Single->value,
            'is_required' => false,
            'sort_order' => 0,
        ]);
        $configured->optionGroups()->attach($group->id, ['sort_order' => 0]);

        Livewire::test(MenuPage::class)
            ->assertSeeHtml('wire:click="openProduct('.$configured->id.')"')
            ->assertSeeHtml('wire:click="addDirectly('.$direct->id.')"');
    }

    private function category(string $name): Category
    {
        return Category::create([
            'name' => $name,
            'slug' => 'cat-'.uniqid(),
            'sort_order' => 0,
            'is_active' => true,
        ]);
    }

    private function product(string $name, ?string $image, ?Category $category = null): Product
    {
        return Product::create([
            'category_id' => ($category ?? $this->category('Καφέδες'))->id,
            'name' => $name,
            'image' => $image,
            'base_price' => '2.20',
            'is_available' => true,
            'sort_order' => 0,
        ]);
    }
}