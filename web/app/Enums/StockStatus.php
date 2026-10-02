<?php

namespace App\Enums;

/**
 * Stock health of a product at a glance. Until forecasts exist (Phase 4) the
 * only reorder threshold is the one an Owner or Manager sets on the product;
 * products without one are never flagged "low".
 */
enum StockStatus: string
{
    case OutOfStock = 'out_of_stock';
    case Low = 'low';
    case Ok = 'ok';

    public static function for(int $onHand, ?int $reorderPoint): self
    {
        if ($onHand <= 0) {
            return self::OutOfStock;
        }

        if ($reorderPoint !== null && $onHand <= $reorderPoint) {
            return self::Low;
        }

        return self::Ok;
    }

    public function label(): string
    {
        return match ($this) {
            self::OutOfStock => 'Out of stock',
            self::Low => 'Low stock',
            self::Ok => 'In stock',
        };
    }
}
