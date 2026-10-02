<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Current stock per product per location. This is a cache of the stock
 * movements ledger: on_hand always equals the sum of that product's movements
 * at that location, and StockService is the only code that changes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->integer('on_hand')->default(0);   // can go negative if sales outrun recorded stock
            $table->unsignedInteger('on_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_levels');
    }
};
