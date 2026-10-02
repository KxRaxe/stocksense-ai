<?php

use App\Enums\SaleSource;
use App\Models\ImportBatch;
use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\StockService;
use App\Services\Sales\ParsedSalesRow;
use App\Services\Sales\SalesService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->sales = app(SalesService::class);
    $this->product = Product::factory()->create(['unit_price' => 85]);
    app(StockService::class)->openingStock($this->product, 50);

    $this->batch = ImportBatch::create(['filename' => 'a.csv', 'status' => 'processing', 'settings' => []]);
    $this->user = User::factory()->create();
});

/** One parsed file row per quantity given, all for the same product and day. */
function parsedRows(Product $product, array $quantities, string $date = '2026-03-05'): array
{
    return array_map(
        fn (int $quantity, int $position) => new ParsedSalesRow($position + 2, $product, CarbonImmutable::parse($date), $quantity, '85.00'),
        $quantities,
        array_keys($quantities),
    );
}

/** Stock on hand as recorded in inventory_levels. */
function shelfStock(Product $product): int
{
    return (int) InventoryLevel::where('product_id', $product->id)->value('on_hand');
}

it('saves the sales and takes the stock off', function () {
    $this->sales->recordMany(parsedRows($this->product, [12, 8, 5]), $this->batch, $this->user, true);

    $sales = Sale::orderBy('id')->get();

    expect($sales)->toHaveCount(3)
        ->and($sales->pluck('quantity')->all())->toBe([12, 8, 5])
        ->and($sales->pluck('total')->all())->toBe(['1020.00', '680.00', '425.00'])
        ->and($sales->pluck('source')->unique()->all())->toBe([SaleSource::Import])
        ->and($sales->pluck('import_batch_id')->unique()->all())->toBe([$this->batch->id])
        ->and($sales->pluck('user_id')->unique()->all())->toBe([$this->user->id])
        ->and($sales->first()->sold_on->toDateString())->toBe('2026-03-05')
        ->and(shelfStock($this->product))->toBe(25);
});

it('ties the stock movements to the import', function () {
    $this->sales->recordMany(parsedRows($this->product, [12, 8]), $this->batch, $this->user, true);

    $movements = StockMovement::where('type', 'sale')->get();

    expect($movements)->toHaveCount(2)
        ->and($movements->pluck('quantity')->all())->toBe([-12, -8])
        ->and($movements->pluck('reference_type')->unique()->all())->toBe([$this->batch->getMorphClass()])
        ->and($movements->pluck('reference_id')->unique()->all())->toBe([$this->batch->id])
        ->and($movements->first()->occurred_at->toDateString())->toBe('2026-03-05');
});

it('can leave stock alone for historical data', function () {
    $this->sales->recordMany(parsedRows($this->product, [12, 8]), $this->batch, $this->user, false);

    expect(Sale::count())->toBe(2)
        ->and(StockMovement::where('type', 'sale')->count())->toBe(0)
        ->and(shelfStock($this->product))->toBe(50);
});

it('does nothing for an empty list', function () {
    $this->sales->recordMany([], $this->batch, $this->user, true);

    expect(Sale::count())->toBe(0);
});

it('keeps the ledger adding up to the stock level', function () {
    $this->sales->recordMany(parsedRows($this->product, [12, 8, 5]), $this->batch, $this->user, true);

    expect((int) StockMovement::where('product_id', $this->product->id)->sum('quantity'))->toBe(shelfStock($this->product));
});
