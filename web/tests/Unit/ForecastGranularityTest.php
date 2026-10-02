<?php

use App\Enums\ForecastGranularity;
use Carbon\CarbonImmutable;

const WEEK = ForecastGranularity::Week;
const MONTH = ForecastGranularity::Month;

function day(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date);
}

describe('where a period starts', function () {
    it('starts a week on Monday', function (string $date, string $monday) {
        expect(WEEK->startOf(day($date))->toDateString())->toBe($monday);
    })->with([
        'a Monday' => ['2026-09-21', '2026-09-21'],
        'a Wednesday' => ['2026-09-23', '2026-09-21'],
        'a Sunday' => ['2026-09-27', '2026-09-21'],
        'across a year end' => ['2026-01-01', '2025-12-29'],
    ]);

    it('starts a month on the first', function () {
        expect(MONTH->startOf(day('2026-09-30'))->toDateString())->toBe('2026-09-01')
            ->and(MONTH->startOf(day('2026-02-01'))->toDateString())->toBe('2026-02-01');
    });

    it('ignores the time of day', function () {
        expect(WEEK->startOf(CarbonImmutable::parse('2026-09-27 23:59:59'))->toDateTimeString())->toBe('2026-09-21 00:00:00');
    });
});

describe('the period after', function () {
    it('is a week or a month on', function () {
        expect(WEEK->next(day('2026-09-21'))->toDateString())->toBe('2026-09-28')
            ->and(MONTH->next(day('2026-09-01'))->toDateString())->toBe('2026-10-01')
            ->and(MONTH->next(day('2026-12-01'))->toDateString())->toBe('2027-01-01')
            ->and(MONTH->next(day('2028-01-01'))->toDateString())->toBe('2028-02-01');
    });

    it('ends the day before the next one starts', function () {
        expect(WEEK->endOf(day('2026-09-21'))->toDateString())->toBe('2026-09-27')
            ->and(MONTH->endOf(day('2026-09-01'))->toDateString())->toBe('2026-09-30')
            ->and(MONTH->endOf(day('2028-02-01'))->toDateString())->toBe('2028-02-29');
    });
});

describe('the last complete period', function () {
    it('is the week before the current one', function (string $today, string $expected) {
        expect(WEEK->lastCompletePeriod(day($today))->toDateString())->toBe($expected);
    })->with([
        'midweek' => ['2026-10-02', '2026-09-21'],
        'on Monday, when last week has just ended' => ['2026-09-28', '2026-09-21'],
        'on Sunday, when this week has not' => ['2026-09-27', '2026-09-14'],
        'at the start of a year' => ['2026-01-02', '2025-12-22'],
    ]);

    it('is the month before the current one', function (string $today, string $expected) {
        expect(MONTH->lastCompletePeriod(day($today))->toDateString())->toBe($expected);
    })->with([
        'midmonth' => ['2026-10-15', '2026-09-01'],
        'on the 1st, when last month has just ended' => ['2026-10-01', '2026-09-01'],
        'on the last day, when this month has not' => ['2026-09-30', '2026-08-01'],
        'in January' => ['2026-01-10', '2025-12-01'],
        'after a short month' => ['2026-03-31', '2026-02-01'],
    ]);
});

describe('limits and labels', function () {
    it('allows half a year of weeks or a year of months', function () {
        expect(WEEK->maxHorizon())->toBe(26)
            ->and(MONTH->maxHorizon())->toBe(12);
    });

    it('is labelled for people', function () {
        expect(WEEK->label())->toBe('Weekly')
            ->and(MONTH->label())->toBe('Monthly')
            ->and(WEEK->noun())->toBe('week')
            ->and(MONTH->noun())->toBe('month');
    });

    it('measures a typical period in days', function () {
        expect(WEEK->averageDays())->toBe(7.0)
            ->and(MONTH->averageDays())->toBe(30.4375);
    });
});
