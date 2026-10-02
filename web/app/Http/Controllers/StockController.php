<?php

namespace App\Http\Controllers;

use App\Http\Requests\Catalog\AdjustStockRequest;
use App\Http\Requests\Catalog\RestockRequest;
use App\Models\Product;
use App\Services\Inventory\StockService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Recording goods received and stock-take corrections. Needs
 * `inventory.adjust`, which every role has (see routes/web.php).
 */
class StockController extends Controller
{
    public function __construct(private readonly StockService $stock) {}

    public function restock(RestockRequest $request, Product $product): RedirectResponse
    {
        $receivedOn = $request->validated('received_on');

        $this->stock->restock(
            $product,
            (int) $request->validated('quantity'),
            // Today (or no date) means "now"; an earlier date is recorded for that day.
            $receivedOn !== null && ! CarbonImmutable::parse($receivedOn)->isToday()
                ? CarbonImmutable::parse($receivedOn)->startOfDay()
                : null,
            $request->validated('note'),
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Restock recorded.']);

        return back();
    }

    public function adjust(AdjustStockRequest $request, Product $product): RedirectResponse
    {
        $movement = $this->stock->adjustTo(
            $product,
            (int) $request->validated('counted'),
            $request->validated('note'),
            $request->user(),
        );

        Inertia::flash('toast', $movement === null
            ? ['type' => 'info', 'message' => 'The count matches the recorded stock, so nothing changed.']
            : ['type' => 'success', 'message' => 'Stock adjusted.']);

        return back();
    }
}
