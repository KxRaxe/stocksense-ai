<?php

namespace App\Services\Forecasting;

use App\Enums\ForecastGranularity;
use App\Enums\ForecastStatus;
use App\Models\Forecast;
use App\Models\ForecastRun;
use App\Models\Location;
use Illuminate\Support\Facades\DB;

/**
 * Does the work of a forecast run: gathers the sales history, has the ML
 * service train and forecast, and stores the answer. A run's rows are written
 * together or not at all, so a run is never half there.
 */
class ForecastExecutor
{
    public function __construct(
        private readonly SeriesBuilder $series,
        private readonly MlClient $ml,
    ) {}

    /**
     * @throws ForecastException When there is nothing to forecast from, or the ML service cannot do it
     */
    public function execute(ForecastRun $run): void
    {
        $location = Location::query()->findOrFail($run->location_id);
        $set = $this->series->build($run->granularity, $location);

        if ($set->isEmpty()) {
            throw new ForecastException('There is no sales history to forecast from yet. Record or import some sales first.');
        }

        $response = $this->ml->forecast($set->toRequest($run->horizon, (int) config('forecasting.backtest_folds')));

        DB::transaction(function () use ($run, $location, $set, $response) {
            $this->store($run, $location, $set, $response);
            $this->prune($run->granularity);
        });
    }

    /**
     * @param  array<string, mixed>  $response  A forecast response (contracts/forecast-response.schema.json)
     */
    private function store(ForecastRun $run, Location $location, SeriesSet $set, array $response): void
    {
        $known = array_flip($set->productIds());
        $rows = [];

        /** @var list<array{product_id: int, method: string, low_confidence: bool, periods: list<array{start: string, yhat: float, yhat_lower: float, yhat_upper: float}>}> $forecasts */
        $forecasts = $response['forecasts'];

        foreach ($forecasts as $forecast) {
            // Only products that were asked about.
            if (! isset($known[$forecast['product_id']])) {
                continue;
            }

            foreach ($forecast['periods'] as $period) {
                $rows[] = [
                    'forecast_run_id' => $run->id,
                    'product_id' => $forecast['product_id'],
                    'location_id' => $location->id,
                    'period_start' => $period['start'],
                    'yhat' => $period['yhat'],
                    'yhat_lower' => $period['yhat_lower'],
                    'yhat_upper' => $period['yhat_upper'],
                    'method' => $forecast['method'],
                    'low_confidence' => $forecast['low_confidence'],
                ];
            }
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            Forecast::query()->insert($chunk);
        }

        /** @var array{last_period: string} $training */
        $training = $response['training'];

        $run->forceFill([
            'status' => ForecastStatus::Completed,
            'model_version' => $response['model_version'],
            'as_of' => $training['last_period'],
            'metrics' => $response['metrics'] ?? null,
            'baseline_metrics' => $response['baseline_metrics'] ?? null,
            'per_category_metrics' => ($response['per_category_metrics'] ?? []) ?: null,
            'feature_importance' => ($response['feature_importance'] ?? []) ?: null,
            'residual_std' => $this->spreadByProduct($response['residual_std'] ?? [], $known),
            'training' => $training,
            'error_message' => null,
            'finished_at' => now(),
        ])->save();
    }

    /**
     * The ML service names series by "product:location"; this keeps the product id.
     *
     * @param  array<string, float|int>  $bySeries
     * @param  array<int, int>  $known  Product ids that were asked about, as keys
     * @return array<int, float>
     */
    private function spreadByProduct(array $bySeries, array $known): array
    {
        $byProduct = [];

        foreach ($bySeries as $key => $value) {
            $productId = (int) explode(':', (string) $key)[0];

            if (isset($known[$productId])) {
                $byProduct[$productId] = (float) $value;
            }
        }

        return $byProduct;
    }

    /**
     * Keeps the forecasts of the latest few runs. Older runs keep their
     * accuracy figures (the Accuracy page tracks them over time) but not the
     * rows, which nothing looks at once a newer run exists.
     */
    private function prune(ForecastGranularity $granularity): void
    {
        $old = ForecastRun::query()
            ->completed($granularity)
            ->skip((int) config('forecasting.keep_runs'))
            ->take(PHP_INT_MAX)
            ->pluck('id');

        if ($old->isNotEmpty()) {
            Forecast::query()->whereIn('forecast_run_id', $old)->delete();
        }
    }
}
