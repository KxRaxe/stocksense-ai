<?php

namespace App\Services\Replenishment;

use App\Enums\RiskLevel;
use Carbon\CarbonImmutable;

/**
 * What the calculator concluded for one product.
 */
final class ReplenishmentResult
{
    /**
     * @param  int  $position  Stock on hand plus what is on order
     * @param  float  $leadTimeDemand  Expected demand while an order would be on its way
     * @param  float  $horizonDemand  Expected demand over the lead time plus the review period
     * @param  int  $orderUpTo  The level an order should bring the position to
     * @param  int  $quantity  Units to order (at the order date), already rounded to the pack size
     * @param  CarbonImmutable|null  $orderBy  When to order: today if it is due, else when stock is expected to reach the reorder point
     * @param  float|null  $daysOfCover  How many days the position lasts at the forecast rate; null if no demand is expected
     */
    public function __construct(
        public readonly RiskLevel $risk,
        public readonly int $onHand,
        public readonly int $onOrder,
        public readonly int $position,
        public readonly float $leadTimeDemand,
        public readonly float $horizonDemand,
        public readonly int $safetyStock,
        public readonly int $reorderPoint,
        public readonly int $orderUpTo,
        public readonly int $quantity,
        public readonly ?CarbonImmutable $orderBy,
        public readonly ?float $daysOfCover,
        public readonly string $explanation,
    ) {}
}
