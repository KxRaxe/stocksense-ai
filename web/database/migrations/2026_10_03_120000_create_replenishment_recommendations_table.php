<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What to reorder, and what was decided about it. A row is one product's
 * assessment: the stock and forecast figures behind it, the quantity and date
 * the calculator suggests, and a plain-language explanation. It stays open
 * (pending, or info for overstock) while it applies and is refreshed in place
 * each time recommendations are generated; once someone decides it, the row is
 * kept as the record of who decided what and when.
 *
 * Advice only: accepting a recommendation records that the person will order
 * and counts the quantity as "on order". Nothing is ever ordered by the system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('replenishment_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            // The forecast it was based on.
            $table->foreignId('forecast_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('risk_level', 20);
            $table->string('status', 20);

            // The position when it was worked out.
            $table->integer('on_hand');
            $table->integer('on_order');
            $table->unsignedSmallInteger('lead_time_days');
            $table->decimal('lead_time_demand', 12, 2);
            $table->unsignedInteger('safety_stock');
            $table->unsignedInteger('reorder_point');
            $table->unsignedInteger('order_up_to');
            $table->decimal('days_of_cover', 8, 1)->nullable();

            // The advice.
            $table->unsignedInteger('recommended_qty');
            $table->date('order_by_date')->nullable();
            $table->text('explanation');

            // The decision.
            $table->unsignedInteger('final_qty')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('note')->nullable();
            // A dismissed recommendation stays quiet for this product until this date.
            $table->date('snoozed_until')->nullable();
            // An accepted order that will not be placed after all.
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'risk_level']);
            $table->index(['product_id', 'status']);
        });

        // A product has at most one open recommendation at a location. Generation
        // refreshes it in place, and this keeps that true even if two runs overlap.
        DB::statement("create unique index replenishment_one_open on replenishment_recommendations (product_id, location_id) where status in ('pending', 'info')");
    }

    public function down(): void
    {
        Schema::dropIfExists('replenishment_recommendations');
    }
};
