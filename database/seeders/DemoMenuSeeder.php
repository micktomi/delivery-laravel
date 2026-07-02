<?php

// PLACEHOLDER — replace with real café catalog after delivery

namespace Database\Seeders;

use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoMenuSeeder extends Seeder
{

    public function run(): void
    {
        $gm = fn (string $name) => OptionGroup::where('name', $name)->first();

        $categories = [
            [
                'name' => 'Καφέδες Κρύοι',
                'groups' => ['Μέγεθος / Δόση', 'Ζάχαρη', 'Γλυκαντικό', 'Γάλα', 'Extras καφέ'],
                'products' => [
                    ['name' => 'Freddo Espresso', 'base_price' => 2.80],
                    ['name' => 'Freddo Cappuccino', 'base_price' => 3.20],
                    ['name' => 'Cold Brew', 'base_price' => 3.50],
                ],
            ],
            [
                'name' => 'Καφέδες Ζεστοί',
                'groups' => ['Μέγεθος / Δόση', 'Ζάχαρη', 'Γλυκαντικό', 'Γάλα', 'Extras καφέ'],
                'products' => [
                    ['name' => 'Espresso', 'base_price' => 2.20],
                    ['name' => 'Cappuccino', 'base_price' => 3.00],
                    ['name' => 'Σοκολάτα', 'base_price' => 3.50],
                    ['name' => 'Americano', 'base_price' => 2.50],
                ],
            ],
            [
                'name' => 'Sandwich / Τοστ',
                'groups' => ['Αφαιρέσεις', 'Extra υλικά'],
                'products' => [
                    ['name' => 'Club Sandwich', 'base_price' => 4.50],
                    ['name' => 'Sandwich γαλοπούλα', 'base_price' => 3.80],
                    ['name' => 'Τοστ τυρί-ζαμπόν', 'base_price' => 2.80],
                ],
            ],
            [
                'name' => 'Σφολιάτες',
                'groups' => [],
                'products' => [
                    ['name' => 'Τυρόπιτα', 'base_price' => 2.00],
                    ['name' => 'Σπανακόπιτα', 'base_price' => 2.00],
                    ['name' => 'Κρουασάν βουτύρου', 'base_price' => 1.80],
                ],
            ],
            [
                'name' => 'Αναψυκτικά',
                'groups' => [],
                'products' => [
                    ['name' => 'Coca-Cola 330ml', 'base_price' => 1.50],
                    ['name' => 'Νερό 500ml', 'base_price' => 0.60],
                    ['name' => 'Χυμός πορτοκάλι', 'base_price' => 2.50],
                ],
            ],
        ];

        foreach ($categories as $catSort => $catData) {
            $groupNames = $catData['groups'];
            $products = $catData['products'];

            $category = Category::create([
                'name' => $catData['name'],
                'slug' => Str::slug($catData['name']),
                'sort_order' => $catSort,
                'is_active' => true,
            ]);

            // Attach template groups to category
            foreach ($groupNames as $gSort => $groupName) {
                $group = $gm($groupName);
                if ($group) {
                    $category->optionGroups()->attach($group->id, ['sort_order' => $gSort]);
                }
            }

            // Build category's group sync data once
            $categorySyncData = $category->optionGroups()
                ->withPivot('sort_order')
                ->get()
                ->mapWithKeys(fn ($g) => [$g->id => ['sort_order' => $g->pivot->sort_order]])
                ->all();

            // Create products and manually copy category template groups
            foreach ($products as $pSort => $productData) {
                $product = Product::create([
                    'category_id' => $category->id,
                    'name' => $productData['name'],
                    'base_price' => $productData['base_price'],
                    'is_available' => true,
                    'sort_order' => $pSort,
                ]);

                if ($categorySyncData) {
                    $product->optionGroups()->sync($categorySyncData);
                }
            }
        }
    }
}
