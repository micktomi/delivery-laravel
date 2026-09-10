<?php

namespace App\Actions;

use App\Enums\QuickSetupPreset;
use App\Enums\SelectionType;
use App\Models\Category;
use App\Models\OptionGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Operator-only provisioning helper behind the "Γρήγορο Στήσιμο" page:
 * creates the reusable categories/option groups/option values for a chosen
 * demo preset, plus the category-level template relationships that make
 * them apply to new products automatically (see ProductObserver::created()).
 *
 * This is provisioning data, not a runtime concept. Nothing else in the
 * application ever reads QuickSetupPreset or knows a preset was involved —
 * once this returns, the result is indistinguishable from an operator
 * having typed all of this into the admin by hand. No products are created.
 */
class ApplyQuickSetupPreset
{
    /**
     * @return array{categories: list<string>, option_groups: list<string>, option_values: int}
     */
    public function execute(QuickSetupPreset $preset): array
    {
        if ($this->catalogueHasExistingData()) {
            throw ValidationException::withMessages([
                'preset' => 'Ο κατάλογος δεν είναι άδειος (υπάρχουν ήδη κατηγορίες ή ομάδες επιλογών). '
                    .'Το Γρήγορο Στήσιμο εφαρμόζεται μόνο σε άδειο κατάλογο.',
            ]);
        }

        return DB::transaction(function () use ($preset): array {
            $definition = self::definition($preset);

            $categoriesByName = [];
            $createdCategoryNames = [];

            foreach ($definition['categories'] as $sortOrder => $name) {
                $category = Category::firstOrCreate(
                    ['slug' => Str::slug($name)],
                    ['name' => $name, 'sort_order' => $sortOrder, 'is_active' => true],
                );
                $categoriesByName[$name] = $category;

                if ($category->wasRecentlyCreated) {
                    $createdCategoryNames[] = $name;
                }
            }

            $createdGroupNames = [];
            $createdValueCount = 0;

            foreach ($definition['option_groups'] as $sortOrder => $groupData) {
                $group = OptionGroup::firstOrCreate(
                    ['name' => $groupData['name']],
                    [
                        'selection' => $groupData['selection']->value,
                        'is_required' => $groupData['is_required'],
                        'max_select' => $groupData['max_select'] ?? null,
                        'sort_order' => $sortOrder,
                    ],
                );

                if ($group->wasRecentlyCreated) {
                    $createdGroupNames[] = $groupData['name'];
                }

                foreach ($groupData['values'] as $valueSortOrder => $valueData) {
                    $value = $group->optionValues()->firstOrCreate(
                        ['name' => $valueData['name']],
                        [
                            'price_delta' => $valueData['price_delta'],
                            'is_default' => $valueData['is_default'],
                            'sort_order' => $valueSortOrder,
                        ],
                    );

                    if ($value->wasRecentlyCreated) {
                        $createdValueCount++;
                    }
                }

                // The template relationship: categories in this list get the
                // group attached so ProductObserver::created() copies it onto
                // every new product in them automatically. Attaching only
                // ever adds a missing row — an already-attached group's pivot
                // is left untouched.
                foreach ($groupData['template_categories'] as $categoryName) {
                    $category = $categoriesByName[$categoryName];
                    $alreadyAttached = $category->optionGroups()
                        ->where('option_groups.id', $group->id)
                        ->exists();

                    if (! $alreadyAttached) {
                        $category->optionGroups()->attach($group->id, ['sort_order' => $sortOrder]);
                    }
                }
            }

            return [
                'categories' => $createdCategoryNames,
                'option_groups' => $createdGroupNames,
                'option_values' => $createdValueCount,
            ];
        });
    }

    private function catalogueHasExistingData(): bool
    {
        return Category::query()->exists() || OptionGroup::query()->exists();
    }

