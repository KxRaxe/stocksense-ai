<?php

use App\Enums\ForecastGranularity;
use App\Enums\ForecastStatus;
use App\Models\Forecast;
use App\Models\ForecastRun;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/*
 * Helpers shared by the forecasting tests.
 */

/**
 * Records sales straight into the table: [date, quantity, unit price] each.
 *
 * @param  list<array{0: string, 1: int, 2: float|int}>  $sales
 */
function sell(Product $product, array $sales, ?Location $location = null): void
{
    DB::table('sales')->insert(array_map(fn (array $sale) => [
        'product_id' => $product->id,
        'location_id' => ($location ?? Location::defaultLocation())->id,
        'sold_on' => $sale[0],
        'quantity' => $sale[1],
        'unit_price' => $sale[2],
        'total' => $sale[1] * $sale[2],
        'source' => 'manual',
        'created_at' => now(),
        'updated_at' => now(),
    ], $sales));
}

/**
 * A finished run, with accuracy figures, as if the ML service had answered.
 *
 * @param  array<string, mixed>  $overrides
 */
function completedRun(array $overrides = []): ForecastRun
{
    return ForecastRun::create([
        'granularity' => ForecastGranularity::Week,
        'horizon' => 4,
        'status' => ForecastStatus::Completed,
        'location_id' => Location::defaultLocation()->id,
        'model_version' => 'xgb-week-20261001T000000Z',
        'as_of' => '2026-09-21',
        'metrics' => ['mae' => 9.5, 'rmse' => 16.0, 'mape' => 26.0, 'wape' => 16.0, 'n' => 100, 'coverage' => 70.0],
        'baseline_metrics' => [
            'seasonal_naive' => ['mae' => 11.0, 'rmse' => 19.0, 'mape' => 31.0, 'wape' => 20.0, 'n' => 100, 'coverage' => null],
            'moving_average' => ['mae' => 10.0, 'rmse' => 17.0, 'mape' => 30.0, 'wape' => 18.0, 'n' => 100, 'coverage' => null],
        ],
        'per_category_metrics' => [
            'Hardware' => [
                'model' => ['mae' => 3.0, 'rmse' => 4.0, 'mape' => 25.0, 'wape' => 15.0, 'n' => 40, 'coverage' => 72.0],
                'seasonal_naive' => ['mae' => 4.0, 'rmse' => 5.0, 'mape' => 30.0, 'wape' => 19.0, 'n' => 40, 'coverage' => null],
                'moving_average' => ['mae' => 3.5, 'rmse' => 4.5, 'mape' => 28.0, 'wape' => 17.0, 'n' => 40, 'coverage' => null],
            ],
        ],
        'feature_importance' => [['feature' => 'roll_mean_4', 'importance' => 0.4], ['feature' => 'lag_52', 'importance' => 0.1]],
        'residual_std' => [],
        'training' => [
            'first_period' => '2024-09-30', 'last_period' => '2026-09-21', 'n_series' => 3, 'n_model_series' => 2,
            'n_low_confidence' => 1, 'n_rows' => 300, 'backtest_folds' => 3, 'backtest_origins' => ['2026-07-27', '2026-08-24', '2026-09-21'],
        ],
        'started_at' => '2026-10-01 02:00:00',
        'finished_at' => '2026-10-01 02:01:00',
        ...$overrides,
    ]);
}

/**
 * Forecast rows for a product: [period start, median, lower, upper] each.
 *
 * @param  list<array{0: string, 1: float|int, 2: float|int, 3: float|int}>  $periods
 */
function forecastFor(ForecastRun $run, Product $product, array $periods, string $method = 'xgboost', bool $lowConfidence = false): void
{
    foreach ($periods as [$start, $median, $lower, $upper]) {
        Forecast::create([
            'forecast_run_id' => $run->id,
            'product_id' => $product->id,
            'location_id' => $run->location_id,
            'period_start' => $start,
            'yhat' => $median,
            'yhat_lower' => $lower,
            'yhat_upper' => $upper,
            'method' => $method,
            'low_confidence' => $lowConfidence,
        ]);
    }
}
