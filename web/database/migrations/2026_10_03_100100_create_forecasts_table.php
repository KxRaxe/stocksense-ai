<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a forecast run predicted: for each product and each period ahead, the
 * median (`yhat`) and the 10th and 90th percentiles around it, in units. Rows
 * carry the location they are for, so forecasting per branch later needs no
 * change here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            // First day of the week or month being forecast.
            $table->date('period_start');
            $table->decimal('yhat', 12, 2);
            $table->decimal('yhat_lower', 12, 2);
            $table->decimal('yhat_upper', 12, 2);
            // `xgboost` (the model) or `moving_average` (under a year of history).
            $table->string('method', 20);
            $table->boolean('low_confidence')->default(false);

            $table->unique(['forecast_run_id', 'product_id', 'period_start']);
            $table->index(['product_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecasts');
    }
};
