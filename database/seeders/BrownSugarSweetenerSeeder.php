<?php

namespace Database\Seeders;

use App\Models\OptionGroup;
use App\Models\OptionValue;
use Illuminate\Database\Seeder;

/**
 * OptionGroupSeeder already carries "Καστανή ζάχαρη" for fresh installs; this
 * seeder applies the same catalogue row to a database that was seeded before
 * it existed, using firstOrCreate so re-running it is a no-op.
 */
class BrownSugarSweetenerSeeder extends Seeder
{
    public function run(): void
    {
        $group = OptionGroup::where('name', 'Γλυκαντικό')->first();

        if (! $group) {
            return;
        }

        OptionValue::firstOrCreate(
            ['option_group_id' => $group->id, 'name' => 'Καστανή ζάχαρη'],
            [
                'price_delta' => 0,
                'is_default' => false,
                'sort_order' => ($group->optionValues()->max('sort_order') ?? -1) + 1,
            ],
        );
    }
}
