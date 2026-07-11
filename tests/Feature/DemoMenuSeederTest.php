<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Database\Seeders\DemoMenuSeeder;
use Database\Seeders\OptionGroupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoMenuSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_idempotent_and_creates_the_expected_categories_and_products(): void
    {
        $this->seed(OptionGroupSeeder::class);
        $this->seed(DemoMenuSeeder::class);
        $this->seed(DemoMenuSeeder::class);

        $expectedProductCounts = [
            'Καφέδες' => 13,
            'Ροφήματα' => 6,
            'Χυμοί' => 4,
            'Γλυκά' => 7,
            'Αλμυρά' => 7,
            'Αναψυκτικά' => 5,
        ];

        $this->assertSame(6, Category::count());
        $this->assertSame(42, Product::count());

        foreach ($expectedProductCounts as $categoryName => $productCount) {
            $category = Category::query()->where('name', $categoryName)->first();

            $this->assertNotNull($category);
            $this->assertTrue($category->is_active);
            $this->assertSame($productCount, $category->products()->count());
        }
    }

    public function test_it_stores_the_requested_product_prices(): void
    {
        $this->seed(OptionGroupSeeder::class);
        $this->seed(DemoMenuSeeder::class);

        foreach ($this->expectedPrices() as $categoryName => $products) {
            $category = Category::query()->where('name', $categoryName)->firstOrFail();

            foreach ($products as $productName => $price) {
                $product = Product::query()
                    ->where('category_id', $category->id)
                    ->where('name', $productName)
                    ->firstOrFail();

                $this->assertSame($price, $product->base_price, $productName);
                $this->assertTrue($product->is_available, $productName);
            }
        }
    }

    public function test_coffee_option_groups_follow_the_explicit_product_assignment_map(): void
    {
        $this->seed(OptionGroupSeeder::class);
        $this->seed(DemoMenuSeeder::class);

        $coffeeCategory = Category::query()->where('name', 'Καφέδες')->firstOrFail();
        $coffeeGroupsWithoutMilk = [
            'Μέγεθος / Δόση',
            'Ζάχαρη',
            'Γλυκαντικό',
            'Extras καφέ',
        ];
        $coffeeGroupsWithMilk = [
            'Μέγεθος / Δόση',
            'Ζάχαρη',
            'Γλυκαντικό',
            'Γάλα',
            'Extras καφέ',
        ];
        $expectedGroupsByProduct = [
            'Espresso' => $coffeeGroupsWithoutMilk,
            'Espresso Ristretto' => $coffeeGroupsWithoutMilk,
            'Espresso Lungo' => $coffeeGroupsWithoutMilk,
            'Espresso Americano' => $coffeeGroupsWithoutMilk,
            'Cappuccino' => $coffeeGroupsWithMilk,
            'Freddo Espresso' => $coffeeGroupsWithoutMilk,
            'Freddo Cappuccino' => $coffeeGroupsWithMilk,
            'Macchiato' => $coffeeGroupsWithMilk,
            'Hot Latte' => $coffeeGroupsWithMilk,
            'Iced Latte' => $coffeeGroupsWithMilk,
            'Nescafe' => $coffeeGroupsWithoutMilk,
            'Frappe' => $coffeeGroupsWithoutMilk,
            'Ελληνικός' => $coffeeGroupsWithoutMilk,
        ];

        foreach ($expectedGroupsByProduct as $productName => $expectedGroups) {
            $product = $coffeeCategory->products()
                ->where('name', $productName)
                ->with('optionGroups')
                ->firstOrFail();

            $this->assertSame($expectedGroups, $product->optionGroups->pluck('name')->all(), $productName);
        }

        $this->assertNotContains('Γάλα', $expectedGroupsByProduct['Freddo Espresso']);
        $this->assertContains('Γάλα', $expectedGroupsByProduct['Freddo Cappuccino']);
        $this->assertContains('Γάλα', $expectedGroupsByProduct['Cappuccino']);
        $this->assertNotContains('Γάλα', $expectedGroupsByProduct['Espresso']);

        $this->assertSame(
            0,
            Product::query()
                ->where('category_id', '!=', $coffeeCategory->id)
                ->whereHas('optionGroups')
                ->count(),
        );
    }

    public function test_it_removes_only_the_known_legacy_placeholder_products(): void
    {
        $this->seed(OptionGroupSeeder::class);
        $this->seed(DemoMenuSeeder::class);

        $legacyCategory = Category::query()->create([
            'name' => 'Καφέδες Κρύοι',
            'slug' => 'kafedes-kryoi',
            'sort_order' => 99,
            'is_active' => true,
        ]);

        $legacyProducts = collect([
            ['name' => 'Freddo Espresso', 'base_price' => '2.80'],
            ['name' => 'Freddo Cappuccino', 'base_price' => '3.20'],
            ['name' => 'Cold Brew', 'base_price' => '3.50'],
        ])->map(fn (array $attributes) => Product::query()->create([
            'category_id' => $legacyCategory->id,
            'is_available' => true,
            'sort_order' => 0,
            ...$attributes,
        ]));

        $nonLegacyProduct = Product::query()->create([
            'category_id' => $legacyCategory->id,
            'name' => 'Προϊόν πελάτη',
            'base_price' => '9.99',
            'is_available' => true,
            'sort_order' => 1,
        ]);

        $order = Order::factory()->create();
        $orderItem = OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $legacyProducts->first()->id,
            'product_name' => 'Freddo Espresso',
            'base_price' => '2.80',
            'quantity' => 1,
            'selected_options' => [],
            'line_total' => '2.80',
        ]);

        $this->seed(DemoMenuSeeder::class);

        foreach ($legacyProducts as $legacyProduct) {
            $this->assertDatabaseMissing('products', ['id' => $legacyProduct->id]);
        }

        $this->assertDatabaseHas('products', ['id' => $nonLegacyProduct->id]);
        $this->assertDatabaseHas('order_items', [
            'id' => $orderItem->id,
            'product_id' => null,
        ]);
        $this->assertDatabaseHas('products', [
            'name' => 'Freddo Espresso',
            'base_price' => '2.20',
        ]);
    }

    private function expectedPrices(): array
    {
        return [
            'Καφέδες' => [
                'Espresso' => '2.20', 'Espresso Ristretto' => '2.20', 'Espresso Lungo' => '2.20',
                'Espresso Americano' => '2.20', 'Cappuccino' => '2.50', 'Freddo Espresso' => '2.20',
                'Freddo Cappuccino' => '2.50', 'Macchiato' => '2.40', 'Hot Latte' => '2.50',
                'Iced Latte' => '2.60', 'Nescafe' => '2.20', 'Frappe' => '2.20', 'Ελληνικός' => '2.20',
            ],
            'Ροφήματα' => [
                'Mochaccino' => '4.20', 'Σοκολάτα Ζεστή' => '3.50', 'Σοκολάτα Vienna' => '4.00',
                'Λευκή Σοκολάτα' => '3.50', 'Κακάο' => '2.20', 'Τσάι' => '2.20',
            ],
            'Χυμοί' => [
                'Πορτοκάλι' => '2.80', 'Ανάμεικτος Φυσικός Χυμός' => '3.50',
                'Φυσική Λεμονάδα' => '3.00', 'Drive Energy' => '4.00',
            ],
            'Γλυκά' => [
                'Pancakes' => '4.50', 'Βάφλα Σοκολάτα' => '5.00', 'Cake Βανίλια' => '2.50',
                'Cake Σοκολάτα' => '2.50', 'Μηλόπιτα' => '3.50', 'Cookies Σοκολάτα' => '3.00',
                'Donut Σοκολάτα' => '3.00',
            ],
            'Αλμυρά' => [
                'Τυρόπιτα' => '2.00', 'Λουκανικόπιτα' => '2.50', 'Κρουασάν Ζαμπόν-Τυρί' => '3.00',
                'Brioche Mortadella' => '2.80', 'Brioche Prosciutto' => '2.80',
                'Sandwich Μπαγκέτα Κοτόπουλο' => '4.00', "Caesar's Salad" => '6.00',
            ],
            'Αναψυκτικά' => [
                'Coca-Cola 330ml' => '2.00', 'Coca-Cola Zero 330ml' => '2.00', 'Sprite 330ml' => '2.00',
                'Νερό 500ml' => '0.50', 'Monster 500ml' => '3.00',
            ],
        ];
    }
}
