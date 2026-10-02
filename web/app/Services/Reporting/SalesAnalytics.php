<?php

namespace App\Services\Reporting;

use App\Services\Inventory\LocationContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Questions about what has sold, for the dashboard and the reports. Always
 * for the current location, and over whole days: `$from` and `$to` are both
 * included.
 */
class SalesAnalytics
{
    public function __construct(private readonly LocationContext $locations) {}

    /**
     * @return array{revenue: float, units: int}
     */
    public function totals(CarbonInterface $from, CarbonInterface $to, ?int $categoryId = null): array
    {
        $row = $this->sales($from, $to, $categoryId)
            ->selectRaw('coalesce(sum(sales.total), 0) as revenue, coalesce(sum(sales.quantity), 0) as units')
            ->first();

        return ['revenue' => round((float) $row->revenue, 2), 'units' => (int) $row->units];
    }

    /**
     * Revenue and units for each Monday-to-Sunday week from the week containing `$from` to the one containing `$to`. Quiet weeks are zero.
     *
     * @return list<array{period: string, revenue: float, units: int}>
     */
    public function weekly(CarbonInterface $from, CarbonInterface $to, ?int $categoryId = null): array
    {
        $bucket = "date_trunc('week', sales.sold_on)::date";

        /** @var array<string, object{revenue: string, units: string}> $found */
        $found = $this->sales($from, $to, $categoryId)
            ->selectRaw("{$bucket} as period, sum(sales.total) as revenue, sum(sales.quantity) as units")
            ->groupByRaw($bucket)
            ->get()
            ->keyBy('period')
            ->all();

        $rows = [];
        $last = CarbonImmutable::instance($to)->startOfWeek(CarbonInterface::MONDAY);

        for ($week = CarbonImmutable::instance($from)->startOfWeek(CarbonInterface::MONDAY); $week <= $last; $week = $week->addWeek()) {
            $key = $week->toDateString();

            $rows[] = [
                'period' => $key,
                'revenue' => isset($found[$key]) ? round((float) $found[$key]->revenue, 2) : 0.0,
                'units' => isset($found[$key]) ? (int) $found[$key]->units : 0,
            ];
        }

        return $rows;
    }

    /**
     * Units for each week, whatever sold, from `$from` to `$to`: the "actual" line beside a forecast.
     *
     * @return list<array{period: string, qty: int}>
     */
    public function weeklyUnits(CarbonInterface $from, CarbonInterface $to): array
    {
        return array_map(
            fn (array $week) => ['period' => $week['period'], 'qty' => $week['units']],
            $this->weekly($from, $to),
        );
    }

    /**
     * What each category earned, biggest first. Categories with no sales are left out.
     *
     * @return array<int, array{category_id: int, category: string, revenue: float, units: int}>
     */
    public function byCategory(CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->sales($from, $to)
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->groupBy('categories.id', 'categories.name')
            ->selectRaw('categories.id as category_id, categories.name as category, sum(sales.total) as revenue, sum(sales.quantity) as units')
            ->orderByDesc('revenue')
            ->orderBy('categories.name')
            ->get()
            ->map(fn ($row) => [
                'category_id' => (int) $row->category_id,
                'category' => (string) $row->category,
                'revenue' => round((float) $row->revenue, 2),
                'units' => (int) $row->units,
            ])
            ->values()
            ->all();
    }

    /**
     * What each product sold, most revenue first. Products that did not sell are left out.
     *
     * @return array<int, array{product_id: int, sku: string, name: string, category: string, unit: string, units: int, revenue: float}>
     */
    public function byProduct(CarbonInterface $from, CarbonInterface $to, ?int $categoryId = null): array
    {
        return $this->sales($from, $to, $categoryId)
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->groupBy('products.id', 'products.sku', 'products.name', 'categories.name', 'products.unit')
            ->selectRaw('products.id as product_id, products.sku, products.name, categories.name as category, products.unit, sum(sales.quantity) as units, sum(sales.total) as revenue')
            ->orderByDesc('revenue')
            ->orderBy('products.name')
            ->get()
            ->map(fn ($row) => [
                'product_id' => (int) $row->product_id,
                'sku' => (string) $row->sku,
                'name' => (string) $row->name,
                'category' => (string) $row->category,
                'unit' => (string) $row->unit,
                'units' => (int) $row->units,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->values()
            ->all();
    }

    /**
     * The products that sold the most units.
     *
     * @return list<array{product_id: int, sku: string, name: string, category: string, unit: string, units: int, revenue: float}>
     */
    public function topMovers(CarbonInterface $from, CarbonInterface $to, int $limit = 5): array
    {
        $products = $this->byProduct($from, $to);

        usort($products, fn (array $a, array $b) => [$b['units'], $a['name']] <=> [$a['units'], $b['name']]);

        return array_slice($products, 0, $limit);
    }

    /**
     * Active products that have stock but are selling least: the money sitting on the shelf. Those
     * that sold nothing come first.
     *
     * @return array<int, array{product_id: int, sku: string, name: string, category: string, unit: string, units: int, on_hand: int, stock_value: float}>
     */
    public function slowMovers(CarbonInterface $from, CarbonInterface $to, int $limit = 5): array
    {
        $locationId = $this->locations->id();

        return DB::table('products')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->join('inventory_levels', fn ($join) => $join
                ->on('inventory_levels.product_id', '=', 'products.id')
                ->where('inventory_levels.location_id', '=', $locationId))
            ->leftJoin('sales', fn ($join) => $join
                ->on('sales.product_id', '=', 'products.id')
                ->where('sales.location_id', '=', $locationId)
                ->whereBetween('sales.sold_on', [$from->toDateString(), $to->toDateString()]))
            ->where('products.is_active', true)
            ->where('inventory_levels.on_hand', '>', 0)
            ->groupBy('products.id', 'products.sku', 'products.name', 'categories.name', 'products.unit', 'inventory_levels.on_hand', 'products.unit_cost')
            ->selectRaw('products.id as product_id, products.sku, products.name, categories.name as category, products.unit, coalesce(sum(sales.quantity), 0) as units, inventory_levels.on_hand, inventory_levels.on_hand * products.unit_cost as stock_value')
            ->orderByRaw('coalesce(sum(sales.quantity), 0) asc')
            ->orderByRaw('inventory_levels.on_hand * products.unit_cost desc')
            ->orderBy('products.name')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'product_id' => (int) $row->product_id,
                'sku' => (string) $row->sku,
                'name' => (string) $row->name,
                'category' => (string) $row->category,
                'unit' => (string) $row->unit,
                'units' => (int) $row->units,
                'on_hand' => (int) $row->on_hand,
                'stock_value' => round((float) $row->stock_value, 2),
            ])
            ->values()
            ->all();
    }

    /**
     * Sales at this location between two days, joined to their products.
     */
    private function sales(CarbonInterface $from, CarbonInterface $to, ?int $categoryId = null): Builder
    {
        return DB::table('sales')
            ->join('products', 'products.id', '=', 'sales.product_id')
            ->where('sales.location_id', $this->locations->id())
            ->whereBetween('sales.sold_on', [$from->toDateString(), $to->toDateString()])
            ->when($categoryId, fn (Builder $query, int $category) => $query->where('products.category_id', $category));
    }
}
