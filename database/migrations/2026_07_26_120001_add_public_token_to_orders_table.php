<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->after('display_number');
        });

        DB::table('orders')->whereNull('public_token')->orderBy('id')->each(function ($order) {
            DB::table('orders')->where('id', $order->id)->update([
                'public_token' => Str::random(40),
            ]);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable(false)->change();
            $table->unique('public_token');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn('public_token');
        });
    }
};
