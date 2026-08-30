<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('unit_price', 10, 2)->nullable()->after('base_price');
        });

        // Preserve a meaningful price snapshot for historical orders as well.
        DB::table('order_items')
            ->where('quantity', '>', 0)
            ->update(['unit_price' => DB::raw('ROUND(line_total / quantity, 2)')]);
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('unit_price');
        });
    }
};
