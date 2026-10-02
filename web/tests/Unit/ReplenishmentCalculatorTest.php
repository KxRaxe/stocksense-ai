<?php

use App\Enums\RiskLevel;
use App\Services\Replenishment\DemandForecast;
use App\Services\Replenishment\ReplenishmentCalculator;
use App\Services\Replenishment\ReplenishmentInput;
use App\Services\Replenishment\ReplenishmentResult;
use Carbon\CarbonImmutable;

/*
 * The base case used throughout: demand of 10 a day (70 a week) for twelve
 * weeks from Monday 5 October 2026, a 7-day lead time, a 7-day review period and
 * no forecast error. So expected demand is 70 over the lead time and 140 over
 * lead time plus review, giving a reorder point of 70 and an order-up-to level
 * of 140 before any safety stock.
 */

/**
 * A forecast of whole weeks from Monday 5 October 2026.
 *
 * @param  list<int|float>  $unitsPerWeek
 */
function weeklyDemand(array $unitsPerWeek): DemandForecast
{
    $periods = [];
    $start = CarbonImmutable::parse('2026-10-05');

    foreach ($unitsPerWeek as $units) {
        $periods[] = ['start' => $start, 'end' => $start->addDays(6), 'units' => (float) $units];
        $start = $start->addWeek();
    }

    return new DemandForecast($periods);
}

/**
 * @param  array<string, mixed>  $change
 */
function calc(array $change = []): ReplenishmentResult
{
    $defaults = [
        'onHand' => 100, 'onOrder' => 0, 'leadTimeDays' => 7, 'reviewDays' => 7, 'moq' => 1, 'packSize' => 1,
        'reorderPointOverride' => null, 'safetyStockOverride' => null, 'serviceLevel' => 95.0, 'errorPerPeriod' => 0.0,
        'periodDays' => 7.0, 'demand' => weeklyDemand(array_fill(0, 12, 70)), 'today' => CarbonImmutable::parse('2026-10-05'),
        'overstockDays' => 90, 'projectionDays' => 180,
    ];

    return (new ReplenishmentCalculator)->calculate(new ReplenishmentInput(...[...$defaults, ...$change]));
}

describe('the risk of running out', function () {
    it('is critical when stock cannot cover the demand before an order could arrive', function () {
        $result = calc(['onHand' => 69]);

        expect($result->risk)->toBe(RiskLevel::Critical)
            ->and($result->orderBy->toDateString())->toBe('2026-10-05')
            ->and($result->quantity)->toBe(71);   // 140 - 69
    });

    it('is critical when there is nothing on the shelf', function () {
        $result = calc(['onHand' => 0]);

        expect($result->risk)->toBe(RiskLevel::Critical)
            ->and($result->quantity)->toBe(140);
    });

    it('treats stock below zero as none', function () {
        $result = calc(['onHand' => -5]);

        expect($result->onHand)->toBe(0)
            ->and($result->position)->toBe(0)
            ->and($result->risk)->toBe(RiskLevel::Critical);
    });

    it('is low at the reorder point, and orders now', function () {
        $result = calc(['onHand' => 70]);

        expect($result->risk)->toBe(RiskLevel::Low)
            ->and($result->reorderPoint)->toBe(70)
            ->and($result->orderBy->toDateString())->toBe('2026-10-05')
            ->and($result->quantity)->toBe(70);   // 140 - 70
    });

    it('is watch when the reorder point will be reached within the review period', function () {
        $result = calc(['onHand' => 100]);

        // 10 a day: 70 left after three days, so order on the 8th.
        expect($result->risk)->toBe(RiskLevel::Watch)
            ->and($result->orderBy->toDateString())->toBe('2026-10-08')
            ->and($result->quantity)->toBe(70);   // 140 - 70, the position at that point
    });

    it('draws the line at the next review: reached before it is watch, on the day it falls is not', function (int $onHand, RiskLevel $risk) {
        expect(calc(['onHand' => $onHand])->risk)->toBe($risk);
    })->with([
        'reached on the sixth day' => [130, RiskLevel::Watch],
        // A product ordered right up to its order-up-to level reaches the reorder point exactly
        // one review period later, which is when the next order would be placed anyway.
        'reached on the seventh day, the next review' => [140, RiskLevel::Ok],
        'reached on the eighth day' => [150, RiskLevel::Ok],
    ]);

    it('is ok when stock is comfortable, and says when the next order falls due', function () {
        $result = calc(['onHand' => 300]);

        expect($result->risk)->toBe(RiskLevel::Ok)
            ->and($result->orderBy->toDateString())->toBe('2026-10-28')   // 23 days of 10
            ->and($result->quantity)->toBe(70)
            ->and($result->daysOfCover)->toBe(30.0);
    });

    it('is overstock when there are more days of cover than the limit', function () {
        $result = calc(['onHand' => 2000]);

        expect($result->risk)->toBe(RiskLevel::Overstock)
            ->and($result->daysOfCover)->toBe(200.0)
            ->and($result->quantity)->toBe(0)
            ->and($result->orderBy)->toBeNull();
    });

    it('draws the overstock line at the configured number of days', function () {
        expect(calc(['onHand' => 900])->risk)->toBe(RiskLevel::Ok)            // exactly 90 days
            ->and(calc(['onHand' => 910])->risk)->toBe(RiskLevel::Overstock)
            ->and(calc(['onHand' => 900, 'overstockDays' => 60])->risk)->toBe(RiskLevel::Overstock);
    });

    it('is never watch when there is no review period', function () {
        expect(calc(['onHand' => 100, 'reviewDays' => 0])->risk)->toBe(RiskLevel::Ok);
    });
});

