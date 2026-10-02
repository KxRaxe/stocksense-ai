<?php

namespace App\Services\Reporting\Reports;

use App\Enums\Permission;
use App\Services\Inventory\LocationContext;
use App\Services\Reporting\SalesAnalytics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What sold in a period, product by product, with each one's share of the revenue.
 */
class SalesReport implements Report
{
    public function __construct(
        private readonly SalesAnalytics $sales,
        private readonly LocationContext $locations,
    ) {}

    public function key(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'Sales';
    }

    public function description(): string
    {
        return 'Units and revenue for each product over a period, with its share of the total.';
    }

    public function permission(): Permission
    {
        return Permission::ViewReports;
    }

    public function filters(): array
    {
        return ['date', 'category'];
    }

    public function defaultRange(CarbonImmutable $today): array
    {
        return [$today->subDays(29), $today];
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $products = $this->sales->byProduct($filters->from, $filters->to, $filters->categoryId);
        $totals = $this->sales->totals($filters->from, $filters->to, $filters->categoryId);

        $rows = array_map(fn (array $product) => [
            'sku' => $product['sku'],
            'name' => $product['name'],
            'category' => $product['category'],
            'unit' => $product['unit'],
            'units' => $product['units'],
            'revenue' => $product['revenue'],
            'average_price' => $product['units'] > 0 ? round($product['revenue'] / $product['units'], 2) : 0.0,
            'share' => $totals['revenue'] > 0 ? round($product['revenue'] / $totals['revenue'] * 100, 1) : 0.0,
        ], $products);

        $quiet = $this->activeProductsWithoutSales($filters);

        return new ReportResult(
            columns: [
                new ReportColumn('sku', 'SKU'),
                new ReportColumn('name', 'Product'),
                new ReportColumn('category', 'Category'),
                new ReportColumn('unit', 'Unit'),
                new ReportColumn('units', 'Units sold', ReportColumn::INTEGER),
                new ReportColumn('revenue', 'Revenue', ReportColumn::MONEY),
                new ReportColumn('average_price', 'Average price', ReportColumn::MONEY),
                new ReportColumn('share', 'Share of revenue', ReportColumn::PERCENT),
            ],
            rows: array_values($rows),
            summary: [
                ['label' => 'Revenue', 'value' => $totals['revenue'], 'type' => ReportColumn::MONEY],
                ['label' => 'Units sold', 'value' => $totals['units'], 'type' => ReportColumn::INTEGER],
                ['label' => 'Products sold', 'value' => count($rows), 'type' => ReportColumn::INTEGER],
                ['label' => 'Best seller', 'value' => $rows[0]['name'] ?? '-', 'type' => ReportColumn::TEXT],
            ],
            totals: $rows === [] ? [] : ['name' => 'Total', 'units' => $totals['units'], 'revenue' => $totals['revenue'], 'share' => 100.0],
            notes: array_filter([
                'The units total counts every product in its own unit (a piece, a kilogram) as one, so it is a rough size of the business rather than a weight.',
                $quiet > 0 ? "{$quiet} active ".($quiet === 1 ? 'product' : 'products').' had no sales in this period and are not listed.' : null,
            ]),
        );
    }

    /**
     * How many active products (in the chosen category) sold nothing in the period.
     */
    private function activeProductsWithoutSales(ReportFilters $filters): int
    {
        return DB::table('products')
            ->where('products.is_active', true)
            ->when($filters->categoryId, fn ($query, int $category) => $query->where('products.category_id', $category))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('sales')
                ->whereColumn('sales.product_id', 'products.id')
                ->where('sales.location_id', $this->locations->id())
                ->whereBetween('sales.sold_on', [$filters->from->toDateString(), $filters->to->toDateString()]))
            ->count();
    }
}
