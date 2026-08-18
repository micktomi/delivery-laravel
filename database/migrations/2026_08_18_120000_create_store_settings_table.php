<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('accepting_orders');
            $table->json('opening_hours')->nullable();
            $table->text('closed_message')->nullable();
        });

        DB::table('store_settings')->insert([
            'id' => 1,
            'accepting_orders' => false,
            'opening_hours' => json_encode([], JSON_THROW_ON_ERROR),
            'closed_message' => null,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('store_settings');
    }
};
