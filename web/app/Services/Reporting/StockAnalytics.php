<?php

namespace App\Services\Reporting;

use App\Services\Inventory\LocationContext;
use Illuminate\Support\Facades\DB;

/**
 * Questions about what is on the shelves, for the dashboard and the reports.
 * Active products at the current location.
 */
class StockAnalytics
{
    public function __construct(private readonly LocationContext $locations) {}

    /**
     * What the stock on hand is worth, and how many products are in and out of stock.
     *
     * @return array{cost_value: float, retail_value: float, products: int, in_stock: int, out_of_stock: int}
     */
    public function valuation(): array
    {
        $row = DB::table('products')
            ->leftJoin('inventory_levels', fn ($join) => $join
                ->on('inventory_levels.product_id', '=', 'products.id')
                ->where('inventory_levels.location_id', '=', $this->locations->id()))
            ->where('products.is_active', true)
            ->selectRaw('
                count(*) as products,
                count(*) filter (where coalesce(inventory_levels.on_hand, 0) > 0) as in_stock,
                coalesce(sum(greatest(coalesce(inventory_levels.on_hand, 0), 0) * products.unit_cost), 0) as cost_value,
                coalesce(sum(greatest(coalesce(inventory_levels.on_hand, 0), 0) * products.unit_price), 0) as retail_value
            ')
            ->first();

        return [
            'cost_value' => round((float) $row->cost_value, 2),
            'retail_value' => round((float) $row->retail_value, 2),
            'products' => (int) $row->products,
            'in_stock' => (int) $row->in_stock,
            'out_of_stock' => (int) $row->products - (int) $row->in_stock,
        ];
    }
}
