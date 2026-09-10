<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * One-time backfill for databases that already seeded the coffee
     * catalogue before hidden_when_option_value_id/combine_display_with_
     * option_group_id existed: wires the same "Σκέτος hides/absorbs
     * Γλυκαντικό" relationship the runtime code used to hard-code by name,
     * as structural data instead. Matched once, here, against the known
     * legacy row names — not at request time. A fresh install seeds this
     * relationship itself (see OptionGroupSeeder) and this migration simply
     * finds nothing to do; a non-coffee instance has no such groups either.
     */
    public function up(): void
    {
        $sweetnessGroupId = DB::table('option_groups')->where('name', 'Ζάχαρη')->value('id');
        $sweetenerGroupId = DB::table('option_groups')->where('name', 'Γλυκαντικό')->value('id');

        if ($sweetnessGroupId === null || $sweetenerGroupId === null) {
            return;
        }

        $plainValueId = DB::table('option_values')
            ->where('option_group_id', $sweetnessGroupId)
            ->where('name', 'Σκέτος')
            ->value('id');

        if ($plainValueId === null) {
            return;
        }

        DB::table('option_groups')->where('id', $sweetenerGroupId)->update([
            'hidden_when_option_value_id' => $plainValueId,
            'combine_display_with_option_group_id' => $sweetnessGroupId,
        ]);
    }

    public function down(): void
    {
        $sweetenerGroupId = DB::table('option_groups')->where('name', 'Γλυκαντικό')->value('id');

        if ($sweetenerGroupId === null) {
            return;
        }

        DB::table('option_groups')->where('id', $sweetenerGroupId)->update([
            'hidden_when_option_value_id' => null,
            'combine_display_with_option_group_id' => null,
        ]);
    }
};
