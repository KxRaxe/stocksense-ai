<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * How finely sales are grouped for forecasting: by week (Monday to Sunday) or
 * by calendar month. The ML service uses exactly these boundaries.
 */
enum ForecastGranularity: string
{
    case Week = 'week';
    case Month = 'month';

    public function label(): string
    {
        return match ($this) {
            self::Week => 'Weekly',
            self::Month => 'Monthly',
        };
    }

    /**
     * "week" / "month", for sentences like "the next 8 weeks".
     */
    public function noun(): string
    {
        return $this->value;
    }

    /**
     * The longest horizon the ML service accepts.
     */
    public function maxHorizon(): int
    {
        return match ($this) {
            self::Week => 26,
            self::Month => 12,
        };
    }

    public function defaultHorizon(): int
    {
        return (int) config("forecasting.horizons.{$this->value}");
    }

    /**
     * How many past periods to show beside a forecast.
     */
    public function historyPeriods(): int
    {
        return (int) config("forecasting.history.{$this->value}");
    }

    /**
     * The first day of the period that contains `$day`.
     */
    public function startOf(CarbonInterface $day): CarbonImmutable
    {
        $day = CarbonImmutable::instance($day)->startOfDay();

        return $this === self::Week ? $day->startOfWeek(CarbonInterface::MONDAY) : $day->startOfMonth();
    }

    /**
     * The first day of the period after the one starting on `$start`.
     */
    public function next(CarbonInterface $start): CarbonImmutable
    {
        $start = CarbonImmutable::instance($start);

        return $this === self::Week ? $start->addWeek() : $start->addMonthNoOverflow();
    }

    /**
     * The last day of the period starting on `$start`.
     */
    public function endOf(CarbonInterface $start): CarbonImmutable
    {
        return $this->next($start)->subDay();
    }

    /**
     * The start of the last period that was complete before `$today`: this
     * week or month is still going, so it is the one before. A period only
     * counts once its last day has passed.
     */
    public function lastCompletePeriod(CarbonInterface $today): CarbonImmutable
    {
        $current = $this->startOf($today);

        return $this === self::Week ? $current->subWeek() : $current->subMonthNoOverflow();
    }

    /**
     * Days in a typical period, for scaling a per-period figure to a number of days.
     */
    public function averageDays(): float
    {
        return match ($this) {
            self::Week => 7.0,
            self::Month => 30.4375,
        };
    }
}