describe('what is already on order', function () {
    it('counts toward the position, so the same product is not recommended twice', function () {
        $without = calc(['onHand' => 20, 'onOrder' => 0]);
        $with = calc(['onHand' => 20, 'onOrder' => 100]);

        expect($without->risk)->toBe(RiskLevel::Critical)
            ->and($with->position)->toBe(120)
            ->and($with->onOrder)->toBe(100)
            ->and($with->risk)->toBe(RiskLevel::Watch)
            ->and($with->orderBy->toDateString())->toBe('2026-10-10');   // 120 - 70 = 50 units, five days
    });

    it('makes an order smaller by what is coming', function () {
        $result = calc(['onHand' => 20, 'onOrder' => 30]);   // position 50: critical

        expect($result->quantity)->toBe(90);   // 140 - 50
    });

    it('ignores a negative amount on order', function () {
        expect(calc(['onHand' => 100, 'onOrder' => -10])->position)->toBe(100);
    });
});

describe('safety stock', function () {
    it('grows with the typical forecast error and the target service level', function () {
        // Error of 14 a week over 14 days is 14 x sqrt(2) = 19.8. 95% is 1.645 deviations: 32.6, so 33.
        $ninetyFive = calc(['errorPerPeriod' => 14.0, 'onHand' => 103]);

        expect($ninetyFive->safetyStock)->toBe(33)
            ->and($ninetyFive->reorderPoint)->toBe(103)    // 70 + 33
            ->and($ninetyFive->orderUpTo)->toBe(173)       // 140 + 33
            ->and($ninetyFive->risk)->toBe(RiskLevel::Low)
            ->and($ninetyFive->quantity)->toBe(70);

        // 99% is 2.326 deviations: 46.06, so 47.
        expect(calc(['errorPerPeriod' => 14.0, 'serviceLevel' => 99.0])->safetyStock)->toBe(47);
    });

    it('is nothing for a service level of 50%, which tolerates running out half the time', function () {
        expect(calc(['errorPerPeriod' => 14.0, 'serviceLevel' => 50.0])->safetyStock)->toBe(0);
    });

    it('is nothing when the forecast has no error', function () {
        expect(calc(['errorPerPeriod' => 0.0])->safetyStock)->toBe(0);
    });

    it('scales to the number of days it must cover, not the length of a period', function () {
        $short = calc(['errorPerPeriod' => 14.0, 'leadTimeDays' => 3, 'reviewDays' => 4]);   // 7 days: 14 x 1 = 14, x 1.645 = 23.03
        $long = calc(['errorPerPeriod' => 14.0, 'leadTimeDays' => 14, 'reviewDays' => 14]);  // 28 days: 28, x 1.645 = 46.06

        expect($short->safetyStock)->toBe(24)
            ->and($long->safetyStock)->toBe(47);
    });

    it('works with months as the period', function () {
        $result = calc(['errorPerPeriod' => 61.0, 'periodDays' => 30.4375]);   // 61 x sqrt(14 / 30.4375)

        expect($result->safetyStock)->toBe((int) ceil(round(1.6448536 * 61 * sqrt(14 / 30.4375), 6)));
    });

    it('is not asked for when no demand is expected', function () {
        $result = calc(['demand' => weeklyDemand(array_fill(0, 12, 0)), 'errorPerPeriod' => 50.0]);

        expect($result->safetyStock)->toBe(0);
    });

    it('does not break on a service level outside the sensible range', function () {
        expect(calc(['serviceLevel' => 100.0, 'errorPerPeriod' => 10.0])->safetyStock)->toBeGreaterThan(0)
            ->and(calc(['serviceLevel' => 10.0, 'errorPerPeriod' => 10.0])->safetyStock)->toBe(0);
    });
});

