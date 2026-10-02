<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Services\Inventory\StockService;
use Illuminate\Database\Seeder;

/**
 * A small starter catalog for local development: the five product categories
 * from the proposal, a few products each, with some stock. Not run in
 * production. (The larger synthetic dataset for forecasting arrives with the
 * sales import in Phase 3.)
 */
class DemoCatalogSeeder extends Seeder
{
    public function run(StockService $stock): void
    {
        // category => service level %, then products: [sku, name, unit, cost, price, lead days, reorder point, opening stock]
        $catalog = [
            'Food and beverages' => [95, [
                ['FB-001', 'Instant coffee 3-in-1 (30 sachets)', 'box', 95.00, 125.00, 5, 40, 120],
                ['FB-002', 'Bottled water 500 ml', 'pc', 8.00, 14.00, 3, 200, 480],
                ['FB-003', 'Canned sardines 155 g', 'can', 17.50, 24.00, 7, 100, 60],
            ]],
            'Personal care' => [95, [
                ['PC-001', 'Shampoo sachet (12 pcs)', 'pack', 36.00, 52.00, 7, 50, 90],
                ['PC-002', 'Bath soap 90 g', 'pc', 22.00, 32.00, 7, 60, 0],
                ['PC-003', 'Toothpaste 100 ml', 'pc', 48.00, 68.00, 10, 30, 25],
            ]],
            'Household and cleaning' => [90, [
                ['HC-001', 'Dishwashing liquid 500 ml', 'bottle', 38.00, 55.00, 7, 35, 70],
                ['HC-002', 'Laundry powder 1 kg', 'pack', 85.00, 115.00, 10, 25, 18],
                ['HC-003', 'Trash bags (medium, 10 pcs)', 'pack', 28.00, 42.00, 14, 20, 40],
            ]],
            'School and office supplies' => [90, [
                ['SO-001', 'Notebook 80 leaves', 'pc', 18.00, 30.00, 14, 150, 300],
                ['SO-002', 'Ballpoint pen (box of 12)', 'box', 60.00, 90.00, 14, 40, 35],
                ['SO-003', 'Bond paper A4 (ream)', 'ream', 215.00, 260.00, 10, 30, 80],
            ]],
            'Hardware and construction' => [85, [
                ['HW-001', 'Common wire nails 2 in (1 kg)', 'kg', 62.00, 85.00, 14, 20, 45],
                ['HW-002', 'Cement 40 kg', 'bag', 235.00, 275.00, 7, 50, 200],
                ['HW-003', 'PVC pipe 1/2 in x 3 m', 'pc', 70.00, 98.00, 14, 15, 10],
            ]],
        ];

        foreach ($catalog as $categoryName => [$serviceLevel, $products]) {
            $category = Category::firstOrCreate(
                ['name' => $categoryName],
                ['service_level' => $serviceLevel],
            );

            foreach ($products as [$sku, $name, $unit, $cost, $price, $leadDays, $reorderPoint, $openingStock]) {
                $product = Product::firstOrCreate(
                    ['sku' => $sku],
                    [
                        'name' => $name,
                        'category_id' => $category->id,
                        'unit' => $unit,
                        'unit_cost' => $cost,
                        'unit_price' => $price,
                        'lead_time_days' => $leadDays,
                        'reorder_point_override' => $reorderPoint,
                    ],
                );

                // Only on first creation, so re-running the seeder never doubles the stock.
                if ($product->wasRecentlyCreated) {
                    $stock->openingStock($product, $openingStock);
                }
            }
        }
    }
}
