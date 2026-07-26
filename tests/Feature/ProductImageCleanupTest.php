<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Filament\Tables\Actions\DeleteBulkAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ProductImageCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_product_removes_its_uploaded_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/one.jpg', 'x');

        $product = $this->product('Espresso', 'products/one.jpg');

        $product->delete();

        Storage::disk('public')->assertMissing('products/one.jpg');
    }

    public function test_deleting_a_product_without_an_image_touches_nothing(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/someone-elses.jpg', 'x');

        $product = $this->product('Espresso', null);

        $product->delete();

        Storage::disk('public')->assertExists('products/someone-elses.jpg');
    }

    public function test_replacing_an_image_removes_only_the_previous_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/old.jpg', 'x');
        Storage::disk('public')->put('products/new.jpg', 'x');

        $product = $this->product('Espresso', 'products/old.jpg');

        $product->update(['image' => 'products/new.jpg']);

        Storage::disk('public')->assertMissing('products/old.jpg');
        Storage::disk('public')->assertExists('products/new.jpg');
    }

    public function test_updating_an_unrelated_field_keeps_the_image(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/keep.jpg', 'x');

        $product = $this->product('Espresso', 'products/keep.jpg');

        $product->update(['base_price' => '9.99']);

        Storage::disk('public')->assertExists('products/keep.jpg');
    }

    /**
     * Filament's DeleteBulkAction deletes record by record today, so the
     * observer fires. This pins that down: an upgrade that switched it to a
     * mass query would orphan every uploaded file without any other signal.
     */
    public function test_filament_bulk_delete_removes_every_uploaded_file(): void
    {
        Storage::fake('public');

        $products = collect(['one', 'two', 'three'])->map(function (string $name) {
            Storage::disk('public')->put("products/{$name}.jpg", 'x');

            return $this->product(ucfirst($name), "products/{$name}.jpg");
        });

        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(ListProducts::class)
            ->callTableBulkAction(DeleteBulkAction::class, $products);

        foreach (['one', 'two', 'three'] as $name) {
            Storage::disk('public')->assertMissing("products/{$name}.jpg");
        }

        $this->assertSame(0, Product::count());
    }

    private function product(string $name, ?string $image): Product
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