describe('hand-set values', function () {
    it('uses a reorder point set for the product, with the safety stock it implies', function () {
        $result = calc(['reorderPointOverride' => 200, 'onHand' => 150]);

        expect($result->reorderPoint)->toBe(200)
            ->and($result->safetyStock)->toBe(130)      // 200 less the 70 of lead-time demand
            ->and($result->orderUpTo)->toBe(270)        // 140 + 130
            ->and($result->risk)->toBe(RiskLevel::Low)
            ->and($result->quantity)->toBe(120);        // 270 - 150
    });

    it('uses a safety stock set for the product', function () {
        $result = calc(['safetyStockOverride' => 40, 'errorPerPeriod' => 500.0, 'onHand' => 110]);

        expect($result->safetyStock)->toBe(40)
            ->and($result->reorderPoint)->toBe(110)     // 70 + 40
            ->and($result->orderUpTo)->toBe(180)
            ->and($result->risk)->toBe(RiskLevel::Low)
            ->and($result->quantity)->toBe(70);
    });

    it('takes both when both are set', function () {
        $result = calc(['reorderPointOverride' => 100, 'safetyStockOverride' => 20]);

        expect($result->reorderPoint)->toBe(100)
            ->and($result->safetyStock)->toBe(20)
            ->and($result->orderUpTo)->toBe(160);
    });

    it('orders even when no demand is expected, if the product has a reorder point and is below it', function () {
        $result = calc(['demand' => weeklyDemand(array_fill(0, 12, 0)), 'reorderPointOverride' => 5, 'onHand' => 3]);

        expect($result->risk)->toBe(RiskLevel::Low)
            ->and($result->quantity)->toBe(2);    // up to 5
    });

    it('still calls a product critical when it will run out, whatever reorder point was set', function () {
        $result = calc(['reorderPointOverride' => 20, 'onHand' => 60]);

        expect($result->risk)->toBe(RiskLevel::Critical)
            ->and($result->orderBy->toDateString())->toBe('2026-10-05');
    });
});

