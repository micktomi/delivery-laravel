<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Snapshot: what the courier collects must never depend on a live
            // read of the coupon row, which the owner may edit afterwards.
            $table->string('coupon_code')->nullable()->after('subtotal');
            $table->decimal('discount_amount', 10, 2)->default(0)->after('coupon_code');
            // Reporting convenience only — never read for display or totals.
            $table->foreignId('coupon_id')->nullable()->after('discount_amount')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn(['coupon_code', 'discount_amount']);
        });
    }
};
