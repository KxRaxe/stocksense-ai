<?php

namespace App\Enums;

/**
 * How urgently a product needs reordering, from the stock on hand and on order
 * measured against the forecast demand.
 *
 * - Critical: what is available cannot cover the demand expected before a new
 *   order could arrive, so it will probably run out.
 * - Low: at or below the reorder point; time to order now.
 * - Watch: above it, but expected to reach it within the review period.
 * - Ok: comfortably stocked.
 * - Overstock: more than the configured number of days of demand on hand.
 */
enum RiskLevel: string
{
    case Critical = 'critical';
    case Low = 'low';
    case Watch = 'watch';
    case Ok = 'ok';
    case Overstock = 'overstock';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::Low => 'Low',
            self::Watch => 'Watch',
            self::Ok => 'OK',
            self::Overstock => 'Overstock',
        };
    }

    /**
     * Whether the person is being asked to place an order.
     */
    public function isActionable(): bool
    {
        return in_array($this, [self::Critical, self::Low, self::Watch], true);
    }
}