describe('the quantity to order', function () {
    it('is never less than the minimum order', function () {
        $result = calc(['onHand' => 70, 'moq' => 100]);

        expect($result->quantity)->toBe(100);   // the gap is 70
    });

    it('is a whole number of packs, rounded up', function (int $moq, int $pack, int $quantity) {
        expect(calc(['onHand' => 70, 'moq' => $moq, 'packSize' => $pack])->quantity)->toBe($quantity);
    })->with([
        'a gap of 70 in packs of 12' => [1, 12, 72],
        'a gap that is already whole packs' => [1, 7, 70],
        'a minimum order that is not whole packs' => [100, 12, 108],
        'a minimum order that is' => [96, 12, 96],
        'a pack bigger than the gap' => [1, 200, 200],
    ]);

    it('covers a gap smaller than a pack with one pack', function () {
        // One day of review: the gap is 10 a day for one day, in packs of 12.
        expect(calc(['onHand' => 70, 'reviewDays' => 1, 'packSize' => 12])->quantity)->toBe(12);
    });

    it('is always at least one, even when stock is already above the order-up-to level', function () {
        // A reorder point of 1,000 with a 7-day horizon implies an order-up-to of 1,070; with 1,000 on hand.
        expect(calc(['reorderPointOverride' => 1000, 'onHand' => 1000])->quantity)->toBe(70);
    });

    it('is zero when nothing is to be ordered', function () {
        expect(calc(['onHand' => 2000])->quantity)->toBe(0)
            ->and(calc(['demand' => weeklyDemand(array_fill(0, 12, 0))])->quantity)->toBe(0);
    });
});

describe('lead time', function () {
    it('can be zero: nothing is lost waiting for delivery, so nothing is critical', function () {
        $result = calc(['leadTimeDays' => 0, 'onHand' => 0]);

        expect($result->leadTimeDemand)->toBe(0.0)
            ->and($result->reorderPoint)->toBe(0)
            ->and($result->risk)->toBe(RiskLevel::Low)
            ->and($result->quantity)->toBe(70);   // the review period's demand
    });

    it('can reach past the forecast, using the average rate for the days beyond it', function () {
        // Four weeks forecast at 70 a week; a 60-day lead time needs 32 more days at the average of 10 a day.
        $result = calc(['demand' => weeklyDemand([70, 70, 70, 70]), 'leadTimeDays' => 60, 'onHand' => 0]);

        expect($result->leadTimeDemand)->toBe(600.0)
            ->and($result->risk)->toBe(RiskLevel::Critical);
    });

    it('follows a rising forecast', function () {
        // 10 a day in the first week, 20 in the second: a 10-day lead time is 7 x 10 + 3 x 20.
        $result = calc(['demand' => weeklyDemand([70, 140, 140, 140]), 'leadTimeDays' => 10, 'reviewDays' => 4, 'onHand' => 50]);

        expect($result->leadTimeDemand)->toBe(130.0)
            ->and($result->horizonDemand)->toBe(210.0);   // plus 4 more days of 20
    });
});

describe('when no demand is expected', function () {
    it('is ok, orders nothing and says why', function () {
        $result = calc(['demand' => weeklyDemand(array_fill(0, 12, 0)), 'onHand' => 50]);

        expect($result->risk)->toBe(RiskLevel::Ok)
            ->and($result->quantity)->toBe(0)
            ->and($result->orderBy)->toBeNull()
            ->and($result->daysOfCover)->toBeNull()
            ->and($result->explanation)->toBe('No demand is expected in the forecast, so there is nothing to reorder. You have 50 on hand.');
    });

    it('is ok even with nothing on the shelf', function () {
        expect(calc(['demand' => weeklyDemand(array_fill(0, 12, 0)), 'onHand' => 0])->risk)->toBe(RiskLevel::Ok);
    });

    it('copes with an empty forecast', function () {
        expect(calc(['demand' => new DemandForecast([])])->risk)->toBe(RiskLevel::Ok);
    });
});

