<?php

namespace App\Services\Reporting;

use App\Enums\ForecastGranularity;
use App\Enums\Permission;
use App\Enums\RiskLevel;
use App\Models\Forecast;
use App\Models\ForecastRun;
use App\Models\Recommendation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Everything the dashboard shows. Each section is included only if the person
 * may see it (`null` otherwise), so a role never receives figures it has no
 * business with; the page shows just what it is given.
 *
 * Sales are over a rolling 30 days, compared with the 30 before, rather than
 * a calendar month, which would be nearly empty early in the month.
 */
class DashboardBuilder
{
    public const DAYS = 30;

    /** How many whole weeks the sales trend and the forecast chart look back. */
    public const TREND_WEEKS = 26;

    public function __construct(
        private readonly SalesAnalytics $sales,
        private readonly StockAnalytics $stock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $to = $today;
        $from = $today->subDays(self::DAYS - 1);

        $canSales = $user->can(Permission::ViewSales->value);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => self::DAYS],
            'sales' => $canSales ? $this->salesFigures($from, $to) : null,
            'sales_trend' => $canSales ? $this->salesTrend($today) : null,
            'categories' => $canSales ? $this->categories($from, $to) : null,
            'top_movers' => $canSales ? $this->sales->topMovers($from, $to) : null,
            'slow_movers' => $canSales && $user->can(Permission::ViewInventory->value) ? $this->sales->slowMovers($from, $to) : null,
            'stock' => $user->can(Permission::ViewInventory->value) ? $this->stock->valuation() : null,
            'risk' => $user->can(Permission::ViewRecommendations->value) ? $this->risk() : null,
            'alerts' => $user->can(Permission::ViewRecommendations->value) ? $this->alerts() : null,
            'forecast' => $user->can(Permission::ViewForecasts->value) ? $this->forecast($today) : null,
        ];
    }

    /**
     * @return array{revenue: float, units: int, previous_revenue: float, previous_units: int, revenue_change: float|null, units_change: float|null}
     */
    private function salesFigures(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $now = $this->sales->totals($from, $to);
        $before = $this->sales->totals($from->subDays(self::DAYS), $from->subDay());

        return [
            'revenue' => $now['revenue'],
            'units' => $now['units'],
            'previous_revenue' => $before['revenue'],
            'previous_units' => $before['units'],
            'revenue_change' => $this->change($now['revenue'], $before['revenue']),
            'units_change' => $this->change($now['units'], $before['units']),
        ];
    }

    /**
     * Revenue and units for each of the last whole weeks. This week is left out: it is still going,
     * and a half-finished week looks like a slump.
     *
     * @return list<array{period: string, revenue: float, units: int}>
     */
    private function salesTrend(CarbonImmutable $today): array
    {
        $last = ForecastGranularity::Week->lastCompletePeriod($today);

        return $this->sales->weekly($last->subWeeks(self::TREND_WEEKS - 1), $last->addDays(6));
    }

    /**
     * @return array<int, array{category_id: int, category: string, revenue: float, units: int, share: float}>
     */
    private function categories(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->sales->byCategory($from, $to);
        $total = array_sum(array_column($rows, 'revenue'));

        return array_map(fn (array $row) => [...$row, 'share' => $total > 0 ? round($row['revenue'] / $total * 100, 1) : 0.0], $rows);
    }

    /**
     * How many products are at each level of risk, for the cards and the link to the recommendations.
     *
     * @return array{critical: int, low: int, watch: int, needs_attention: int}
     */
    private function risk(): array
    {
        $counts = Recommendation::query()->open()->selectRaw('risk_level, count(*) as total')->groupBy('risk_level')->toBase()->pluck('total', 'risk_level');

        $critical = (int) ($counts[RiskLevel::Critical->value] ?? 0);
        $low = (int) ($counts[RiskLevel::Low->value] ?? 0);
        $watch = (int) ($counts[RiskLevel::Watch->value] ?? 0);

        return ['critical' => $critical, 'low' => $low, 'watch' => $watch, 'needs_attention' => $critical + $low];
    }

    /**
     * The most urgent products, for the alerts list.
     *
     * @return array<int, array{id: int, product: array{id: int, sku: string, name: string, unit: string}, risk: string, risk_label: string, on_hand: int, on_order: int, lead_time_demand: float, recommended_qty: int}>
     */
    private function alerts(): array
    {
        return Recommendation::query()
            ->open()
            ->whereIn('risk_level', [RiskLevel::Critical->value, RiskLevel::Low->value])
            ->with('product:id,sku,name,unit')
            ->mostUrgentFirst()
            ->limit(5)
            ->get()
            ->map(fn (Recommendation $recommendation) => [
                'id' => $recommendation->id,
                'product' => [
                    'id' => $recommendation->product->id,
                    'sku' => $recommendation->product->sku,
                    'name' => $recommendation->product->name,
                    'unit' => $recommendation->product->unit,
                ],
                'risk' => $recommendation->risk_level->value,
                'risk_label' => $recommendation->risk_level->label(),
                'on_hand' => $recommendation->on_hand,
                'on_order' => $recommendation->on_order,
                'lead_time_demand' => (float) $recommendation->lead_time_demand,
                'recommended_qty' => $recommendation->recommended_qty,
            ])
            ->values()
            ->all();
    }

    /**
     * How accurate the forecast has been, and the whole shop's sales so far with what is expected next.
     *
     * The range is each product's range added up, which is wider than the range for the total (products
     * do not all miss in the same direction at once), so it is a cautious one.
     *
     * @return array{has_run: bool, granularity: string, finished_at: string|null, age_days: int|null, stale: bool, accuracy: array{model: float|null, seasonal_naive: float|null, moving_average: float|null}|null, history: list<array{period: string, qty: int}>, forecast: array<int, array{period: string, yhat: float, lower: float, upper: float}>}
     */
    private function forecast(CarbonImmutable $today): array
    {
        $granularity = ForecastGranularity::Week;
        $run = ForecastRun::latestCompleted($granularity);

        if ($run === null) {
            return ['has_run' => false, 'granularity' => $granularity->value, 'finished_at' => null, 'age_days' => null, 'stale' => false, 'accuracy' => null, 'history' => [], 'forecast' => []];
        }

        $age = $run->finished_at === null ? null : (int) round($run->finished_at->startOfDay()->diffInDays($today));
        $last = $run->as_of === null ? null : CarbonImmutable::instance($run->as_of);

        return [
            'has_run' => true,
            'granularity' => $granularity->value,
            'finished_at' => $run->finished_at?->toIso8601String(),
            'age_days' => $age,
            'stale' => $age !== null && $age > (int) config('replenishment.stale_forecast_days'),
            'accuracy' => $run->metrics === null ? null : [
                'model' => $run->metrics['wape'] ?? null,
                'seasonal_naive' => $run->baseline_metrics['seasonal_naive']['wape'] ?? null,
                'moving_average' => $run->baseline_metrics['moving_average']['wape'] ?? null,
            ],
            'history' => $last === null ? [] : $this->sales->weeklyUnits($last->subWeeks(self::TREND_WEEKS - 1), $last->addDays(6)),
            'forecast' => $this->forecastTotals($run),
        ];
    }

    /**
     * What the run expects for all products together, week by week.
     *
     * @return array<int, array{period: string, yhat: float, lower: float, upper: float}>
     */
    private function forecastTotals(ForecastRun $run): array
    {
        return Forecast::query()
            ->where('forecast_run_id', $run->id)
            ->groupBy('period_start')
            ->orderBy('period_start')
            ->select('period_start', DB::raw('sum(yhat) as yhat'), DB::raw('sum(yhat_lower) as lower'), DB::raw('sum(yhat_upper) as upper'))
            ->toBase()
            ->get()
            ->map(fn ($row) => [
                'period' => substr((string) $row->period_start, 0, 10),
                'yhat' => round((float) $row->yhat, 1),
                'lower' => round((float) $row->lower, 1),
                'upper' => round((float) $row->upper, 1),
            ])
            ->values()
            ->all();
    }

    /**
     * The % change from `$before` to `$now`; null when there was nothing before to compare with.
     */
    private function change(float|int $now, float|int $before): ?float
    {
        return $before > 0 ? round(($now - $before) / $before * 100, 1) : null;
    }
}
