<?php

namespace App\Enums;

use App\Models\User;

/**
 * The kinds of notification, each of which a person can turn on or off by
 * channel (in the app, by email), and the digest's frequency.
 *
 * Email policy: emails never contain a name or an email address; see the
 * notification classes and EmailPrivacyTest.
 */
enum NotificationType: string
{
    case CriticalStock = 'critical_stock';
    case Digest = 'replenishment_digest';
    case ForecastRun = 'forecast_run';
    case ImportErrors = 'import_errors';

    public function label(): string
    {
        return match ($this) {
            self::CriticalStock => 'Critical stock alerts',
            self::Digest => 'Replenishment digest',
            self::ForecastRun => 'Forecast runs',
            self::ImportErrors => 'Imports with problems',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CriticalStock => 'When products are expected to run out before a new order could arrive. At most once a day for each product.',
            self::Digest => 'A summary of what needs ordering, with the most urgent items.',
            self::ForecastRun => 'When a forecast you started, or any forecast if you are the Owner, finishes or fails.',
            self::ImportErrors => 'When a file you imported has rows that could not be imported.',
        };
    }

    /**
     * Whether this is a summary sent on a schedule, with a frequency to choose.
     */
    public function isDigest(): bool
    {
        return $this === self::Digest;
    }

    /**
     * Whether this person could ever receive it, given what they are allowed to do.
     */
    public function eligible(User $user): bool
    {
        return match ($this) {
            self::CriticalStock, self::Digest => $user->can(Permission::DecideRecommendations->value),
            self::ForecastRun => $user->can(Permission::ViewForecasts->value),
            self::ImportErrors => $user->can(Permission::ImportSales->value) || $user->can(Permission::ManageCatalog->value),
        };
    }
}
