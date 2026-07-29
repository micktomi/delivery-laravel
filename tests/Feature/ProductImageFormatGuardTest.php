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
 * What the upload field will and will not take, and what the list does with
 * the result. JPEG and PNG are ordinary stored formats now, not a symptom, so
 * the only thing still worth refusing is a format browsers cannot render.
 */
class ProductImageFormatGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_upload_field_accepts_the_three_web_image_formats(): void
    {
        $accepted = $this->uploadField()->getAcceptedFileTypes();

        $this->assertEqualsCanonicalizing(
            ['image/jpeg', 'image/png', 'image/webp'],
            $accepted,
        );

        // The whole point of the list: HEIC is what an iPhone hands over and
        // no browser will paint it, so it must never reach the disk.
        $this->assertNotContains('image/heic', $accepted);
        $this->assertNotContains('image/heif', $accepted);
    }

    public function test_the_upload_field_stores_onto_the_public_disk_under_products(): void
    {
        $field = $this->uploadField();

        $this->assertSame('public', $field->getDiskName());
        $this->assertSame('products', $field->getDirectory());
    }

    /**
     * A JPEG or PNG on the disk is a working photo, so the list must not
     * decorate it with a failure. The red warning column that used to sit here
     * reported a problem that no longer exists.
     */
    public function test_the_product_list_does_not_flag_a_non_webp_photo(): void
    {
        $jpeg = $this->product('products/stuck.jpg');
        $webp = $this->product('products/fine.webp', 'Freddo');

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ListProducts::class)
            ->assertCanSeeTableRecords([$jpeg, $webp])
            ->assertTableColumnDoesNotExist('image_conversion');
    }

    public function test_the_product_list_still_shows_the_photo_itself(): void
    {
        $product = $this->product('products/fine.webp');

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ListProducts::class)
            ->assertCanSeeTableRecords([$product])
            ->assertTableColumnExists('image');
    }

    private function uploadField(): FileUpload
    {
        $field = collect(ProductResource::form(new Form(new ListProducts))->getComponents())
            ->first(fn ($component) => $component instanceof FileUpload && $component->getName() === 'image');

        $this->assertNotNull($field, 'The product form no longer has an image upload field.');

        return $field;
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
