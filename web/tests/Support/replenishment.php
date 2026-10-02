<?php

use App\Models\ForecastRun;
use App\Models\Product;
use App\Services\Inventory\StockService;
use Carbon\CarbonImmutable;

/*
 * Helpers shared by the replenishment and notification tests.
 */

/** Monday 5 October 2026: the first day of the forecasts made by forecastWith(), and "today" in these tests. */
const REPLENISH_TODAY = '2026-10-05';

function testToday(): CarbonImmutable
{
    return CarbonImmutable::parse(REPLENISH_TODAY);
}

/**
 * A completed weekly run, as of the week of 28 September 2026 and looking eight
 * weeks ahead from Monday 5 October, with a flat forecast for each product.
 *
 * @param  array<int, int|float>  $perWeek  Units a week, by product id
 * @param  array<int, int|float>  $errors  Typical forecast error per week, by product id (zero by default; pass `run: ['residual_std' => []]` for a run that gives none)
 * @param  array<string, mixed>  $run  Anything to change about the run itself
 */
function forecastWith(array $perWeek, array $errors = [], array $run = []): ForecastRun
{
    // Every product has a typical error: none, unless it is given.
    $residual = [];

    foreach ($perWeek as $productId => $units) {
        $residual[$productId] = $errors[$productId] ?? 0;
    }

    $forecastRun = completedRun([
        'as_of' => '2026-09-28',
        'horizon' => 8,
        'residual_std' => $residual,
        ...$run,
    ]);

    foreach ($perWeek as $productId => $units) {
        $weeks = [];

        for ($week = 0; $week < 8; $week++) {
            $weeks[] = [testToday()->addWeeks($week)->toDateString(), $units, round($units * 0.8, 2), round($units * 1.2, 2)];
        }

        forecastFor($forecastRun, Product::query()->findOrFail($productId), $weeks);
    }

    return $forecastRun;
}

/**
 * Puts stock on the shelf (and, if asked, on order) for a product.
 */
function putInStock(Product $product, int $onHand, int $onOrder = 0): void
{
    $stock = app(StockService::class);

    $stock->openingStock($product, $onHand);

    if ($onOrder > 0) {
        $stock->addOnOrder($product, $onOrder);
    }
}
