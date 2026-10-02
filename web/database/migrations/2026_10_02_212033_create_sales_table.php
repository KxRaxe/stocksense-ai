<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales history: the data the forecasts learn from. One row is one line of
 * sales (a product sold on a day). Imports can hold several rows for the same
 * product and day, for example one per receipt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->date('sold_on');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('total', 14, 2);
            $table->string('source', 10);                 // manual | import
            $table->foreignId('import_batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            // Forecasting reads sales by product and date.
            $table->index(['location_id', 'product_id', 'sold_on']);
            $table->index('sold_on');
            $table->index('import_batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
