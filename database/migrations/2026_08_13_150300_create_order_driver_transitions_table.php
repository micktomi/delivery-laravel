<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_driver_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_id')->constrained()->restrictOnDelete();
            $table->string('from_status');
            $table->string('to_status');
            $table->timestamp('transitioned_at')->useCurrent();
            $table->index(['order_id', 'transitioned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_driver_transitions');
    }
};
