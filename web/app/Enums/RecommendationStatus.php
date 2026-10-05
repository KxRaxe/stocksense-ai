<?php

namespace App\Enums;

/**
 * Where a recommendation is in its life.
 *
 * Open: Pending (waiting for someone to decide) or Info (overstock, nothing to
 * decide). Decided: Accepted or Adjusted (counted as on order until the goods
 * arrive), Dismissed (not ordering now), or Cancelled (an accepted order that
 * will not be placed after all). Expired is a pending recommendation that
 * stopped being needed before anyone decided, for example because stock arrived.
 */
enum RecommendationStatus: string
{
    case Pending = 'pending';
    case Info = 'info';
    case Accepted = 'accepted';
    case Adjusted = 'adjusted';
    case Dismissed = 'dismissed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Needs a decision',
            self::Info => 'For information',
            self::Accepted => 'Accepted',
            self::Adjusted => 'Accepted with a changed quantity',
            self::Dismissed => 'Dismissed',
            self::Cancelled => 'Cancelled',
            self::Expired => 'No longer needed',
        };
    }

    /**
     * An order the person agreed to place and that has not been cancelled.
     */
    public function isOrdered(): bool
    {
        return $this === self::Accepted || $this === self::Adjusted;
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::Pending->value, self::Info->value];
    }
}
