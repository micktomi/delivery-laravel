<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureKitchenAvailabilityAccess;
use App\Livewire\KitchenAvailability;
use App\Livewire\MenuPage;
use App\Models\Category;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerMenuAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_available_product_appears_in_the_customer_menu(): void
    {
        $product = $this->product('ON προϊόν', true);

        Livewire::test(MenuPage::class)
            ->assertSee($product->name)
            ->assertSeeHtml('data-product-card="'.$product->id.'"');
    }

    public function test_unavailable_product_is_hidden_and_cannot_be_added_to_the_cart(): void
    {
        $available = $this->product('Διαθέσιμο προϊόν', true);
        $unavailable = $this->product('OFF προϊόν', false, $available->category);

        Livewire::test(MenuPage::class)
            ->assertSee($available->name)
            ->assertDontSee($unavailable->name)
            ->assertDontSeeHtml('data-product-card="'.$unavailable->id.'"');

        try {
            Livewire::test(MenuPage::class)->call('addDirectly', $unavailable->id);
            $this->fail('An unavailable product should not be orderable.');
        } catch (ModelNotFoundException) {
            $this->assertSame([], app(CartService::class)->items());
        }
    }

    public function test_kitchen_toggle_is_reflected_on_customer_menu_refresh(): void
    {
        $product = $this->product('Προϊόν άμεσης αλλαγής', true);

        Livewire::test(MenuPage::class)
            ->assertSeeHtml('data-product-card="'.$product->id.'"')
            ->call('addDirectly', $product->id);

        $this->assertCount(1, app(CartService::class)->items());

        $pinHash = Hash::make('654321');
        config(['kitchen.availability_pin_hash' => $pinHash]);
        session([
            EnsureKitchenAvailabilityAccess::SESSION_KEY => EnsureKitchenAvailabilityAccess::fingerprint($pinHash),
        ]);

        $kitchen = Livewire::test(KitchenAvailability::class)
            ->call('setAvailability', $product->id, false)
            ->assertHasNoErrors();

        $this->assertFalse($product->fresh()->is_available);

        Livewire::test(MenuPage::class)
            ->assertDontSeeHtml('data-product-card="'.$product->id.'"')
            ->assertDontSee($product->name);

        $this->assertSame([], app(CartService::class)->items());

        $kitchen->call('setAvailability', $product->id, true)
            ->assertHasNoErrors();

        Livewire::test(MenuPage::class)
            ->assertSeeHtml('data-product-card="'.$product->id.'"')
            ->assertSee($product->name);
    }

    private function product(string $name, bool $available, ?Category $category = null): Product
    {
        $category ??= Category::query()->create([
            'name' => 'Καφέδες',
            'slug' => 'kafedes',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        return Product::query()->create([
            'category_id' => $category->id,
            'name' => $name,
            'base_price' => '2.80',
            'is_available' => $available,
            'sort_order' => 0,
        ]);
    }
}
