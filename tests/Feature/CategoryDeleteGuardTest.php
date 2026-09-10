<?php

namespace Tests\Feature;

use App\Filament\Resources\CategoryResource\Pages\EditCategory;
use App\Filament\Resources\CategoryResource\Pages\ListCategories;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * products.category_id is restrict-on-delete at the database level (see the
 * products table migration) — a category with products cannot be deleted
 * without first moving or removing them. Without the guards added here, an
 * operator clearing out a demo category by mistake would hit a raw query
 * exception instead of a clear explanation.
 */
class CategoryDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    private function categoryWithProduct(): Category
    {
        $category = Category::create(['name' => 'Σουβλάκια', 'slug' => 'souvlakia', 'sort_order' => 0, 'is_active' => true]);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Καλαμάκι Χοιρινό',
            'base_price' => '2.50',
            'is_available' => true,
            'sort_order' => 0,
        ]);

        return $category;
    }

    public function test_edit_page_refuses_to_delete_a_category_that_has_products(): void
    {
        $admin = User::factory()->admin()->create();
        $category = $this->categoryWithProduct();

        Livewire::actingAs($admin)
            ->test(EditCategory::class, ['record' => $category->getRouteKey()])
            ->callAction('delete');

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_edit_page_deletes_an_empty_category(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::create(['name' => 'Αναψυκτικά', 'slug' => 'anapsyktika', 'sort_order' => 0, 'is_active' => true]);

        Livewire::actingAs($admin)
            ->test(EditCategory::class, ['record' => $category->getRouteKey()])
            ->callAction('delete');

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_bulk_delete_blocks_the_whole_batch_when_any_selected_category_has_products(): void
    {
        $admin = User::factory()->admin()->create();
        $withProduct = $this->categoryWithProduct();
        $empty = Category::create(['name' => 'Σαλάτες', 'slug' => 'salates', 'sort_order' => 1, 'is_active' => true]);

        Livewire::actingAs($admin)
            ->test(ListCategories::class)
            ->callTableBulkAction('delete', [$withProduct->id, $empty->id]);

        // The whole batch is blocked, including the category that was safe to
        // delete on its own — simple and predictable beats a partial delete.
        $this->assertDatabaseHas('categories', ['id' => $withProduct->id]);
        $this->assertDatabaseHas('categories', ['id' => $empty->id]);
    }

    public function test_bulk_delete_removes_categories_with_no_products(): void
    {
        $admin = User::factory()->admin()->create();
        $first = Category::create(['name' => 'Μερίδες', 'slug' => 'merides', 'sort_order' => 0, 'is_active' => true]);
        $second = Category::create(['name' => 'Σαλάτες', 'slug' => 'salates', 'sort_order' => 1, 'is_active' => true]);

        Livewire::actingAs($admin)
            ->test(ListCategories::class)
            ->callTableBulkAction('delete', [$first->id, $second->id]);

        $this->assertDatabaseMissing('categories', ['id' => $first->id]);
        $this->assertDatabaseMissing('categories', ['id' => $second->id]);
    }
}
