<?php

use App\Services\Replenishment\DemandForecast;
use App\Services\Replenishment\Normal;
use Carbon\CarbonImmutable;

/**
 * Two weeks from Monday 5 October 2026: 70 units (10 a day) then 140 (20 a day).
 */
function twoWeeks(): DemandForecast
{
    return new DemandForecast([
        ['start' => CarbonImmutable::parse('2026-10-05'), 'end' => CarbonImmutable::parse('2026-10-11'), 'units' => 70.0],
        ['start' => CarbonImmutable::parse('2026-10-12'), 'end' => CarbonImmutable::parse('2026-10-18'), 'units' => 140.0],
    ]);
}

describe('daily rates', function () {
    it('spread a period\'s units evenly over its days', function () {
        $rates = twoWeeks()->dailyRates(CarbonImmutable::parse('2026-10-05'), 14);

        expect(array_slice($rates, 0, 7))->toBe(array_fill(0, 7, 10.0))
            ->and(array_slice($rates, 7, 7))->toBe(array_fill(0, 7, 20.0));
    });

    it('start on the day asked for, part way through a period', function () {
        $rates = twoWeeks()->dailyRates(CarbonImmutable::parse('2026-10-09'), 5);

        // Friday to Sunday are 10 a day; Monday and Tuesday of the next week 20.
        expect($rates)->toBe([10.0, 10.0, 10.0, 20.0, 20.0]);
    });

    it('use the first period\'s rate for days before the forecast begins', function () {
        $rates = twoWeeks()->dailyRates(CarbonImmutable::parse('2026-10-03'), 4);

        expect($rates)->toBe([10.0, 10.0, 10.0, 10.0]);
    });

    it('use the average for days after the forecast ends', function () {
        $rates = twoWeeks()->dailyRates(CarbonImmutable::parse('2026-10-17'), 4);

        // The 17th and 18th are the forecast's last days; after it, 210 over 14 days = 15 a day.
        expect($rates)->toBe([20.0, 20.0, 15.0, 15.0]);
    });

    it('are all zero for an empty forecast', function () {
        expect((new DemandForecast([]))->dailyRates(CarbonImmutable::parse('2026-10-05'), 3))->toBe([0.0, 0.0, 0.0])
            ->and((new DemandForecast([]))->isEmpty())->toBeTrue();
    });

    it('give nothing for no days', function () {
        expect(twoWeeks()->dailyRates(CarbonImmutable::parse('2026-10-05'), 0))->toBe([]);
    });

    it('ignore the time of day', function () {
        $rates = twoWeeks()->dailyRates(CarbonImmutable::parse('2026-10-11 23:59:00'), 2);

        expect($rates)->toBe([10.0, 20.0]);
    });

    it('handle months of different lengths', function () {
        $forecast = new DemandForecast([
            ['start' => CarbonImmutable::parse('2026-02-01'), 'end' => CarbonImmutable::parse('2026-02-28'), 'units' => 280.0],
            ['start' => CarbonImmutable::parse('2026-03-01'), 'end' => CarbonImmutable::parse('2026-03-31'), 'units' => 310.0],
        ]);

        $rates = $forecast->dailyRates(CarbonImmutable::parse('2026-02-27'), 4);

        expect($rates)->toBe([10.0, 10.0, 10.0, 10.0]);   // 280 / 28 and 310 / 31
    });
});

describe('the average', function () {
    it('is units over days across the whole forecast', function () {
        expect(twoWeeks()->averageDaily())->toBe(15.0);
    });

    it('is zero for an empty forecast', function () {
        expect((new DemandForecast([]))->averageDaily())->toBe(0.0);
    });
});

describe('the normal quantile', function () {
    it('gives the familiar z-scores', function (float $p, float $z) {
        expect(Normal::quantile($p))->toEqualWithDelta($z, 0.0001);
    })->with([
        'the median' => [0.5, 0.0],
        '90%' => [0.90, 1.2815516],
        '95%' => [0.95, 1.6448536],
        '97.5%' => [0.975, 1.959964],
        '99%' => [0.99, 2.3263479],
        '99.9%' => [0.999, 3.0902323],
        '80%' => [0.80, 0.8416212],
        '60%' => [0.60, 0.2533471],
    ]);

    it('is symmetric about the median', function (float $p) {
        expect(Normal::quantile($p))->toEqualWithDelta(-Normal::quantile(1 - $p), 1e-9);
    })->with([0.6, 0.75, 0.9, 0.95, 0.99, 0.999]);

    it('rises with the probability', function () {
        $previous = -INF;

        foreach ([0.01, 0.05, 0.2, 0.5, 0.8, 0.95, 0.999] as $p) {
            expect(Normal::quantile($p))->toBeGreaterThan($previous);
            $previous = Normal::quantile($p);
        }
    });

    it('is finite in the tails', function () {
        expect(Normal::quantile(1e-6))->toEqualWithDelta(-4.753424, 0.0001)
            ->and(Normal::quantile(1 - 1e-6))->toEqualWithDelta(4.753424, 0.0001);
    });

    it('is not defined at 0 or 1', function (float $p) {
        expect(fn () => Normal::quantile($p))->toThrow(InvalidArgumentException::class);
    })->with([0.0, 1.0, -0.1, 1.5]);
});
