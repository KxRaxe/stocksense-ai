<?php

use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Inventory\StockService;

beforeEach(function () {
    $this->product = Product::factory()->create();
    $this->stock = app(StockService::class);
});

function onOrderOf(Product $product): int
{
    return (int) InventoryLevel::where('product_id', $product->id)->where('location_id', Location::defaultLocation()->id)->value('on_order');
}

describe('goods on order', function () {
    it('are counted without becoming stock', function () {
        $this->stock->openingStock($this->product, 20);
        $movements = StockMovement::count();

        $this->stock->addOnOrder($this->product, 50);
        $level = InventoryLevel::where('product_id', $this->product->id)->firstOrFail();

        expect($level->on_order)->toBe(50)
            ->and($level->on_hand)->toBe(20)
            ->and(StockMovement::count())->toBe($movements);
    });

    it('add up', function () {
        $this->stock->addOnOrder($this->product, 50);
        $this->stock->addOnOrder($this->product, 30);

        expect(onOrderOf($this->product))->toBe(80);
    });

    it('can be recorded for a product that has no stock record yet', function () {
        $this->stock->addOnOrder($this->product, 10);

        expect(onOrderOf($this->product))->toBe(10)
            ->and(InventoryLevel::where('product_id', $this->product->id)->value('on_hand'))->toBe(0);
    });

    it('come down as goods are received, never below zero', function () {
        $this->stock->addOnOrder($this->product, 50);

        $this->stock->restock($this->product, 20);
        expect(onOrderOf($this->product))->toBe(30);

        $this->stock->restock($this->product, 100);
        expect(onOrderOf($this->product))->toBe(0)
            ->and(InventoryLevel::where('product_id', $this->product->id)->value('on_hand'))->toBe(120);
    });

    it('must be at least one unit', function (int $quantity) {
        expect(fn () => $this->stock->addOnOrder($this->product, $quantity))->toThrow(InvalidArgumentException::class);
    })->with([0, -3]);
});

describe('taking goods off the order', function () {
    it('lowers what is on order and says how much came off', function () {
        $this->stock->addOnOrder($this->product, 50);

        expect($this->stock->reduceOnOrder($this->product, 20))->toBe(20)
            ->and(onOrderOf($this->product))->toBe(30);
    });

    it('never goes below zero, and says how much really came off', function () {
        $this->stock->addOnOrder($this->product, 10);

        expect($this->stock->reduceOnOrder($this->product, 25))->toBe(10)
            ->and(onOrderOf($this->product))->toBe(0);
    });

    it('does nothing to a product with nothing on order', function () {
        expect($this->stock->reduceOnOrder($this->product, 5))->toBe(0)
            ->and(onOrderOf($this->product))->toBe(0);
    });

    it('leaves the stock and the ledger alone', function () {
        $this->stock->openingStock($this->product, 20);
        $this->stock->addOnOrder($this->product, 50);
        $movements = StockMovement::count();

        $this->stock->reduceOnOrder($this->product, 50);

        expect(InventoryLevel::where('product_id', $this->product->id)->value('on_hand'))->toBe(20)
            ->and(StockMovement::count())->toBe($movements);
    });

    it('must be at least one unit', function () {
        expect(fn () => $this->stock->reduceOnOrder($this->product, 0))->toThrow(InvalidArgumentException::class);
    });

    it('only touches the location asked about', function () {
        $branch = Location::create(['name' => 'Branch', 'code' => 'BR', 'is_default' => false]);
        $this->stock->addOnOrder($this->product, 40, $branch);
        $this->stock->addOnOrder($this->product, 10);

        $this->stock->reduceOnOrder($this->product, 40, $branch);

        expect(onOrderOf($this->product))->toBe(10)
            ->and(InventoryLevel::where('product_id', $this->product->id)->where('location_id', $branch->id)->value('on_order'))->toBe(0);
    });
});
