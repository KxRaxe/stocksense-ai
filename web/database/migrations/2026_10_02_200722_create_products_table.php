<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 64)->unique();
            $table->string('name');
            // A category cannot be deleted while it still has products.
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('unit', 20)->default('pc');
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->decimal('unit_price', 12, 2)->default(0);

            // Replenishment inputs. Quantities are whole units.
            $table->unsignedSmallInteger('lead_time_days')->default(7);
            $table->unsignedInteger('moq')->default(1);          // minimum order quantity
            $table->unsignedInteger('pack_size')->default(1);    // orders come in multiples of this
            $table->unsignedInteger('reorder_point_override')->nullable();
            $table->unsignedInteger('safety_stock_override')->nullable();

            // Products are archived, not deleted, so sales and stock history stay intact.
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