    /**
     * The preset definitions themselves. Deliberately plain arrays, not a
     * generic "preset provider" abstraction — there are exactly two of
     * these, and a third would still just be another array here.
     *
     * @return array{
     *     categories: list<string>,
     *     option_groups: list<array{
     *         name: string,
     *         selection: SelectionType,
     *         is_required: bool,
     *         max_select: int|null,
     *         template_categories: list<string>,
     *         values: list<array{name: string, price_delta: float, is_default: bool}>,
     *     }>,
     * }
     */
    public static function definition(QuickSetupPreset $preset): array
    {
        return match ($preset) {
            QuickSetupPreset::GrillHouse => [
                'categories' => ['Σουβλάκια', 'Μερίδες', 'Σαλάτες', 'Ορεκτικά', 'Αναψυκτικά'],
                'option_groups' => [
                    [
                        'name' => 'Ψήσιμο',
                        'selection' => SelectionType::Single,
                        'is_required' => true,
                        'max_select' => null,
                        // Σουβλάκια/Μερίδες are the grilled-meat categories;
                        // salads/starters/drinks have no "doneness" to pick.
                        'template_categories' => ['Σουβλάκια', 'Μερίδες'],
                        'values' => [
                            ['name' => 'Μέτριο', 'price_delta' => 0.0, 'is_default' => true],
                            ['name' => 'Καλοψημένο', 'price_delta' => 0.0, 'is_default' => false],
                        ],
                    ],
                    [
                        'name' => 'Πίτα',
                        'selection' => SelectionType::Single,
                        'is_required' => false,
                        'max_select' => null,
                        'template_categories' => ['Σουβλάκια', 'Μερίδες'],
                        'values' => [
                            ['name' => 'Κανονική', 'price_delta' => 0.0, 'is_default' => true],
                            ['name' => 'Ολικής', 'price_delta' => 0.0, 'is_default' => false],
                        ],
                    ],
                    [
                        'name' => 'Σάλτσα',
                        'selection' => SelectionType::Single,
                        'is_required' => false,
                        'max_select' => null,
                        'template_categories' => ['Σουβλάκια', 'Μερίδες'],
                        'values' => [
                            ['name' => 'Τζατζίκι', 'price_delta' => 0.0, 'is_default' => false],
                            ['name' => 'Σως', 'price_delta' => 0.0, 'is_default' => false],
                            ['name' => 'Χωρίς', 'price_delta' => 0.0, 'is_default' => true],
                        ],
                    ],
                    [
                        'name' => 'Extras',
                        'selection' => SelectionType::Multi,
                        'is_required' => false,
                        'max_select' => null,
                        'template_categories' => ['Σουβλάκια', 'Μερίδες'],
                        'values' => [
                            ['name' => 'Τυρί', 'price_delta' => 0.50, 'is_default' => false],
                            ['name' => 'Μπέικον', 'price_delta' => 0.80, 'is_default' => false],
                            ['name' => 'Πατάτες', 'price_delta' => 1.00, 'is_default' => false],
                        ],
                    ],
                ],
            ],
            QuickSetupPreset::Restaurant => [
                'categories' => ['Ορεκτικά', 'Σαλάτες', 'Κυρίως', 'Ζυμαρικά', 'Επιδόρπια', 'Αναψυκτικά'],
                'option_groups' => [
                    [
                        'name' => 'Ψήσιμο',
                        'selection' => SelectionType::Single,
                        'is_required' => true,
                        'max_select' => null,
                        // Only the mains category involves a doneness choice.
                        'template_categories' => ['Κυρίως'],
                        'values' => [
                            ['name' => 'Σενιάν', 'price_delta' => 0.0, 'is_default' => false],
                            ['name' => 'Μέτριο', 'price_delta' => 0.0, 'is_default' => true],
                            ['name' => 'Καλοψημένο', 'price_delta' => 0.0, 'is_default' => false],
                        ],
                    ],
                    [
                        'name' => 'Συνοδευτικό',
                        'selection' => SelectionType::Single,
                        'is_required' => true,
                        'max_select' => null,
                        'template_categories' => ['Κυρίως'],
                        'values' => [
                            ['name' => 'Πατάτες', 'price_delta' => 0.0, 'is_default' => true],
                            ['name' => 'Ρύζι', 'price_delta' => 0.0, 'is_default' => false],
                            ['name' => 'Λαχανικά', 'price_delta' => 0.0, 'is_default' => false],
                        ],
                    ],
                    [
                        'name' => 'Extra sauce',
                        'selection' => SelectionType::Single,
                        'is_required' => false,
                        'max_select' => null,
                        'template_categories' => ['Κυρίως'],
                        'values' => [
                            ['name' => 'Πιπεριού', 'price_delta' => 0.0, 'is_default' => false],
                            ['name' => 'Μανιταριών', 'price_delta' => 0.0, 'is_default' => false],
                            ['name' => 'Χωρίς', 'price_delta' => 0.0, 'is_default' => true],
                        ],
                    ],
                ],
            ],
        };
    }
}