describe('the explanation', function () {
    it('spells out a low product', function () {
        expect(calc(['onHand' => 70])->explanation)->toBe(
            'Expected demand over the 7-day lead time is 70; with 0 safety stock, the reorder point is 70. You have 70 on hand. That is at or below the reorder point. Order 70 now, to bring stock up to 140.'
        );
    });

    it('spells out a critical product', function () {
        expect(calc(['onHand' => 69])->explanation)->toBe(
            'Stock is expected to run out before a new order could arrive: 69 available against 70 of expected demand over the 7-day lead time. You have 69 on hand. Order 71 now, to bring stock up to 140.'
        );
    });

    it('spells out a watched product, with the date', function () {
        expect(calc(['onHand' => 100])->explanation)->toBe(
            'Expected demand over the 7-day lead time is 70; with 0 safety stock, the reorder point is 70. You have 100 on hand. Stock is expected to reach the reorder point around Oct 8. Plan to order 70 then, to bring stock up to 140.'
        );
    });

    it('spells out a comfortable product', function () {
        expect(calc(['onHand' => 300])->explanation)->toContain('Stock is expected to reach the reorder point around Oct 28, when 70 would be ordered, to bring stock up to 140.');
    });

    it('spells out an overstocked product', function () {
        expect(calc(['onHand' => 2000])->explanation)->toBe(
            'You have 2,000 on hand. At the forecast rate that is about 200 days of demand, more than the 90 days considered comfortable. No order is needed.'
        );
    });

    it('mentions what is on order', function () {
        expect(calc(['onHand' => 20, 'onOrder' => 100])->explanation)->toContain('You have 20 on hand and 100 on order, 120 in all.');
    });

    it('mentions packs, and a minimum order that set the quantity', function () {
        expect(calc(['onHand' => 70, 'packSize' => 12])->explanation)->toContain('Order 72 (6 packs of 12) now');
        expect(calc(['onHand' => 70, 'reviewDays' => 1, 'packSize' => 12])->explanation)->toContain('Order 12 (1 pack of 12) now');
        expect(calc(['onHand' => 70, 'moq' => 100])->explanation)->toContain('Order 100 now, to bring stock up to 140 (the minimum order is 100).');
    });

    it('uses a singular day for a one-day lead time', function () {
        expect(calc(['onHand' => 5, 'leadTimeDays' => 1])->explanation)->toContain('over the 1-day lead time');
    });

    it('shows fractions only where they matter', function () {
        // Demand of 10.5 a day over 7 days is 73.5.
        $result = calc(['demand' => weeklyDemand(array_fill(0, 12, 73.5)), 'onHand' => 60]);

        expect($result->explanation)->toContain('73.5');
    });

    it('contains nothing personal', function () {
        // Only numbers and dates: no names, addresses or product names can appear.
        expect(calc(['onHand' => 70])->explanation)->not->toMatch('/@|[A-Z][a-z]+ [A-Z][a-z]+ [A-Z]/');
    });
});

describe('the figures on the result', function () {
    it('gives the expected demand over the lead time and the review horizon', function () {
        $result = calc(['onHand' => 70]);

        expect($result->leadTimeDemand)->toBe(70.0)
            ->and($result->horizonDemand)->toBe(140.0);
    });

    it('gives days of cover at the average forecast rate', function () {
        expect(calc(['onHand' => 70])->daysOfCover)->toBe(7.0)
            ->and(calc(['onHand' => 25, 'onOrder' => 5])->daysOfCover)->toBe(3.0);
    });

    it('starts from the day it is asked on', function () {
        // On Thursday the 8th, three days of the first week have gone: it is still 10 a day.
        $result = calc(['today' => CarbonImmutable::parse('2026-10-08'), 'onHand' => 70]);

        expect($result->orderBy->toDateString())->toBe('2026-10-08');
    });

    it('is the same whatever the time of day', function () {
        $morning = calc(['today' => CarbonImmutable::parse('2026-10-05 06:00:00'), 'onHand' => 100]);
        $evening = calc(['today' => CarbonImmutable::parse('2026-10-05 22:30:00')->startOfDay(), 'onHand' => 100]);

        expect($morning->orderBy->toDateString())->toBe($evening->orderBy->toDateString());
    });

    it('works through a forecast of months', function () {
        $periods = [];
        $start = CarbonImmutable::parse('2026-10-01');

        foreach ([310, 300, 310] as $units) {   // 10 a day, 10 a day, 10 a day
            $end = $start->endOfMonth()->startOfDay();
            $periods[] = ['start' => $start, 'end' => $end, 'units' => (float) $units];
            $start = $start->addMonthNoOverflow();
        }

        $result = calc(['demand' => new DemandForecast($periods), 'periodDays' => 30.4375, 'today' => CarbonImmutable::parse('2026-10-01'), 'onHand' => 70]);

        expect($result->leadTimeDemand)->toBe(70.0)
            ->and($result->risk)->toBe(RiskLevel::Low);
    });
});
