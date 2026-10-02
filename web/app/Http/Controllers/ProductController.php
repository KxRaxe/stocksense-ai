<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\StockStatus;
use App\Http\Requests\Catalog\ProductRequest;
use App\Models\Category;
use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Inventory\LocationContext;
use App\Services\Inventory\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The product catalog. Viewing needs `catalog.view`; creating, editing and
 * archiving need `catalog.manage` (see routes/web.php).
 */
class ProductController extends Controller
{
    public function __construct(
        private readonly StockService $stock,
        private readonly LocationContext $locations,
    ) {}

    public function index(Request $request): Response
    {
        $filters = [
            'search' => (string) $request->query('search', ''),
            'category' => $request->query('category') !== null ? (int) $request->query('category') : null,
            'status' => in_array($request->query('status'), ['active', 'archived', 'all'], true)
                ? $request->query('status')
                : 'active',
        ];

        $products = Product::query()
            ->with('category:id,name')
            ->search($filters['search'])
            ->when($filters['category'], fn ($query, int $category) => $query->where('category_id', $category))
            ->when($filters['status'] !== 'all', fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Product $product) => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'category' => $product->category->name,
                'unit' => $product->unit,
                'unit_cost' => (float) $product->unit_cost,
                'unit_price' => (float) $product->unit_price,
                'is_active' => $product->is_active,
            ]);

        return Inertia::render('products/index', [
            'products' => $products,
            'filters' => $filters,
            'categories' => $this->categoryOptions(),
            'can' => [
                'manage' => $request->user()->hasPermissionTo(Permission::ManageCatalog),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('products/create', [
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $product = DB::transaction(function () use ($request) {
            $product = Product::create(Arr::except($request->validated(), ['opening_stock']));

            $this->stock->openingStock($product, (int) $request->validated('opening_stock', 0), $request->user());

            return $product;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Product created.']);

        return to_route('products.show', $product);
    }

    public function show(Request $request, Product $product): Response
    {
        // Read-only: a product with no stock record yet simply has none on hand.
        $level = InventoryLevel::query()
            ->where('product_id', $product->id)
            ->where('location_id', $this->locations->id())
            ->first();
        $onHand = $level->on_hand ?? 0;
        $user = $request->user();

        return Inertia::render('products/show', [
            'product' => $this->details($product),
            'stock' => [
                'on_hand' => $onHand,
                'on_order' => $level->on_order ?? 0,
                'status' => StockStatus::for($onHand, $product->reorder_point_override)->value,
            ],
            'movements' => StockMovement::query()
                ->with('user:id,name')
                ->where('product_id', $product->id)
                ->where('location_id', $this->locations->id())
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(25)
                ->get()
                ->map(fn (StockMovement $movement) => [
                    'id' => $movement->id,
                    'type' => $movement->type->value,
                    'type_label' => $movement->type->label(),
                    'quantity' => $movement->quantity,
                    'occurred_at' => $movement->occurred_at->toIso8601String(),
                    'note' => $movement->note,
                    'user' => $movement->user?->name,
                ])
                ->all(),
            'can' => [
                'manage' => $user->hasPermissionTo(Permission::ManageCatalog),
                'adjust' => $user->hasPermissionTo(Permission::AdjustStock),
            ],
        ]);
    }

    public function edit(Product $product): Response
    {
        return Inertia::render('products/edit', [
            'product' => $this->details($product),
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $product->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Product updated.']);

        return to_route('products.show', $product);
    }

    public function archive(Product $product): RedirectResponse
    {
        $product->update(['is_active' => false]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Product archived.']);

        return back();
    }

    public function restore(Product $product): RedirectResponse
    {
        $product->update(['is_active' => true]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Product restored.']);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function details(Product $product): array
    {
        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'category_id' => $product->category_id,
            'category' => $product->category->name,
            'unit' => $product->unit,
            'unit_cost' => (float) $product->unit_cost,
            'unit_price' => (float) $product->unit_price,
            'lead_time_days' => $product->lead_time_days,
            'moq' => $product->moq,
            'pack_size' => $product->pack_size,
            'reorder_point_override' => $product->reorder_point_override,
            'safety_stock_override' => $product->safety_stock_override,
            'is_active' => $product->is_active,
        ];
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function categoryOptions(): array
    {
        return Category::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->name])
            ->values()
            ->all();
    }
}
