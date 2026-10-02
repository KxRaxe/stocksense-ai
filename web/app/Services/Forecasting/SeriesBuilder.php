<?php

namespace App\Services\Forecasting;

use App\Enums\ForecastGranularity;
use App\Models\Location;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Turns the sales table into the series the ML service forecasts from.
 *
 * Sales are summed per product into weeks (Monday to Sunday) or calendar
 * months. Each product's series starts at its first sale and runs, with the
 * quiet periods filled with zero, to the last period that was complete: the
 * week or month still in progress is left out, since a half-finished period
 * would look like a collapse in demand. Only active products are included, and
 * only sales at the given location, so forecasting per branch later is a
 * matter of calling this once per location.
 *
 * Nothing personal is in a series: a product, a category name, and units and
 * average price per period.
 *
 * @phpstan-import-type SeriesRow from SeriesSet
 */
class SeriesBuilder
{
    public function build(ForecastGranularity $granularity, Location $location, ?CarbonInterface $today = null): SeriesSet
    {
        $last = $granularity->lastCompletePeriod($today ?? CarbonImmutable::today());
        $cutoff = $granularity->endOf($last);

        // Postgres truncates weeks to Monday, matching the ML service's weeks.
        $bucket = "date_trunc('{$granularity->value}', sales.sold_on)::date";

        $rows = DB::table('sales')
            ->join('products', 'products.id', '=', 'sales.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->where('sales.location_id', $location->id)
            ->where('products.is_active', true)
            ->where('sales.sold_on', '<=', $cutoff->toDateString())
            ->groupBy('sales.product_id', 'categories.name')
            ->groupByRaw($bucket)
            ->orderBy('sales.product_id')
            ->orderByRaw($bucket)
            ->selectRaw("sales.product_id, categories.name as category, {$bucket} as period, sum(sales.quantity) as qty, sum(sales.total) as revenue")
            ->cursor();

        /** @var list<SeriesRow> $series */
        $series = [];
        $current = null;
        /** @var array<string, array{qty: int, revenue: float}> $sold */
        $sold = [];

        foreach ($rows as $row) {
            if ($current !== null && $current->product_id !== $row->product_id) {
                $series[] = $this->series($granularity, $location, $current, $sold, $last);
                $sold = [];
            }

            $current = $row;
            $sold[(string) $row->period] = ['qty' => (int) $row->qty, 'revenue' => (float) $row->revenue];
        }

        if ($current !== null) {
            $series[] = $this->series($granularity, $location, $current, $sold, $last);
        }

        return new SeriesSet($granularity, $last, $series);
    }

    /**
     * A product's units per period for the last few periods up to `$last`, for
     * showing beside its forecast. Quiet periods are zero; periods before the
     * product's first sale are left out, as it did not exist yet.
     *
     * @return list<array{period: string, qty: int}>
     */
    public function history(Product $product, Location $location, ForecastGranularity $granularity, CarbonImmutable $last, int $periods): array
    {
        $first = DB::table('sales')
            ->where('location_id', $location->id)
            ->where('product_id', $product->id)
            ->min('sold_on');

        if ($first === null) {
            return [];
        }

        $earliest = $granularity === ForecastGranularity::Week ? $last->subWeeks($periods - 1) : $last->subMonthsNoOverflow($periods - 1);
        $start = max($granularity->startOf(CarbonImmutable::parse((string) $first)), $earliest);
        $bucket = "date_trunc('{$granularity->value}', sold_on)::date";

        /** @var array<string, int> $sold */
        $sold = DB::table('sales')
            ->where('location_id', $location->id)
            ->where('product_id', $product->id)
            ->whereBetween('sold_on', [$start->toDateString(), $granularity->endOf($last)->toDateString()])
            ->groupByRaw($bucket)
            ->selectRaw("{$bucket} as period, sum(quantity) as qty")
            ->pluck('qty', 'period')
            ->map(fn ($qty) => (int) $qty)
            ->all();

        $rows = [];

        for ($period = $start; $period <= $last; $period = $granularity->next($period)) {
            $rows[] = ['period' => $period->toDateString(), 'qty' => $sold[$period->toDateString()] ?? 0];
        }

        return $rows;
    }

    /**
     * One product's series: from its first sale to the last complete period, zero-filled.
     *
     * @param  array<string, array{qty: int, revenue: float}>  $sold  Units and revenue by period start (Y-m-d)
     * @return SeriesRow
     */
    private function series(
        ForecastGranularity $granularity,
        Location $location,
        stdClass $product,
        array $sold,
        CarbonImmutable $last,
    ): array {
        $periods = [];

        for ($start = CarbonImmutable::parse((string) array_key_first($sold)); $start <= $last; $start = $granularity->next($start)) {
            $day = $start->toDateString();
            $qty = $sold[$day]['qty'] ?? 0;

            $periods[] = [
                'start' => $day,
                'qty' => $qty,
                'avg_price' => $qty > 0 ? round($sold[$day]['revenue'] / $qty, 2) : null,
            ];
        }

        return [
            'series_key' => "{$product->product_id}:{$location->id}",
            'product_id' => (int) $product->product_id,
            'category' => (string) $product->category,
            'periods' => $periods,
        ];
    }
}
