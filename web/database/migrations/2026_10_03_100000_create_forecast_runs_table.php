<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per forecast run. A run moves from `queued` (waiting for a worker)
 * through `running` (the ML service is training and forecasting) to `completed`
 * or `failed`. The accuracy measured on the run, the features the model leaned
 * on and the typical error per product are kept with it, so the Accuracy page
 * can show how forecasts have done over time. The forecasts themselves are in
 * `forecasts`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_runs', function (Blueprint $table) {
            $table->id();
            $table->string('granularity', 10);
            $table->unsignedSmallInteger('horizon');
            $table->string('status', 20);
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            // The model that made the forecasts, as named by the ML service.
            $table->string('model_version', 100)->nullable();
            // Start of the last period of sales history the run used.
            $table->date('as_of')->nullable();
            $table->jsonb('metrics')->nullable();
            $table->jsonb('baseline_metrics')->nullable();
            $table->jsonb('per_category_metrics')->nullable();
            $table->jsonb('feature_importance')->nullable();
            // Typical forecast error per product (units per period), by product id.
            $table->jsonb('residual_std')->nullable();
            // What the model trained on: products, rows, backtest windows.
            $table->jsonb('training')->nullable();
            $table->text('error_message')->nullable();
            // Null for a scheduled run.
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['granularity', 'status', 'id']);
        });

        // Only one run per granularity and location may be going at a time. The
        // runner checks first, and this makes it true even for two requests that
        // arrive at the same instant: the second one fails to insert.
        DB::statement("create unique index forecast_runs_one_active on forecast_runs (granularity, location_id) where status in ('queued', 'running')");
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_runs');
    }
};
