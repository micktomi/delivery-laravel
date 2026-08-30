<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureKitchenAvailabilityAccess;
use App\Livewire\CheckoutPage;
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

    public function test_unavailable_product_stays_visible_as_disabled_and_cannot_be_added_to_the_cart(): void
    {
        $available = $this->product('Διαθέσιμο προϊόν', true);
        $unavailable = $this->product('OFF προϊόν', false, $available->category);
        $unavailable->update(['image' => 'products/off-product.webp']);

        $html = Livewire::test(MenuPage::class)
            ->assertSee($available->name)
            ->assertSee($unavailable->name)
            ->assertSee('Μη διαθέσιμο')
            ->assertSeeHtml('data-product-card="'.$unavailable->id.'"')
            ->html();

        $this->assertMatchesRegularExpression(
            '/data-product-card="'.$unavailable->id.'"[^>]*disabled/',
            $html,
        );
        $this->assertStringContainsString('data-product-image', $html);

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
            ->assertSeeHtml('data-product-card="'.$product->id.'"')
            ->assertSee($product->name)
            ->assertSee('Μη διαθέσιμο');

        $this->assertSame([], app(CartService::class)->items());

        $kitchen->call('setAvailability', $product->id, true)
            ->assertHasNoErrors();

        Livewire::test(MenuPage::class)
            ->assertSeeHtml('data-product-card="'.$product->id.'"')
            ->assertSee($product->name);
    }

    public function test_unavailable_cart_product_is_removed_recalculated_and_announced(): void
    {
        $available = $this->product('Διαθέσιμο προϊόν', true);
        $unavailable = $this->product('Cappuccino', true, $available->category);
        $unavailable->update(['base_price' => '3.70']);

        $this->addCartLine($available);
        $this->addCartLine($unavailable);

        $unavailable->update(['is_available' => false]);

        $notice = 'Το προϊόν Cappuccino δεν είναι πλέον διαθέσιμο και αφαιρέθηκε από την παραγγελία.';

        Livewire::test(MenuPage::class)
            ->assertSee($notice)
            ->assertSee('2,80');

        $cart = app(CartService::class);

        $this->assertSame([$available->id], array_column($cart->items(), 'product_id'));
        $this->assertEquals(2.80, $cart->totals()['total']);
        $this->assertSame($notice, session(CartService::UNAVAILABLE_PRODUCT_NOTICE_SESSION_KEY));

        Livewire::test(CheckoutPage::class)
            ->assertSee($notice)
            ->assertSeeHtml('data-unavailable-product-notice');
    }

    public function test_multiple_unavailable_cart_products_use_one_combined_notice(): void
    {
        $available = $this->product('Διαθέσιμο προϊόν', true);
        $firstUnavailable = $this->product('Cappuccino', true, $available->category);
        $secondUnavailable = $this->product('Freddo Espresso', true, $available->category);

        $this->addCartLine($firstUnavailable);
        $this->addCartLine($secondUnavailable);

        $firstUnavailable->update(['is_available' => false]);
        $secondUnavailable->update(['is_available' => false]);

        Livewire::test(MenuPage::class)
            ->assertSee('Τα προϊόντα Cappuccino και Freddo Espresso δεν είναι πλέον διαθέσιμα και αφαιρέθηκαν από την παραγγελία.');

        $this->assertSame([], app(CartService::class)->items());
    }

    public function test_no_unavailable_product_notice_is_shown_when_cart_does_not_change(): void
    {
        $product = $this->product('Διαθέσιμο προϊόν', true);
        $this->addCartLine($product);

        Livewire::test(MenuPage::class)
            ->assertDontSee('δεν είναι πλέον διαθέσιμο και αφαιρέθηκε από την παραγγελία.')
            ->assertDontSeeHtml('data-unavailable-product-notice');
    }

    private function addCartLine(Product $product): void
    {
        app(CartService::class)->add([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'base_price' => (float) $product->base_price,
            'selected_options' => [],
            'quantity' => 1,
            'line_total' => (float) $product->base_price,
            'notes' => '',
        ]);
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
