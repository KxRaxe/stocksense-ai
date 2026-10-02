<?php

namespace App\Services\Reporting\Reports;

use App\Enums\Permission;
use App\Enums\StockStatus;
use App\Models\Product;
use App\Models\Recommendation;
use App\Services\Inventory\LocationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Where stock stands right now, product by product: how much is on hand and
 * on order, what it is worth, whether it is low, and what the recommendations
 * say about it. Always as of now, so it has no date filter.
 */
class InventoryReport implements Report
{
    public function __construct(private readonly LocationContext $locations) {}

    public function key(): string
    {
        return 'inventory';
    }

    public function title(): string
    {
        return 'Inventory status';
    }

    public function description(): string
    {
        return 'Stock on hand and on order for every active product, what it is worth, and how urgent it is.';
    }

    public function permission(): Permission
    {
        return Permission::ViewInventoryReports;
    }

    public function filters(): array
    {
        return ['category'];
    }

    public function defaultRange(CarbonImmutable $today): array
    {
        return [$today, $today];
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $products = Product::query()
            ->withStockAt($this->locations->id())
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->addSelect('categories.name as category_name')
            ->where('products.is_active', true)
            ->when($filters->categoryId, fn ($query, int $category) => $query->where('products.category_id', $category))
            ->orderBy('categories.name')
            ->orderBy('products.name')
            ->get();

        /** @var Collection<int, Recommendation> $advice */
        $advice = Recommendation::query()->open()->whereIn('product_id', $products->pluck('id'))->get()->keyBy('product_id');

        $rows = [];
        $value = 0.0;
        $out = 0;
        $low = 0;
        $urgent = 0;

        foreach ($products as $product) {
            $onHand = (int) $product->stock_on_hand;
            $recommendation = $advice->get($product->id);
            $reorderPoint = $product->reorder_point_override ?? $recommendation?->reorder_point;
            $status = StockStatus::for($onHand, $product->reorder_point_override);
            $stockValue = round(max($onHand, 0) * (float) $product->unit_cost, 2);

            $value += $stockValue;
            $out += $status === StockStatus::OutOfStock ? 1 : 0;
            $low += $status === StockStatus::Low ? 1 : 0;
            $urgent += $recommendation !== null && in_array($recommendation->risk_level->value, ['critical', 'low'], true) ? 1 : 0;

            $rows[] = [
                'sku' => $product->sku,
                'name' => $product->name,
                'category' => $product->getAttribute('category_name'),
                'unit' => $product->unit,
                'on_hand' => $onHand,
                'on_order' => (int) $product->stock_on_order,
                'reorder_point' => $reorderPoint,
                'status' => $status->label(),
                'risk' => $recommendation?->risk_level->label(),
                'days_of_cover' => $recommendation?->days_of_cover === null ? null : round((float) $recommendation->days_of_cover, 1),
                'unit_cost' => round((float) $product->unit_cost, 2),
                'stock_value' => $stockValue,
            ];
        }

        return new ReportResult(
            columns: [
                new ReportColumn('sku', 'SKU'),
                new ReportColumn('name', 'Product'),
                new ReportColumn('category', 'Category'),
                new ReportColumn('unit', 'Unit'),
                new ReportColumn('on_hand', 'On hand', ReportColumn::INTEGER),
                new ReportColumn('on_order', 'On order', ReportColumn::INTEGER),
                new ReportColumn('reorder_point', 'Reorder point', ReportColumn::INTEGER),
                new ReportColumn('status', 'Stock'),
                new ReportColumn('risk', 'Advice'),
                new ReportColumn('days_of_cover', 'Days of cover', ReportColumn::DECIMAL),
                new ReportColumn('unit_cost', 'Unit cost', ReportColumn::MONEY),
                new ReportColumn('stock_value', 'Stock value', ReportColumn::MONEY),
            ],
            rows: $rows,
            summary: [
                ['label' => 'Stock value (at cost)', 'value' => round($value, 2), 'type' => ReportColumn::MONEY],
                ['label' => 'Products', 'value' => count($rows), 'type' => ReportColumn::INTEGER],
                ['label' => 'Out of stock', 'value' => $out, 'type' => ReportColumn::INTEGER],
                ['label' => 'Below reorder point', 'value' => $low, 'type' => ReportColumn::INTEGER],
                ['label' => 'Need ordering', 'value' => $urgent, 'type' => ReportColumn::INTEGER],
            ],
            totals: $rows === [] ? [] : ['name' => 'Total', 'stock_value' => round($value, 2)],
            notes: [
                'As of '.now()->format('j M Y, g:i a').'. Reorder point is the one set on the product, or else the one worked out for the latest recommendation.',
                '"Stock" compares what is on hand with the reorder point set on the product. "Advice" is the risk level from the recommendations, which also looks at expected sales.',
            ],
        );
    }
}
