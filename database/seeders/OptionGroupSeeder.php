<?php

// PLACEHOLDER — replace with real café option groups after catalog delivery

namespace Database\Seeders;

use App\Enums\SelectionType;
use App\Models\OptionGroup;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class OptionGroupSeeder extends Seeder
{
    use WithoutModelEvents;

    public static array $groupMap = [];

    public function run(): void
    {
        $groups = [
            [
                'name' => 'Μέγεθος / Δόση',
                'selection' => SelectionType::Single->value,
                'is_required' => true,
                'values' => [
                    ['name' => 'Κανονικός', 'price_delta' => 0.00, 'is_default' => true],
                    ['name' => 'Διπλός', 'price_delta' => 0.70, 'is_default' => false],
                    ['name' => 'Extra Large / Τετραπλός', 'price_delta' => 1.40, 'is_default' => false],
                ],
            ],
            [
                'name' => 'Ζάχαρη',
                'selection' => SelectionType::Single->value,
                'is_required' => true,
                'values' => [
                    ['name' => 'Σκέτος', 'price_delta' => 0, 'is_default' => false],
                    ['name' => 'Μέτριος', 'price_delta' => 0, 'is_default' => true],
                    ['name' => 'Γλυκός', 'price_delta' => 0, 'is_default' => false],
                    ['name' => 'Πολύ γλυκός', 'price_delta' => 0, 'is_default' => false],
                ],
            ],
            [
                'name' => 'Γλυκαντικό',
                'selection' => SelectionType::Single->value,
                'is_required' => false,
                'values' => [
                    ['name' => 'Ζάχαρη', 'price_delta' => 0, 'is_default' => true],
                    ['name' => 'Στέβια', 'price_delta' => 0, 'is_default' => false],
                    ['name' => 'Ζαχαρίνη', 'price_delta' => 0, 'is_default' => false],
                ],
            ],
            [
                'name' => 'Γάλα',
                'selection' => SelectionType::Single->value,
                'is_required' => false,
                'values' => [
                    ['name' => 'Φρέσκο', 'price_delta' => 0, 'is_default' => true],
                    ['name' => 'Εβαπορέ', 'price_delta' => 0, 'is_default' => false],
                    ['name' => 'Αμυγδάλου', 'price_delta' => 0.50, 'is_default' => false],
                ],
            ],
            [
                'name' => 'Extras καφέ',
                'selection' => SelectionType::Multi->value,
                'is_required' => false,
                'values' => [
                    ['name' => 'Extra shot', 'price_delta' => 0.50, 'is_default' => false],
                    ['name' => 'Σαντιγί', 'price_delta' => 0.50, 'is_default' => false],
                ],
            ],
            [
                'name' => 'Αφαιρέσεις',
                'selection' => SelectionType::Multi->value,
                'is_required' => false,
                'values' => [
                    ['name' => 'Χωρίς ντομάτα', 'price_delta' => 0, 'is_default' => false],
                    ['name' => 'Χωρίς σως', 'price_delta' => 0, 'is_default' => false],
                ],
            ],
            [
                'name' => 'Extra υλικά',
                'selection' => SelectionType::Multi->value,
                'is_required' => false,
                'values' => [
                    ['name' => 'Extra τυρί', 'price_delta' => 0.50, 'is_default' => false],
                    ['name' => 'Extra γαλοπούλα', 'price_delta' => 1.00, 'is_default' => false],
                ],
            ],
        ];

        foreach ($groups as $sort => $data) {
            $values = $data['values'];
            unset($data['values']);
            $data['sort_order'] = $sort;

            $group = OptionGroup::create($data);
            self::$groupMap[$group->name] = $group->id;

            foreach ($values as $vSort => $valueData) {
                $valueData['sort_order'] = $vSort;
                $group->optionValues()->create($valueData);
            }
        }
    }
}
