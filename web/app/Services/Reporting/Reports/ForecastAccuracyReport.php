<?php

namespace App\Services\Reporting\Reports;

use App\Enums\ForecastStatus;
use App\Enums\Permission;
use App\Models\Category;
use App\Models\ForecastRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/**
 * How accurate the forecasts have been, run by run: the model's error against
 * the two plain yardsticks it has to beat, for all products and by category.
 * The date is when each run finished.
 */
class ForecastAccuracyReport implements Report
{
    public function key(): string
    {
        return 'forecast-accuracy';
    }

    public function title(): string
    {
        return 'Forecast accuracy';
    }

    public function description(): string
    {
        return 'How far off each forecast run was on recent history (MAE, RMSE, MAPE, WAPE), against the same week last year and a recent average.';
    }

    public function permission(): Permission
    {
        return Permission::ViewReports;
    }

    public function filters(): array
    {
        return ['date', 'category', 'granularity'];
    }

    public function defaultRange(CarbonImmutable $today): array
    {
        return [$today->subDays(89), $today];
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $runs = ForecastRun::query()
            ->where('granularity', $filters->granularity->value)
            ->where('status', ForecastStatus::Completed->value)
            ->whereBetween('finished_at', [$filters->from->startOfDay(), $filters->to->endOfDay()])
            ->orderByDesc('id')
            ->get();

        $category = $filters->categoryId === null ? null : Category::query()->whereKey($filters->categoryId)->value('name');

        $rows = [];

        foreach ($runs as $run) {
            $date = $run->finished_at?->toDateString();

            // The whole-shop row has nothing to say about one category.
            if ($category === null) {
                $rows[] = $this->row($date, 'All products', $run->metrics ?? [], $run->baseline_metrics['seasonal_naive'] ?? [], $run->baseline_metrics['moving_average'] ?? []);
            }

            foreach ($run->per_category_metrics ?? [] as $name => $metrics) {
                if ($category !== null && $name !== $category) {
                    continue;
                }

                $rows[] = $this->row($date, (string) $name, Arr::get($metrics, 'model', []), Arr::get($metrics, 'seasonal_naive', []), Arr::get($metrics, 'moving_average', []));
            }
        }

        $latest = $runs->first();
        $headline = $this->number($latest?->metrics['wape'] ?? null);
        $naive = $this->number($latest?->baseline_metrics['seasonal_naive']['wape'] ?? null);

        return new ReportResult(
            columns: [
                new ReportColumn('run_date', 'Run finished', ReportColumn::DATE),
                new ReportColumn('scope', 'Products'),
                new ReportColumn('mae', 'MAE (units)', ReportColumn::DECIMAL),
                new ReportColumn('rmse', 'RMSE (units)', ReportColumn::DECIMAL),
                new ReportColumn('mape', 'MAPE', ReportColumn::PERCENT),
                new ReportColumn('wape', 'WAPE', ReportColumn::PERCENT),
                new ReportColumn('naive_wape', 'WAPE, same period last year', ReportColumn::PERCENT),
                new ReportColumn('average_wape', 'WAPE, recent average', ReportColumn::PERCENT),
                new ReportColumn('observations', 'Forecasts checked', ReportColumn::INTEGER),
            ],
            rows: $rows,
            summary: [
                ['label' => 'Runs in this period', 'value' => $runs->count(), 'type' => ReportColumn::INTEGER],
                ['label' => 'Latest typical error (WAPE)', 'value' => $headline, 'type' => ReportColumn::PERCENT],
                ['label' => 'Same period last year', 'value' => $naive, 'type' => ReportColumn::PERCENT],
                ['label' => 'Forecast looks ahead', 'value' => $latest === null ? null : "{$latest->horizon} {$filters->granularity->noun()}s", 'type' => ReportColumn::TEXT],
            ],
            notes: array_values(array_filter([
                $runs->isEmpty() ? 'No '.mb_strtolower($filters->granularity->label()).' forecast finished in this period.' : null,
                'Each run is checked by replaying the recent past: the model sees only what was known at the time, forecasts, and is compared with what really sold.',
                'WAPE is total error as a percentage of units sold, and the most reliable single figure. MAPE is distorted by quiet periods. Lower is better for all of them.',
            ])),
        );
    }

    /**
     * @param  array<string, mixed>  $model
     * @param  array<string, mixed>  $naive
     * @param  array<string, mixed>  $average
     * @return array<string, mixed>
     */
    private function row(?string $date, string $scope, array $model, array $naive, array $average): array
    {
        return [
            'run_date' => $date,
            'scope' => $scope,
            'mae' => $this->number($model['mae'] ?? null),
            'rmse' => $this->number($model['rmse'] ?? null),
            'mape' => $this->number($model['mape'] ?? null),
            'wape' => $this->number($model['wape'] ?? null),
            'naive_wape' => $this->number($naive['wape'] ?? null),
            'average_wape' => $this->number($average['wape'] ?? null),
            'observations' => isset($model['n']) ? (int) $model['n'] : null,
        ];
    }

    private function number(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }
}
