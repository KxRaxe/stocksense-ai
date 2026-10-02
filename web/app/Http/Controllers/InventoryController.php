<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\StockStatus;
use App\Models\Category;
use App\Models\Product;
use App\Services\Inventory\LocationContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock overview: what is on hand for every active product, with low-stock
 * flags. Needs `inventory.view`.
 */
class InventoryController extends Controller
{
    public function __construct(private readonly LocationContext $locations) {}

    public function index(Request $request): Response
    {
        $locationId = $this->locations->id();

        $stock = StockStatus::tryFrom((string) $request->query('stock'));

        $filters = [
            'search' => (string) $request->query('search', ''),
            'category' => $request->query('category') !== null ? (int) $request->query('category') : null,
            'stock' => $stock->value ?? 'all',
        ];

        $products = Product::query()
            ->withStockAt($locationId)
            ->with('category:id,name')
            ->where('products.is_active', true)
            ->search($filters['search'])
            ->when($filters['category'], fn ($query, int $category) => $query->where('products.category_id', $category))
            ->when($stock, fn ($query, StockStatus $status) => $query->inStockStatus($status, $locationId))
            ->orderBy('products.name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Product $product) => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'category' => $product->category->name,
                'unit' => $product->unit,
                'on_hand' => $product->stock_on_hand ?? 0,
                'on_order' => $product->stock_on_order ?? 0,
                'reorder_point' => $product->reorder_point_override,
                'lead_time_days' => $product->lead_time_days,
                'status' => StockStatus::for($product->stock_on_hand ?? 0, $product->reorder_point_override)->value,
            ]);

        return Inertia::render('inventory/index', [
            'products' => $products,
            'filters' => $filters,
            'categories' => Category::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->name])
                ->all(),
            'can' => [
                'adjust' => $request->user()->hasPermissionTo(Permission::AdjustStock),
            ],
        ]);
    }
}
