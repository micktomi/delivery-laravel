<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class GrillDemoSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Σουβλάκια',
                'products' => [
                    ['name' => 'Σουβλάκι Χοιρινό', 'description' => 'Ψητό χοιρινό σουβλάκι στα κάρβουνα.', 'base_price' => '3.20'],
                    ['name' => 'Σουβλάκι Κοτόπουλο', 'description' => 'Ψητό κοτόπουλο σουβλάκι στα κάρβουνα.', 'base_price' => '3.20'],
                    ['name' => 'Σουβλάκι Λουκάνικο', 'description' => 'Χωριάτικο λουκάνικο ψημένο στη σχάρα.', 'base_price' => '3.00'],
                    ['name' => 'Πίτα Γύρος Χοιρινός', 'description' => 'Πίτα με γύρο χοιρινό, ντομάτα, κρεμμύδι και τζατζίκι.', 'base_price' => '3.50'],
                    ['name' => 'Πίτα Γύρος Κοτόπουλο', 'description' => 'Πίτα με γύρο κοτόπουλο, ντομάτα, κρεμμύδι και τζατζίκι.', 'base_price' => '3.50'],
                    ['name' => 'Πίτα Γύρος Χοιρινός Σπέσιαλ', 'description' => 'Διπλή μερίδα γύρου χοιρινού με πατάτες μέσα στην πίτα.', 'base_price' => '4.20'],
                    ['name' => 'Πίτα Γύρος Κοτόπουλο Σπέσιαλ', 'description' => 'Διπλή μερίδα γύρου κοτόπουλου με πατάτες μέσα στην πίτα.', 'base_price' => '4.20'],
                    ['name' => 'Πίτα Σουβλάκι Χοιρινό Διπλή', 'description' => 'Πίτα με δύο σουβλάκια χοιρινό.', 'base_price' => '4.50'],
                    ['name' => 'Πίτα Σουβλάκι Κοτόπουλο Διπλή', 'description' => 'Πίτα με δύο σουβλάκια κοτόπουλο.', 'base_price' => '4.50'],
                    ['name' => 'Πίτα Μπιφτέκι', 'description' => 'Πίτα με σπιτικό μπιφτέκι στη σχάρα.', 'base_price' => '3.80'],
                    ['name' => 'Πίτα Kebab', 'description' => 'Πίτα με χειροποίητο kebab μπαχαρικών.', 'base_price' => '3.80'],
                    ['name' => 'Καλαμάκι Μπιφτέκι', 'description' => 'Καλαμάκι με μπιφτέκι στη σχάρα.', 'base_price' => '3.80'],
                ],
            ],
            [
                'name' => 'Μερίδες',
                'products' => [
                    ['name' => 'Μερίδα Σουβλάκι Χοιρινό', 'description' => 'Τρία σουβλάκια χοιρινό με πατάτες τηγανητές.', 'base_price' => '8.50'],
                    ['name' => 'Μερίδα Σουβλάκι Κοτόπουλο', 'description' => 'Τρία σουβλάκια κοτόπουλο με πατάτες τηγανητές.', 'base_price' => '8.50'],
                    ['name' => 'Μερίδα Γύρος Χοιρινός', 'description' => 'Γύρος χοιρινός με πατάτες τηγανητές και πίτες.', 'base_price' => '8.00'],
                    ['name' => 'Μερίδα Γύρος Κοτόπουλο', 'description' => 'Γύρος κοτόπουλο με πατάτες τηγανητές και πίτες.', 'base_price' => '8.00'],
                    ['name' => 'Μερίδα Μπιφτέκια', 'description' => 'Δύο σπιτικά μπιφτέκια με πατάτες τηγανητές.', 'base_price' => '8.80'],
                    ['name' => 'Μερίδα Kebab', 'description' => 'Δύο kebab μπαχαρικών με πατάτες τηγανητές.', 'base_price' => '9.00'],
                    ['name' => 'Μερίδα Κοντοσούβλι', 'description' => 'Κοντοσούβλι χοιρινό αργοψημένο με πατάτες τηγανητές.', 'base_price' => '9.50'],
                    ['name' => 'Μερίδα Παϊδάκια', 'description' => 'Παϊδάκια αρνίσια στη σχάρα με πατάτες τηγανητές.', 'base_price' => '11.00'],
                ],
            ],
            [
                'name' => 'Σαλάτες',
                'products' => [
                    ['name' => 'Χωριάτικη Σαλάτα', 'description' => 'Ντομάτα, αγγούρι, πιπεριά, κρεμμύδι, ελιές και φέτα.', 'base_price' => '6.50'],
                    ['name' => 'Αγγουροντοματοσαλάτα', 'description' => 'Φρέσκια σαλάτα ντομάτας και αγγουριού.', 'base_price' => '4.50'],
                    ['name' => 'Λαχανοσαλάτα', 'description' => 'Ψιλοκομμένο λάχανο με καρότο και λεμονολαδο.', 'base_price' => '3.50'],
                    ['name' => 'Πράσινη Σαλάτα', 'description' => 'Μαρούλι και εποχιακά λαχανικά με λαδολέμονο.', 'base_price' => '4.00'],
                    ['name' => 'Ρόκα με Παρμεζάνα', 'description' => 'Ρόκα, παρμεζάνα και μπαλσάμικο.', 'base_price' => '5.50'],
                ],
            ],
            [
                'name' => 'Ορεκτικά',
                'products' => [
                    ['name' => 'Τζατζίκι', 'description' => 'Σπιτικό τζατζίκι με γιαούρτι στραγγιστό και σκόρδο.', 'base_price' => '3.00'],
                    ['name' => 'Πατάτες Τηγανητές', 'description' => 'Φρέσκιες τηγανητές πατάτες.', 'base_price' => '3.00'],
                    ['name' => 'Πιτάκια Τυριού', 'description' => 'Χειροποίητα πιτάκια με τυρί, σε φύλλο κρούστας.', 'base_price' => '4.00'],
                    ['name' => 'Ντολμαδάκια', 'description' => 'Αμπελόφυλλα γεμιστά με ρύζι και μυρωδικά.', 'base_price' => '4.50'],
                    ['name' => 'Κεφτεδάκια', 'description' => 'Σπιτικά κεφτεδάκια με μυρωδικά, τηγανητά.', 'base_price' => '5.50'],
                    ['name' => 'Σαγανάκι', 'description' => 'Τυρί σαγανάκι με λεμόνι.', 'base_price' => '5.50'],
                    ['name' => 'Πιπεριές Φλωρίνης', 'description' => 'Ψητές πιπεριές Φλωρίνης με φέτα.', 'base_price' => '4.00'],
                ],
            ],
            [
                'name' => 'Αναψυκτικά',
                'products' => [
                    ['name' => 'Coca-Cola 330ml', 'description' => 'Αναψυκτικό κόλα σε κουτάκι.', 'base_price' => '2.00'],
                    ['name' => 'Coca-Cola Zero 330ml', 'description' => 'Αναψυκτικό κόλα χωρίς ζάχαρη σε κουτάκι.', 'base_price' => '2.00'],
                    ['name' => 'Sprite 330ml', 'description' => 'Αναψυκτικό λεμονάδα σε κουτάκι.', 'base_price' => '2.00'],
                    ['name' => 'Πορτοκαλάδα 330ml', 'description' => 'Ανθρακούχο αναψυκτικό πορτοκάλι σε κουτάκι.', 'base_price' => '2.00'],
                    ['name' => 'Νερό 500ml', 'description' => 'Εμφιαλωμένο φυσικό μεταλλικό νερό.', 'base_price' => '0.50'],
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
                Product::query()->updateOrCreate(
                    [
                        'category_id' => $category->id,
                        'name' => $productData['name'],
                    ],
                    [
                        'description' => $productData['description'],
                        'image' => null,
                        'base_price' => $productData['base_price'],
                        'is_available' => true,
                        'sort_order' => $productSort,
                    ],
                );
            }
        }
    }
}
