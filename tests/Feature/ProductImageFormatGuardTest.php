<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The two guards around HEIC: refuse it at upload, and surface anything that
 * slipped through in the one place a café owner actually looks.
 */
class ProductImageFormatGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_upload_field_only_accepts_formats_gd_can_re_encode(): void
    {
        $field = collect(ProductResource::form(new Form(new ListProducts))->getComponents())
            ->first(fn ($component) => $component instanceof FileUpload && $component->getName() === 'image');

        $this->assertNotNull($field, 'The product form no longer has an image upload field.');

        $accepted = $field->getAcceptedFileTypes();

        $this->assertEqualsCanonicalizing(
            ['image/jpeg', 'image/png', 'image/webp'],
            $accepted,
        );

        // The whole point of the list: HEIC fails twice over, once in GD and
        // again in the browser, so it must never reach disk.
        $this->assertNotContains('image/heic', $accepted);
        $this->assertNotContains('image/heif', $accepted);
    }

    public function test_a_non_webp_image_is_flagged(): void
    {
        $this->assertTrue(
            ProductResource::imageNeedsAttention($this->product('products/stuck.jpg')),
        );
    }

    public function test_a_webp_image_is_not_flagged(): void
    {
        $this->assertFalse(
            ProductResource::imageNeedsAttention($this->product('products/fine.webp')),
        );
    }

    public function test_a_product_with_no_photo_is_not_flagged(): void
    {
        $this->assertFalse(
            ProductResource::imageNeedsAttention($this->product(null)),
        );
    }

    public function test_an_uppercase_extension_is_not_mistaken_for_a_failure(): void
    {
        $this->assertFalse(
            ProductResource::imageNeedsAttention($this->product('products/shouty.WEBP')),
        );
    }

    public function test_the_product_list_renders_the_warning_column(): void
    {
        $stuck = $this->product('products/stuck.jpg');
        $fine = $this->product('products/fine.webp', 'Freddo');

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ListProducts::class)
            ->assertCanSeeTableRecords([$stuck, $fine])
            ->assertTableColumnStateSet('image_conversion', true, $stuck)
            ->assertTableColumnStateSet('image_conversion', false, $fine);
    }

    private function product(?string $image, string $name = 'Espresso'): Product
    {
        $category = Category::firstOrCreate(
            ['slug' => 'kafedes'],
            ['name' => 'Καφέδες', 'sort_order' => 0, 'is_active' => true],
        );

        return Product::create([
            'category_id' => $category->id,
            'name' => $name,
            'image' => $image,
            'base_price' => '2.20',
            'is_available' => true,
            'sort_order' => 0,
        ]);
    }
}
