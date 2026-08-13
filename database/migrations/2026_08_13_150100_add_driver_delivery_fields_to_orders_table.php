<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('driver_id')->nullable()->after('status')
                ->constrained('drivers')->restrictOnDelete();
            $table->string('delivery_status')->nullable()->after('driver_id')->index();
            $table->index(['status', 'driver_id', 'delivery_status'], 'orders_driver_claim_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_driver_claim_lookup_index');
            $table->dropIndex(['delivery_status']);
            $table->dropConstrainedForeignId('driver_id');
        });
    }
};
