<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\SaleSource;
use App\Http\Requests\Sales\StoreSalesRequest;
use App\Models\Product;
use App\Models\Sale;
use App\Services\Inventory\LocationContext;
use App\Services\Sales\SalesService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Sales history and entering sales by hand. Viewing needs `sales.view`;
 * entering and deleting need `sales.enter` (see routes/web.php).
 */
class SaleController extends Controller
{
    public function __construct(
        private readonly SalesService $sales,
        private readonly LocationContext $locations,
    ) {}

    public function index(Request $request): Response
    {
        $filters = [
            'search' => (string) $request->query('search', ''),
            'from' => $this->date($request->query('from')),
            'to' => $this->date($request->query('to')),
            'source' => in_array($request->query('source'), ['manual', 'import'], true) ? $request->query('source') : '',
            'import' => $request->query('import') !== null ? (int) $request->query('import') : null,
        ];

        $query = Sale::query()
            ->where('sales.location_id', $this->locations->id())
            ->when($filters['search'] !== '', fn ($q) => $q->whereHas('product', fn ($product) => $product->search($filters['search'])))
            ->when($filters['from'], fn ($q, string $from) => $q->where('sold_on', '>=', $from))
            ->when($filters['to'], fn ($q, string $to) => $q->where('sold_on', '<=', $to))
            ->when($filters['source'] !== '', fn ($q) => $q->where('source', $filters['source']))
            ->when($filters['import'], fn ($q, int $import) => $q->where('import_batch_id', $import));

        $totals = (clone $query)->toBase()
            ->selectRaw('count(*) as lines, coalesce(sum(quantity), 0) as units, coalesce(sum(total), 0) as revenue')
            ->first();

        $sales = $query
            ->with(['product:id,sku,name,unit', 'user:id,name'])
            ->orderByDesc('sold_on')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Sale $sale) => [
                'id' => $sale->id,
                'sold_on' => $sale->sold_on->toDateString(),
                'product' => ['id' => $sale->product->id, 'sku' => $sale->product->sku, 'name' => $sale->product->name, 'unit' => $sale->product->unit],
                'quantity' => $sale->quantity,
                'unit_price' => (float) $sale->unit_price,
                'total' => (float) $sale->total,
                'source' => $sale->source->value,
                'source_label' => $sale->source->label(),
                'import_batch_id' => $sale->import_batch_id,
                'user' => $sale->user?->name,
            ]);

        $user = $request->user();

        return Inertia::render('sales/index', [
            'sales' => $sales,
            'filters' => $filters,
            'totals' => [
                'lines' => (int) $totals->lines,
                'units' => (int) $totals->units,
                'revenue' => (float) $totals->revenue,
            ],
            'can' => [
                'enter' => $user->hasPermissionTo(Permission::EnterSales),
                'import' => $user->hasPermissionTo(Permission::ImportSales),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('sales/create', [
            'products' => Product::query()
                ->withStockAt($this->locations->id())
                ->where('products.is_active', true)
                ->orderBy('products.name')
                ->get()
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'unit' => $product->unit,
                    'unit_price' => (float) $product->unit_price,
                    'on_hand' => $product->stock_on_hand ?? 0,
                ])
                ->values()
                ->all(),
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    public function store(StoreSalesRequest $request): RedirectResponse
    {
        $items = $request->validated('items');
        $soldOn = CarbonImmutable::parse($request->validated('sold_on'));

        $products = Product::query()
            ->whereIn('id', array_column($items, 'product_id'))
            ->get()
            ->keyBy('id');

        // All or nothing: a day's sheet is saved in full or not at all.
        DB::transaction(function () use ($items, $soldOn, $products, $request) {
            foreach ($items as $item) {
                $this->sales->record(
                    $products[$item['product_id']],
                    $soldOn,
                    (int) $item['quantity'],
                    number_format((float) $item['unit_price'], 2, '.', ''),
                    SaleSource::Manual,
                    $request->user(),
                );
            }
        });

        $count = count($items);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $count === 1 ? 'Sale recorded.' : "{$count} sales recorded.",
        ]);

        return to_route('sales.index');
    }

    public function destroy(Request $request, Sale $sale): RedirectResponse
    {
        try {
            $this->sales->delete($sale, $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['sale' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sale deleted and its stock put back.']);

        return back();
    }

    /**
     * A date from the query string, or null if it is missing or not a date.
     */
    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) ? $value : null;
    }
}
