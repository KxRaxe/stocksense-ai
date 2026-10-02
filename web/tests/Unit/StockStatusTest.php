<?php

use App\Enums\StockMovementType;
use App\Enums\StockStatus;

it('classifies stock against the reorder point', function (int $onHand, ?int $reorderPoint, StockStatus $expected) {
    expect(StockStatus::for($onHand, $reorderPoint))->toBe($expected);
})->with([
    'none on hand' => [0, 10, StockStatus::OutOfStock],
    'negative on hand' => [-4, 10, StockStatus::OutOfStock],
    'none on hand, no reorder point' => [0, null, StockStatus::OutOfStock],
    'below the reorder point' => [5, 10, StockStatus::Low],
    'exactly at the reorder point' => [10, 10, StockStatus::Low],
    'above the reorder point' => [11, 10, StockStatus::Ok],
    'no reorder point set' => [3, null, StockStatus::Ok],
    'reorder point of zero' => [1, 0, StockStatus::Ok],
]);

it('only allows the right direction for each movement type', function (StockMovementType $type, int $quantity, bool $allowed) {
    expect($type->allows($quantity))->toBe($allowed);
})->with([
    [StockMovementType::Initial, 5, true],
    [StockMovementType::Initial, 0, false],
    [StockMovementType::Initial, -5, false],
    [StockMovementType::Restock, 5, true],
    [StockMovementType::Restock, -5, false],
    [StockMovementType::Sale, -5, true],
    [StockMovementType::Sale, 5, false],
    [StockMovementType::Adjustment, 5, true],
    [StockMovementType::Adjustment, -5, true],
    [StockMovementType::Adjustment, 0, false],
]);
