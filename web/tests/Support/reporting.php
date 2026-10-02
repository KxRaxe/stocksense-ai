<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Recommendation;
use App\Services\Replenishment\RecommendationGenerator;

/*
 * Helpers shared by the dashboard and report tests.
 */

/**
 * A small shop with known numbers. "Today" is Monday 5 October 2026, so the last 30 days
 * are 6 September to 5 October: rice sold 30 units for 1,500, nails 100 for 800; in the 30
 * before, rice sold 20 for 1,000. Stock at cost is 7,500 (10,500 at the shelf price), salt is
 * out of stock, and soap is about to run out (a critical recommendation to order 130).
 */
function smallShop(): void
{
    $food = Category::factory()->create(['name' => 'Food', 'service_level' => 95]);
    $hardware = Category::factory()->create(['name' => 'Hardware', 'service_level' => 95]);

    $rice = Product::factory()->for($food)->create(['name' => 'Rice', 'sku' => 'F-1', 'unit_cost' => 40, 'unit_price' => 50, 'lead_time_days' => 7]);
    $nails = Product::factory()->for($hardware)->create(['name' => 'Nails', 'sku' => 'H-1', 'unit_cost' => 5, 'unit_price' => 8, 'lead_time_days' => 7]);
    $paint = Product::factory()->for($hardware)->create(['name' => 'Paint', 'sku' => 'H-2', 'unit_cost' => 100, 'unit_price' => 150, 'lead_time_days' => 7]);
    Product::factory()->for($food)->create(['name' => 'Salt', 'sku' => 'F-2', 'unit_cost' => 10, 'unit_price' => 15, 'lead_time_days' => 7]);
    $soap = Product::factory()->for($food)->create(['name' => 'Soap', 'sku' => 'F-3', 'unit_cost' => 0, 'unit_price' => 0, 'lead_time_days' => 7]);

    putInStock($rice, 100);
    putInStock($nails, 500);
    putInStock($paint, 10);
    putInStock($soap, 10);
    // Salt has none.

    sell($rice, [['2026-08-20', 20, 50], ['2026-09-10', 10, 50], ['2026-10-01', 20, 50]]);
    sell($nails, [['2026-09-20', 100, 8]]);

    forecastWith([$rice->id => 10, $nails->id => 20, $soap->id => 70]);
    app(RecommendationGenerator::class)->generate(testToday());
}

/** The soap recommendation from smallShop(). */
function soapRecommendation(): Recommendation
{
    return Recommendation::open()->whereHas('product', fn ($query) => $query->where('sku', 'F-3'))->sole();
}
