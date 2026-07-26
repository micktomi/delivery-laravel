<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

    public function test_a_category_uses_images_only_when_every_product_has_one(): void
    {
        $category = $this->category('Καφέδες');
        $this->product('Espresso', 'products/one.jpg', $category);
        $this->product('Cappuccino', 'products/two.jpg', $category);

        $this->assertTrue($category->load('products')->uses_images);
    }

    public function test_one_product_without_an_image_drops_the_whole_category(): void
    {
        $category = $this->category('Καφέδες');
        $this->product('Espresso', 'products/one.jpg', $category);
        $this->product('Cappuccino', null, $category);

        $this->assertFalse($category->load('products')->uses_images);
    }

    public function test_an_empty_category_does_not_use_images(): void
    {
        $category = $this->category('Καφέδες');

        $this->assertFalse($category->load('products')->uses_images);
    }

    public function test_it_never_lazy_loads_products_to_answer(): void
    {
        $category = $this->category('Καφέδες');
        $this->product('Espresso', 'products/one.jpg', $category);

        $fresh = Category::query()->findOrFail($category->id);

        $queries = 0;
        \DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->assertFalse($fresh->uses_images);
        $this->assertSame(0, $queries, 'Reading uses_images lazy-loaded the products relation.');
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
