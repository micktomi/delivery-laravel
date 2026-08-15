<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_status', 32)->nullable()->after('payment_method')->index();
            $table->string('viva_order_code', 32)->nullable()->after('payment_status')->unique();
            $table->uuid('viva_transaction_id')->nullable()->after('viva_order_code')->unique();
            $table->timestamp('paid_at')->nullable()->after('viva_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['viva_transaction_id']);
            $table->dropUnique(['viva_order_code']);
            $table->dropIndex(['payment_status']);
            $table->dropColumn([
                'payment_status',
                'viva_order_code',
                'viva_transaction_id',
                'paid_at',
            ]);
        });
    }
};
