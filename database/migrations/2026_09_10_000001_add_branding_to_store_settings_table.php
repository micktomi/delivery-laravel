<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expand-only: previous releases ignore these columns, so switching code
     * back never requires a database restore.
     */
    public function up(): void
    {
        Schema::table('store_settings', function (Blueprint $table): void {
            $table->string('store_name', 120)->default('Delivery Menu');
            $table->string('logo_path')->nullable();
            $table->string('brand_primary', 7)->default('#D97706');
            $table->string('brand_accent', 7)->default('#1C1206');
        });
    }

    public function down(): void
    {
        Schema::table('store_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'store_name',
                'logo_path',
                'brand_primary',
                'brand_accent',
            ]);
        });
    }
};
