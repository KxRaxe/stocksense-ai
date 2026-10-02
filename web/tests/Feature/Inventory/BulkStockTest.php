<?php

use App\Enums\StockMovementType;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\StockService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->stock = app(StockService::class);
    $this->product = Product::factory()->create();
    $this->stock->openingStock($this->product, 50);
});

/** Stock on hand at the default location, as recorded in inventory_levels. */
function levelOf(Product $product): int
{
    return (int) InventoryLevel::where('product_id', $product->id)
        ->where('location_id', Location::defaultLocation()->id)
        ->value('on_hand');
}

/** One entry for recordMany(). */
function bulkEntry(Product $product, StockMovementType $type, int $quantity, string $date = '2026-03-05 12:00:00', array $extra = []): array
{
    return array_merge([
        'product_id' => $product->id,
        'type' => $type,
        'quantity' => $quantity,
        'occurred_at' => CarbonImmutable::parse($date),
    ], $extra);
}

it('gives the same result as recording the movements one by one', function () {
    $other = Product::factory()->create();
    $history = [
        [StockMovementType::Restock, 30, 'a'],
        [StockMovementType::Sale, -12, 'a'],
        [StockMovementType::Initial, 80, 'b'],
        [StockMovementType::Sale, -5, 'a'],
        [StockMovementType::Sale, -20, 'b'],
        [StockMovementType::Adjustment, -3, 'b'],
    ];

    // The same history written one movement at a time to a pair of twin products.
    $twinA = Product::factory()->create();
    $twinB = Product::factory()->create();
    $this->stock->openingStock($twinA, 50);

    foreach ($history as [$type, $quantity, $which]) {
        $this->stock->record($which === 'a' ? $twinA : $twinB, $type, $quantity);
    }

    $this->stock->recordMany(array_map(
        fn ($entry) => bulkEntry($entry[2] === 'a' ? $this->product : $other, $entry[0], $entry[1]),
        $history,
    ));

    expect(levelOf($this->product))->toBe(levelOf($twinA))->toBe(63)
        ->and(levelOf($other))->toBe(levelOf($twinB))->toBe(57)
        ->and(StockMovement::where('product_id', $this->product->id)->count())->toBe(4)   // opening + 3
        ->and(StockMovement::where('product_id', $other->id)->count())->toBe(3);
});

it('writes every field of each movement', function () {
    $user = User::factory()->create();

    $this->stock->recordMany([
        bulkEntry($this->product, StockMovementType::Restock, 7, '2026-01-15 09:00:00', [
            'note' => 'Delivery',
            'user_id' => $user->id,
            'reference_type' => 'App\Models\ImportBatch',
            'reference_id' => 42,
        ]),
    ]);

    $movement = StockMovement::where('type', 'restock')->firstOrFail();

    expect($movement->quantity)->toBe(7)
        ->and($movement->occurred_at->toDateTimeString())->toBe('2026-01-15 09:00:00')
        ->and($movement->note)->toBe('Delivery')
        ->and($movement->user_id)->toBe($user->id)
        ->and($movement->reference_type)->toBe('App\Models\ImportBatch')
        ->and($movement->reference_id)->toBe(42)
        ->and($movement->location_id)->toBe(Location::defaultLocation()->id)
        ->and($movement->created_at)->not->toBeNull();
});

it('creates stock rows for products that had none', function () {
    $fresh = Product::factory()->create();

    $this->stock->recordMany([bulkEntry($fresh, StockMovementType::Initial, 25)]);

    expect(levelOf($fresh))->toBe(25);
});

it('reduces the quantity on order for deliveries, but never below zero', function () {
    InventoryLevel::where('product_id', $this->product->id)->update(['on_order' => 20]);

    $this->stock->recordMany([
        bulkEntry($this->product, StockMovementType::Restock, 8),
        bulkEntry($this->product, StockMovementType::Restock, 8),
    ]);
    expect(InventoryLevel::first()->on_order)->toBe(4);

    $this->stock->recordMany([bulkEntry($this->product, StockMovementType::Restock, 50)]);
    expect(InventoryLevel::first()->on_order)->toBe(0);
});

it('leaves the quantity on order alone for sales', function () {
    InventoryLevel::where('product_id', $this->product->id)->update(['on_order' => 20]);

    $this->stock->recordMany([bulkEntry($this->product, StockMovementType::Sale, -5)]);

    expect(InventoryLevel::first()->on_order)->toBe(20);
});

it('handles more movements than fit in one insert', function () {
    $this->stock->recordMany(array_fill(0, 2500, bulkEntry($this->product, StockMovementType::Sale, -1)));

    expect(StockMovement::where('type', 'sale')->count())->toBe(2500)
        ->and(levelOf($this->product))->toBe(50 - 2500)
        ->and((int) StockMovement::where('product_id', $this->product->id)->sum('quantity'))->toBe(levelOf($this->product));
});

it('does nothing for an empty list', function () {
    $this->stock->recordMany([]);

    expect(StockMovement::count())->toBe(1);
});

it('writes none of them if one has the wrong direction', function () {
    expect(fn () => $this->stock->recordMany([
        bulkEntry($this->product, StockMovementType::Restock, 10),
        bulkEntry($this->product, StockMovementType::Sale, 3),   // a sale must remove stock
    ]))->toThrow(InvalidArgumentException::class, 'Movement #1');

    expect(StockMovement::count())->toBe(1)
        ->and(levelOf($this->product))->toBe(50);
});

it('writes none of them if the database refuses one', function () {
    $fresh = Product::factory()->create();

    // A product that does not exist fails the insert after the other rows were
    // prepared; nothing is kept, including the stock row made for $fresh.
    expect(fn () => $this->stock->recordMany([
        bulkEntry($fresh, StockMovementType::Initial, 5),
        ['product_id' => 99999] + bulkEntry($this->product, StockMovementType::Restock, 5),
    ]))->toThrow(QueryException::class);

    expect(StockMovement::count())->toBe(1)
        ->and(InventoryLevel::where('product_id', $fresh->id)->exists())->toBeFalse()
        ->and(levelOf($this->product))->toBe(50);
});

it('keeps stock at another location separate', function () {
    $branch = Location::create(['name' => 'Branch', 'code' => 'BR2']);

    $this->stock->recordMany([bulkEntry($this->product, StockMovementType::Restock, 7)], $branch);

    expect(InventoryLevel::where('location_id', $branch->id)->value('on_hand'))->toBe(7)
        ->and(levelOf($this->product))->toBe(50);
});
