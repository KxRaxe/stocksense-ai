<?php

namespace App\Services\Replenishment;

use Carbon\CarbonImmutable;

/**
 * Everything the calculator needs to know about one product, none of it fetched
 * from the database, so the arithmetic can be tested on its own.
 */
final class ReplenishmentInput
{
    /**
     * @param  int  $onHand  Units in stock now (never below zero for this purpose)
     * @param  int  $onOrder  Units ordered and not yet received
     * @param  int  $leadTimeDays  Days from ordering to the goods arriving
     * @param  int  $reviewDays  Days between the orders the shop would normally place
     * @param  int  $moq  Smallest quantity the supplier will sell
     * @param  int  $packSize  Orders come in multiples of this
     * @param  int|null  $reorderPointOverride  A reorder point set by hand for this product
     * @param  int|null  $safetyStockOverride  A safety stock set by hand for this product
     * @param  float  $serviceLevel  Target chance of not running out, as a percentage (95)
     * @param  float  $errorPerPeriod  Typical forecast miss in units per forecast period (week or month)
     * @param  float  $periodDays  Days in a forecast period (7, or about 30.4 for months)
     * @param  int  $overstockDays  More days of demand than this in stock counts as overstock
     * @param  int  $projectionDays  How far ahead to look for the day the reorder point is reached
     */
    public function __construct(
        public readonly int $onHand,
        public readonly int $onOrder,
        public readonly int $leadTimeDays,
        public readonly int $reviewDays,
        public readonly int $moq,
        public readonly int $packSize,
        public readonly ?int $reorderPointOverride,
        public readonly ?int $safetyStockOverride,
        public readonly float $serviceLevel,
        public readonly float $errorPerPeriod,
        public readonly float $periodDays,
        public readonly DemandForecast $demand,
        public readonly CarbonImmutable $today,
        public readonly int $overstockDays = 90,
        public readonly int $projectionDays = 180,
    ) {}
}
