<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Structural, name-independent replacement for the option system's one
     * real cross-group behaviour: a group can be irrelevant once a specific
     * value is picked elsewhere (e.g. an optional add-on group that stops
     * applying once a "plain" choice is made), and a group's pick can be
     * folded into another group's displayed phrase instead of listed on its
     * own. Both are nullable/no-op by default, so every existing group keeps
     * behaving exactly as it does today until explicitly configured.
     */
    public function up(): void
    {
        Schema::table('option_groups', function (Blueprint $table): void {
            $table->foreignId('hidden_when_option_value_id')->nullable()->after('max_select')
                ->constrained('option_values')->nullOnDelete();
            $table->foreignId('combine_display_with_option_group_id')->nullable()->after('hidden_when_option_value_id')
                ->constrained('option_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('option_groups', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('combine_display_with_option_group_id');
            $table->dropConstrainedForeignId('hidden_when_option_value_id');
        });
    }
};
