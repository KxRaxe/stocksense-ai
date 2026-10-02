<?php

namespace App\Services\Replenishment;

use Carbon\CarbonImmutable;

/**
 * Expected demand for one product, as the forecast gave it: units per week or
 * month, spread evenly over the days of each.
 *
 * A reorder decision is about days (a lead time of 5 days, a review every 7), so
 * this turns period totals into a rate for each day. Before the first forecast
 * period a day takes the first period's rate; after the last it takes the
 * average over the whole forecast, which is the most that can honestly be said
 * about a lead time that reaches past the horizon.
 */
final class DemandForecast
{
    /**
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, units: float}>  $periods  Oldest first; `end` is the last day, inclusive
     */
    public function __construct(private readonly array $periods) {}

    public function isEmpty(): bool
    {
        return $this->periods === [];
    }

    /**
     * Units per day on average over the whole forecast.
     */
    public function averageDaily(): float
    {
        $days = 0;
        $units = 0.0;

        foreach ($this->periods as $period) {
            $days += $this->days($period);
            $units += $period['units'];
        }

        return $days === 0 ? 0.0 : $units / $days;
    }

    /**
     * Expected units on each of `$days` days, starting with `$from`.
     *
     * @return list<float>
     */
    public function dailyRates(CarbonImmutable $from, int $days): array
    {
        if ($this->periods === []) {
            return array_fill(0, max($days, 0), 0.0);
        }

        $first = $this->periods[0];
        $last = $this->periods[array_key_last($this->periods)];
        $average = $this->averageDaily();
        $rates = [];

        for ($offset = 0; $offset < $days; $offset++) {
            $day = $from->addDays($offset)->startOfDay();

            $rates[] = match (true) {
                $day < $first['start'] => $first['units'] / $this->days($first),
                $day > $last['end'] => $average,
                default => $this->rateOn($day),
            };
        }

        return $rates;
    }

    private function rateOn(CarbonImmutable $day): float
    {
        foreach ($this->periods as $period) {
            if ($day >= $period['start'] && $day <= $period['end']) {
                return $period['units'] / $this->days($period);
            }
        }

        return 0.0;
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, units: float}  $period
     */
    private function days(array $period): int
    {
        return (int) round($period['start']->startOfDay()->diffInDays($period['end']->startOfDay())) + 1;
    }
}
