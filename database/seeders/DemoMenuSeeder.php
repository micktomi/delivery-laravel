<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoMenuSeeder extends Seeder
{
    public function run(): void
    {
        $this->removeLegacyPlaceholderProducts();
        $sizeDoseGroup = OptionGroup::query()
            ->where('name', 'Μέγεθος / Δόση')
            ->first();

        $categories = [
            [
                'name' => 'Καφέδες',
                'uses_size_dose' => true,
                'products' => [
                    ['name' => 'Espresso', 'base_price' => '2.20'],
                    ['name' => 'Espresso Ristretto', 'base_price' => '2.20'],
                    ['name' => 'Espresso Lungo', 'base_price' => '2.20'],
                    ['name' => 'Espresso Americano', 'base_price' => '2.20'],
                    ['name' => 'Cappuccino', 'base_price' => '2.50'],
                    ['name' => 'Freddo Espresso', 'base_price' => '2.20'],
                    ['name' => 'Freddo Cappuccino', 'base_price' => '2.50'],
                    ['name' => 'Macchiato', 'base_price' => '2.40'],
                    ['name' => 'Hot Latte', 'base_price' => '2.50'],
                    ['name' => 'Iced Latte', 'base_price' => '2.60'],
                    ['name' => 'Nescafe', 'base_price' => '2.20'],
                    ['name' => 'Frappe', 'base_price' => '2.20'],
                    ['name' => 'Ελληνικός', 'base_price' => '2.20'],
                ],
            ],
            [
                'name' => 'Ροφήματα',
                'uses_size_dose' => false,
                'products' => [
                    ['name' => 'Mochaccino', 'base_price' => '4.20'],
                    ['name' => 'Σοκολάτα Ζεστή', 'base_price' => '3.50'],
                    ['name' => 'Σοκολάτα Vienna', 'base_price' => '4.00'],
                    ['name' => 'Λευκή Σοκολάτα', 'base_price' => '3.50'],
                    ['name' => 'Κακάο', 'base_price' => '2.20'],
                    ['name' => 'Τσάι', 'base_price' => '2.20'],
                ],
            ],
            [
                'name' => 'Χυμοί',
                'uses_size_dose' => false,
                'products' => [
                    ['name' => 'Πορτοκάλι', 'base_price' => '2.80'],
                    ['name' => 'Ανάμεικτος Φυσικός Χυμός', 'base_price' => '3.50'],
                    ['name' => 'Φυσική Λεμονάδα', 'base_price' => '3.00'],
                    ['name' => 'Drive Energy', 'base_price' => '4.00'],
                ],
            ],
            [
                'name' => 'Γλυκά',
                'uses_size_dose' => false,
                'products' => [
                    ['name' => 'Pancakes', 'base_price' => '4.50'],
                    ['name' => 'Βάφλα Σοκολάτα', 'base_price' => '5.00'],
                    ['name' => 'Cake Βανίλια', 'base_price' => '2.50'],
                    ['name' => 'Cake Σοκολάτα', 'base_price' => '2.50'],
                    ['name' => 'Μηλόπιτα', 'base_price' => '3.50'],
                    ['name' => 'Cookies Σοκολάτα', 'base_price' => '3.00'],
                    ['name' => 'Donut Σοκολάτα', 'base_price' => '3.00'],
                ],
            ],
            [
                'name' => 'Αλμυρά',
                'uses_size_dose' => false,
                'products' => [
                    ['name' => 'Τυρόπιτα', 'base_price' => '2.00'],
                    ['name' => 'Λουκανικόπιτα', 'base_price' => '2.50'],
                    ['name' => 'Κρουασάν Ζαμπόν-Τυρί', 'base_price' => '3.00'],
                    ['name' => 'Brioche Mortadella', 'base_price' => '2.80'],
                    ['name' => 'Brioche Prosciutto', 'base_price' => '2.80'],
                    ['name' => 'Sandwich Μπαγκέτα Κοτόπουλο', 'base_price' => '4.00'],
                    ['name' => "Caesar's Salad", 'base_price' => '6.00'],
                ],
            ],
            [
                'name' => 'Αναψυκτικά',
                'uses_size_dose' => false,
                'products' => [
                    ['name' => 'Coca-Cola 330ml', 'base_price' => '2.00'],
                    ['name' => 'Coca-Cola Zero 330ml', 'base_price' => '2.00'],
                    ['name' => 'Sprite 330ml', 'base_price' => '2.00'],
                    ['name' => 'Νερό 500ml', 'base_price' => '0.50'],
                    ['name' => 'Monster 500ml', 'base_price' => '3.00'],
                ],
            ],
        ];

        foreach ($categories as $categorySort => $categoryData) {
            $category = Category::query()->updateOrCreate(
                ['slug' => Str::slug($categoryData['name'])],
                [
                    'name' => $categoryData['name'],
                    'sort_order' => $categorySort,
                    'is_active' => true,
                ],
            );

            foreach ($categoryData['products'] as $productSort => $productData) {
                $product = Product::query()->updateOrCreate(
                    [
                        'category_id' => $category->id,
                        'name' => $productData['name'],
                    ],
                    [
                        'base_price' => $productData['base_price'],
                        'is_available' => true,
                        'sort_order' => $productSort,
                    ],
                );

                if ($categoryData['uses_size_dose'] && $sizeDoseGroup) {
                    $product->optionGroups()->syncWithoutDetaching([
                        $sizeDoseGroup->id => ['sort_order' => 0],
                    ]);
                }
            }
        }
    }

    private function removeLegacyPlaceholderProducts(): void
    {
        $legacyCategory = Category::query()
            ->where('name', 'Καφέδες Κρύοι')
            ->where('slug', Str::slug('Καφέδες Κρύοι'))
            ->first();

        if (! $legacyCategory) {
            return;
        }

        foreach ([
            ['name' => 'Freddo Espresso', 'base_price' => '2.80'],
            ['name' => 'Freddo Cappuccino', 'base_price' => '3.20'],
            ['name' => 'Cold Brew', 'base_price' => '3.50'],
        ] as $legacyProduct) {
            $legacyProductIds = Product::query()
                ->where('category_id', $legacyCategory->id)
                ->where('name', $legacyProduct['name'])
                ->where('base_price', $legacyProduct['base_price'])
                ->pluck('id');

            OrderItem::query()
                ->whereIn('product_id', $legacyProductIds)
                ->update(['product_id' => null]);

            Product::query()
                ->whereKey($legacyProductIds)
                ->delete();
        }

        if (! $legacyCategory->products()->exists()) {
            $legacyCategory->delete();
        }
    }
}
