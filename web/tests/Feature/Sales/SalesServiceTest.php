<?php

use App\Enums\SaleSource;
use App\Models\ImportBatch;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\StockService;
use App\Services\Sales\SalesService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->sales = app(SalesService::class);
    $this->product = Product::factory()->create(['unit_price' => 85]);
    app(StockService::class)->openingStock($this->product, 50);
});

function stockOf(Product $product): int
{
    return (int) InventoryLevel::where('product_id', $product->id)->value('on_hand');
}

describe('recording a sale', function () {
    it('saves the sale and takes the stock off the shelf', function () {
        $user = User::factory()->create();

        $sale = $this->sales->record($this->product, CarbonImmutable::today(), 12, '85.50', SaleSource::Manual, $user);

        expect($sale->quantity)->toBe(12)
            ->and($sale->unit_price)->toBe('85.50')
            ->and($sale->total)->toBe('1026.00')
            ->and($sale->source)->toBe(SaleSource::Manual)
            ->and($sale->user_id)->toBe($user->id)
            ->and($sale->location_id)->toBe(Location::defaultLocation()->id)
            ->and($sale->sold_on->toDateString())->toBe(CarbonImmutable::today()->toDateString())
            ->and(stockOf($this->product))->toBe(38);

        $movement = StockMovement::where('type', 'sale')->firstOrFail();
        expect($movement->quantity)->toBe(-12)
            ->and($movement->reference_id)->toBe($sale->id)
            ->and($movement->reference_type)->toBe($sale->getMorphClass())
            ->and($movement->user_id)->toBe($user->id);
    });

    it('works out the total without rounding errors', function (int $quantity, string $price, string $total) {
        expect($this->sales->record($this->product, CarbonImmutable::today(), $quantity, $price, SaleSource::Manual)->total)->toBe($total);
    })->with([
        [3, '0.10', '0.30'],
        [7, '19.99', '139.93'],
        [1000, '1250.50', '1250500.00'],
        [1, '0.00', '0.00'],
    ]);

    it('dates the stock movement to the day of the sale', function () {
        $this->sales->record($this->product, CarbonImmutable::parse('2026-03-05'), 4, '85.00', SaleSource::Import);

        $movement = StockMovement::where('type', 'sale')->firstOrFail();

        expect($movement->occurred_at->toDateString())->toBe('2026-03-05');
    });

    it('lets stock go negative when more is sold than was recorded', function () {
        $this->sales->record($this->product, CarbonImmutable::today(), 80, '85.00', SaleSource::Manual);

        expect(stockOf($this->product))->toBe(-30);
    });

    it('can leave stock alone for historical data', function () {
        $sale = $this->sales->record($this->product, CarbonImmutable::parse('2025-01-10'), 9, '85.00', SaleSource::Import, adjustStock: false);

        expect(Sale::count())->toBe(1)
            ->and($sale->quantity)->toBe(9)
            ->and(StockMovement::where('type', 'sale')->count())->toBe(0)
            ->and(stockOf($this->product))->toBe(50);
    });

    it('remembers which import it came from', function () {
        $batch = ImportBatch::create(['filename' => 'a.csv', 'status' => 'completed', 'settings' => []]);

        $sale = $this->sales->record($this->product, CarbonImmutable::today(), 1, '85.00', SaleSource::Import, batch: $batch);

        expect($sale->import_batch_id)->toBe($batch->id);
    });

    it('saves nothing if the stock change fails', function () {
        InventoryLevel::saving(fn () => throw new RuntimeException('disk full'));

        try {
            expect(fn () => $this->sales->record($this->product, CarbonImmutable::today(), 5, '85.00', SaleSource::Manual))
                ->toThrow(RuntimeException::class);
        } finally {
            InventoryLevel::flushEventListeners();
        }

        expect(Sale::count())->toBe(0)
            ->and(stockOf($this->product))->toBe(50);
    });
});

describe('deleting a sale', function () {
    it('removes it and puts the stock back, leaving a trail in the ledger', function () {
        $user = User::factory()->create();
        $sale = $this->sales->record($this->product, CarbonImmutable::today(), 12, '85.00', SaleSource::Manual);

        $this->sales->delete($sale, $user);

        expect(Sale::count())->toBe(0)
            ->and(stockOf($this->product))->toBe(50);

        $correction = StockMovement::where('type', 'adjustment')->firstOrFail();
        expect($correction->quantity)->toBe(12)
            ->and($correction->note)->toBe("Sale #{$sale->id} removed")
            ->and($correction->user_id)->toBe($user->id);

        // The original movement is still there: the ledger is never edited.
        expect(StockMovement::where('type', 'sale')->count())->toBe(1);
    });

    it('keeps the ledger adding up to the stock level', function () {
        $sale = $this->sales->record($this->product, CarbonImmutable::today(), 12, '85.00', SaleSource::Manual);
        $this->sales->delete($sale);

        expect((int) StockMovement::where('product_id', $this->product->id)->sum('quantity'))->toBe(stockOf($this->product));
    });

    it('handles a sale that never touched stock', function () {
        $sale = $this->sales->record($this->product, CarbonImmutable::today(), 3, '85.00', SaleSource::Manual, adjustStock: false);

        $this->sales->delete($sale);

        expect(Sale::count())->toBe(0)
            ->and(StockMovement::where('type', 'adjustment')->count())->toBe(0)
            ->and(stockOf($this->product))->toBe(50);
    });

    it('refuses to delete an imported sale', function () {
        $sale = $this->sales->record($this->product, CarbonImmutable::today(), 3, '85.00', SaleSource::Import);

        expect(fn () => $this->sales->delete($sale))->toThrow(InvalidArgumentException::class);

        expect(Sale::count())->toBe(1);
    });
});
