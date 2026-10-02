<?php

use App\Enums\StockMovementType;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\StockService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->stock = app(StockService::class);
    $this->product = Product::factory()->create();
});

/** Stock on hand as recorded in inventory_levels. */
function onHand(Product $product): int
{
    return (int) InventoryLevel::where('product_id', $product->id)->value('on_hand');
}

/** What the ledger says: the sum of every movement for the product. */
function ledgerTotal(Product $product): int
{
    return (int) StockMovement::where('product_id', $product->id)->sum('quantity');
}

describe('opening stock', function () {
    it('writes a ledger row and a stock level', function () {
        $user = User::factory()->create();

        $movement = $this->stock->openingStock($this->product, 40, $user);

        expect($movement->type)->toBe(StockMovementType::Initial)
            ->and($movement->quantity)->toBe(40)
            ->and($movement->user_id)->toBe($user->id)
            ->and(onHand($this->product))->toBe(40);
    });

    it('does nothing for zero', function () {
        expect($this->stock->openingStock($this->product, 0))->toBeNull()
            ->and(StockMovement::count())->toBe(0)
            ->and(InventoryLevel::count())->toBe(0);
    });
});

describe('restocking', function () {
    it('adds stock', function () {
        $this->stock->openingStock($this->product, 10);
        $this->stock->restock($this->product, 25, note: 'Weekly delivery');

        expect(onHand($this->product))->toBe(35);
        expect(StockMovement::where('type', 'restock')->first()->note)->toBe('Weekly delivery');
    });

    it('reduces the quantity on order, but never below zero', function () {
        $this->stock->openingStock($this->product, 5);
        InventoryLevel::where('product_id', $this->product->id)->update(['on_order' => 30]);

        $this->stock->restock($this->product, 10);
        expect(InventoryLevel::first()->on_order)->toBe(20);

        $this->stock->restock($this->product, 50);
        expect(InventoryLevel::first()->on_order)->toBe(0);
    });

    it('can be dated in the past', function () {
        $date = CarbonImmutable::parse('2026-01-15 09:30:00');

        $movement = $this->stock->restock($this->product, 5, $date);

        expect($movement->occurred_at->equalTo($date))->toBeTrue();
    });
});

describe('stock-take adjustments', function () {
    it('records the difference between the count and what was on hand', function () {
        $this->stock->openingStock($this->product, 10);

        $down = $this->stock->adjustTo($this->product, 7, 'Damaged in storage');
        expect($down->type)->toBe(StockMovementType::Adjustment)
            ->and($down->quantity)->toBe(-3)
            ->and($down->note)->toBe('Damaged in storage')
            ->and(onHand($this->product))->toBe(7);

        $up = $this->stock->adjustTo($this->product, 12);
        expect($up->quantity)->toBe(5)->and(onHand($this->product))->toBe(12);
    });

    it('records nothing when the count matches', function () {
        $this->stock->openingStock($this->product, 10);

        expect($this->stock->adjustTo($this->product, 10))->toBeNull()
            ->and(StockMovement::count())->toBe(1);
    });

    it('can count a product that has no stock record yet', function () {
        $movement = $this->stock->adjustTo($this->product, 6);

        expect($movement->quantity)->toBe(6)->and(onHand($this->product))->toBe(6);
    });

    it('rejects a negative count', function () {
        expect(fn () => $this->stock->adjustTo($this->product, -1))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('direction rules', function () {
    it('refuses a quantity the movement type does not allow', function (StockMovementType $type, int $quantity) {
        expect(fn () => $this->stock->record($this->product, $type, $quantity))
            ->toThrow(InvalidArgumentException::class);

        expect(StockMovement::count())->toBe(0);
    })->with([
        'opening stock of zero' => [StockMovementType::Initial, 0],
        'negative opening stock' => [StockMovementType::Initial, -1],
        'negative restock' => [StockMovementType::Restock, -5],
        'positive sale' => [StockMovementType::Sale, 3],
        'zero adjustment' => [StockMovementType::Adjustment, 0],
    ]);

    it('lets a sale remove stock', function () {
        $this->stock->openingStock($this->product, 10);
        $this->stock->record($this->product, StockMovementType::Sale, -4);

        expect(onHand($this->product))->toBe(6);
    });

    it('lets stock go negative when sales outrun the recorded count', function () {
        $this->stock->openingStock($this->product, 2);
        $this->stock->record($this->product, StockMovementType::Sale, -5);

        expect(onHand($this->product))->toBe(-3);
    });
});

describe('ledger consistency', function () {
    it('keeps on_hand equal to the sum of movements through a mixed sequence', function () {
        $this->stock->openingStock($this->product, 100);
        $this->stock->record($this->product, StockMovementType::Sale, -12);
        $this->stock->restock($this->product, 40);
        $this->stock->record($this->product, StockMovementType::Sale, -30);
        $this->stock->adjustTo($this->product, 90);
        $this->stock->record($this->product, StockMovementType::Sale, -95);
        $this->stock->adjustTo($this->product, 0);

        expect(onHand($this->product))->toBe(0)->toBe(ledgerTotal($this->product));
    });

    it('keeps products separate', function () {
        $other = Product::factory()->create();

        $this->stock->openingStock($this->product, 10);
        $this->stock->openingStock($other, 99);

        expect(onHand($this->product))->toBe(10)->and(onHand($other))->toBe(99);
    });

    it('rolls back the ledger row if the stock level cannot be updated', function () {
        $this->stock->openingStock($this->product, 10);

        InventoryLevel::saving(fn () => throw new RuntimeException('disk full'));

        try {
            expect(fn () => $this->stock->restock($this->product, 5))->toThrow(RuntimeException::class);
        } finally {
            InventoryLevel::flushEventListeners();
        }

        expect(StockMovement::count())->toBe(1)
            ->and(onHand($this->product))->toBe(10);
    });
});

describe('locations', function () {
    it('tags every movement with the default location', function () {
        $this->stock->openingStock($this->product, 10);
        $this->stock->restock($this->product, 5);
        $this->stock->adjustTo($this->product, 3);

        $default = Location::defaultLocation();

        expect(StockMovement::pluck('location_id')->unique()->all())->toBe([$default->id])
            ->and(InventoryLevel::pluck('location_id')->unique()->all())->toBe([$default->id]);
    });

    it('keeps stock at another location separate', function () {
        $other = Location::create(['name' => 'Second branch', 'code' => 'BR2']);

        $this->stock->openingStock($this->product, 10);
        $this->stock->record($this->product, StockMovementType::Restock, 7, location: $other);

        expect(InventoryLevel::where('location_id', $other->id)->value('on_hand'))->toBe(7)
            ->and(InventoryLevel::where('location_id', Location::defaultLocation()->id)->value('on_hand'))->toBe(10);
    });

    it('records what caused a movement', function () {
        $cause = Product::factory()->create();

        $movement = $this->stock->record($this->product, StockMovementType::Restock, 3, reference: $cause);

        expect($movement->reference_type)->toBe($cause->getMorphClass())
            ->and($movement->reference_id)->toBe($cause->id);
    });
});
