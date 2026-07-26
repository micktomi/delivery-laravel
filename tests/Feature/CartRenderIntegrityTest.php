<?php

namespace Tests\Feature;

use App\Livewire\MenuPage;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The cart rows were drawn client-side by an Alpine x-for over a mirror of
 * $cart while Livewire morphed the same container, which produced stale and
 * blank rows. They are rendered by Livewire now, and these tests pin down the
 * two properties that keep morphing honest: one row per line in each of the two
 * cart copies, and a key that survives a quantity change.
 */
class CartRenderIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name, string $price): Product
    {
        $category = Category::firstOrCreate(
            ['slug' => 'kafedes'],
            ['name' => 'Καφέδες', 'sort_order' => 0, 'is_active' => true],
        );

        return Product::create([
            'category_id' => $category->id,
            'name' => $name,
            'base_price' => $price,
            'is_available' => true,
            'sort_order' => 0,
        ]);
    }

    /** @return list<string> */
    private function keys(string $html, string $scope): array
    {
        preg_match_all('#wire:key="'.$scope.'-cart-line-([a-f0-9]{32}-\d+)"#', $html, $matches);

        return $matches[1];
    }

    private function assertRowsMatchCart(string $html, int $expectedLines, string $context): void
    {
        $desktop = $this->keys($html, 'desktop');
        $mobile = $this->keys($html, 'mobile');

        $this->assertCount($expectedLines, $desktop, "desktop rows after {$context}");
        $this->assertCount($expectedLines, $mobile, "mobile rows after {$context}");
        $this->assertSame($desktop, array_unique($desktop), "duplicate desktop keys after {$context}");
        $this->assertSame($mobile, array_unique($mobile), "duplicate mobile keys after {$context}");
        $this->assertSame($desktop, $mobile, "desktop and mobile disagree after {$context}");
    }

    public function test_ten_consecutive_cart_operations_never_leave_a_stale_row(): void
    {
        $a = $this->product('Espresso', '2.20');
        $b = $this->product('Frappe', '2.00');

        $component = Livewire::test(MenuPage::class);

        $steps = [
            ['add A', fn () => $component->call('addDirectly', $a->id), 1],
            ['add B', fn () => $component->call('addDirectly', $b->id), 2],
            ['add A again', fn () => $component->call('addDirectly', $a->id), 3],
            ['increment A', fn () => $component->call('updateQty', 0, 2), 3],
            ['increment A again', fn () => $component->call('updateQty', 0, 3), 3],
            ['decrement A', fn () => $component->call('updateQty', 0, 2), 3],
            ['increment B', fn () => $component->call('updateQty', 1, 4), 3],
            ['decrement B to one', fn () => $component->call('updateQty', 1, 1), 3],
            ['decrement B to zero', fn () => $component->call('updateQty', 1, 0), 2],
            ['remove the last line', fn () => $component->call('removeFromCart', 1), 1],
        ];

        foreach ($steps as [$label, $action, $expectedLines]) {
            $action();

            $this->assertRowsMatchCart($component->html(), $expectedLines, $label);
        }

        $component->call('removeFromCart', 0);

        $html = $component->html();
        $this->assertSame([], $this->keys($html, 'desktop'));
        $this->assertStringContainsString('Άδειο καλάθι', $html);
    }

    public function test_a_quantity_change_keeps_the_same_key(): void
    {
        $product = $this->product('Espresso', '2.20');

        $component = Livewire::test(MenuPage::class)->call('addDirectly', $product->id);

        $before = $this->keys($component->html(), 'desktop');

        $component->call('updateQty', 0, 7);

        $this->assertSame($before, $this->keys($component->html(), 'desktop'));
        $this->assertNotSame([], $before);
    }

    public function test_identical_lines_get_distinct_keys(): void
    {
        $product = $this->product('Espresso', '2.20');

        $component = Livewire::test(MenuPage::class)
            ->call('addDirectly', $product->id)
            ->call('addDirectly', $product->id);

        $keys = $this->keys($component->html(), 'desktop');

        $this->assertCount(2, $keys);
        $this->assertSame($keys, array_unique($keys));
    }
}
