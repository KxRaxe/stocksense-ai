<?php

namespace App\Services\Forecasting;

use App\Enums\ForecastGranularity;
use Carbon\CarbonImmutable;

/**
 * The sales history of every product, ready to send to the ML service: units
 * sold per week or month, no gaps, every series ending on the same period.
 *
 * @phpstan-type SeriesPeriod array{start: string, qty: int, avg_price: float|null}
 * @phpstan-type SeriesRow array{series_key: string, product_id: int, category: string, periods: list<SeriesPeriod>}
 */
final class SeriesSet
{
    /**
     * @param  CarbonImmutable  $lastPeriod  Start of the last period of history, shared by every series
     * @param  list<SeriesRow>  $series
     */
    public function __construct(
        public readonly ForecastGranularity $granularity,
        public readonly CarbonImmutable $lastPeriod,
        public readonly array $series,
    ) {}

    public function isEmpty(): bool
    {
        return $this->series === [];
    }

    /**
     * @return list<int>
     */
    public function productIds(): array
    {
        return array_column($this->series, 'product_id');
    }

    /**
     * The body of the ML service's forecast request (see contracts/forecast-request.schema.json).
     *
     * @return array{granularity: string, horizon: int, series: list<SeriesRow>, options: array{backtest_folds: int}}
     */
    public function toRequest(int $horizon, int $backtestFolds): array
    {
        return [
            'granularity' => $this->granularity->value,
            'horizon' => $horizon,
            'series' => $this->series,
            'options' => ['backtest_folds' => $backtestFolds],
        ];
    }
}
