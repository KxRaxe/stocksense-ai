<?php

use App\Enums\ForecastGranularity;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Services\Forecasting\SeriesBuilder;
use Carbon\CarbonImmutable;
use Tests\Support\Contracts;

// "Today" is Friday 2 October 2026: the week of 28 September is still going,
// the last complete week began on Monday 21 September, and September is over.
const SERIES_TODAY = '2026-10-02';

beforeEach(function () {
    $this->location = Location::defaultLocation();
    $this->hardware = Category::factory()->create(['name' => 'Hardware']);
    $this->food = Category::factory()->create(['name' => 'Food']);
    $this->nails = Product::factory()->for($this->hardware)->create(['sku' => 'HW-1']);
});

function build(ForecastGranularity $granularity, string $today = SERIES_TODAY, ?Location $location = null)
{
    return app(SeriesBuilder::class)->build($granularity, $location ?? Location::defaultLocation(), CarbonImmutable::parse($today));
}

/** @return array<string, int> Units by period start */
function unitsByPeriod(array $series): array
{
    return array_column($series['periods'], 'qty', 'start');
}

describe('weekly series', function () {
    it('sums sales into weeks from Monday to Sunday', function () {
        sell($this->nails, [
            ['2026-08-02', 1, 10],   // Sunday: the week before
            ['2026-08-03', 5, 10],   // Monday
            ['2026-08-05', 3, 12],   // Wednesday
            ['2026-08-09', 2, 10],   // Sunday: still that week
            ['2026-08-10', 4, 10],   // the next Monday
        ]);

        $units = unitsByPeriod(build(ForecastGranularity::Week)->series[0]);

        expect($units['2026-07-27'])->toBe(1)
            ->and($units['2026-08-03'])->toBe(10)
            ->and($units['2026-08-10'])->toBe(4);
    });

    it('starts at the first sale and fills quiet weeks with zero up to the last complete week', function () {
        sell($this->nails, [['2026-08-03', 5, 10], ['2026-08-24', 4, 10]]);

        $series = build(ForecastGranularity::Week)->series[0];

        expect(array_column($series['periods'], 'start'))->toBe([
            '2026-08-03', '2026-08-10', '2026-08-17', '2026-08-24', '2026-08-31', '2026-09-07', '2026-09-14', '2026-09-21',
        ])
            ->and(array_column($series['periods'], 'qty'))->toBe([5, 0, 0, 4, 0, 0, 0, 0]);
    });

    it('leaves out the week still in progress', function () {
        sell($this->nails, [['2026-09-21', 6, 10], ['2026-09-30', 99, 10]]);

        $set = build(ForecastGranularity::Week);

        expect($set->lastPeriod->toDateString())->toBe('2026-09-21')
            ->and(array_column($set->series[0]['periods'], 'start'))->not->toContain('2026-09-28')
            ->and(array_sum(array_column($set->series[0]['periods'], 'qty')))->toBe(6);
    });

    it('counts a week as complete the day after it ends', function () {
        sell($this->nails, [['2026-09-23', 6, 10]]);

        // Monday 28 September: the week of 21 September ended yesterday.
        $monday = build(ForecastGranularity::Week, '2026-09-28');
        // Sunday 27 September: that week has not quite finished.
        $sunday = build(ForecastGranularity::Week, '2026-09-27');

        expect($monday->lastPeriod->toDateString())->toBe('2026-09-21')
            ->and($monday->isEmpty())->toBeFalse()
            ->and($sunday->lastPeriod->toDateString())->toBe('2026-09-14')
            ->and($sunday->isEmpty())->toBeTrue();   // its only sale is in the unfinished week
    });

    it('gives the average price of the periods that sold, and none for the rest', function () {
        sell($this->nails, [['2026-08-03', 5, 10], ['2026-08-05', 3, 12], ['2026-08-17', 2, 8]]);

        $prices = array_column(build(ForecastGranularity::Week)->series[0]['periods'], 'avg_price', 'start');

        expect($prices['2026-08-03'])->toBe(10.75)   // (50 + 36) / 8
            ->and($prices['2026-08-10'])->toBeNull()
            ->and($prices['2026-08-17'])->toBe(8.0);
    });

    it('works across a year end', function () {
        sell($this->nails, [['2025-12-29', 3, 10], ['2026-01-05', 4, 10]]);

        $periods = array_column(build(ForecastGranularity::Week, '2026-01-14')->series[0]['periods'], 'qty', 'start');

        expect($periods)->toBe(['2025-12-29' => 3, '2026-01-05' => 4]);
    });
});

describe('monthly series', function () {
    it('sums sales into calendar months, up to the last complete month', function () {
        sell($this->nails, [
            ['2026-07-31', 2, 10],
            ['2026-08-01', 3, 10],
            ['2026-08-31', 4, 10],
            ['2026-09-30', 5, 10],   // September is over by 2 October
            ['2026-10-01', 99, 10],  // October is still going
        ]);

        $series = build(ForecastGranularity::Month)->series[0];

        expect(array_column($series['periods'], 'qty', 'start'))->toBe([
            '2026-07-01' => 2,
            '2026-08-01' => 7,
            '2026-09-01' => 5,
        ]);
    });

    it('fills empty months with zero', function () {
        sell($this->nails, [['2026-05-10', 3, 10], ['2026-09-01', 2, 10]]);

        expect(array_column(build(ForecastGranularity::Month)->series[0]['periods'], 'qty'))->toBe([3, 0, 0, 0, 2]);
    });

    it('treats the first of the month as the end of the month before', function () {
        sell($this->nails, [['2026-08-20', 3, 10]]);

        $set = build(ForecastGranularity::Month, '2026-09-01');

        expect($set->lastPeriod->toDateString())->toBe('2026-08-01');
    });
});

describe('which products are included', function () {
    it('includes each product with sales, with its category, in order', function () {
        $milk = Product::factory()->for($this->food)->create(['sku' => 'FB-1']);
        sell($this->nails, [['2026-09-01', 1, 10]]);
        sell($milk, [['2026-09-08', 2, 10]]);

        $series = build(ForecastGranularity::Week)->series;

        expect(array_column($series, 'product_id'))->toBe([$this->nails->id, $milk->id])
            ->and(array_column($series, 'category'))->toBe(['Hardware', 'Food']);
    });

    it('names each series after the product and location', function () {
        sell($this->nails, [['2026-09-01', 1, 10]]);

        expect(build(ForecastGranularity::Week)->series[0]['series_key'])->toBe("{$this->nails->id}:{$this->location->id}");
    });

    it('leaves out products with no sales', function () {
        Product::factory()->for($this->food)->create();
        sell($this->nails, [['2026-09-01', 1, 10]]);

        expect(build(ForecastGranularity::Week)->series)->toHaveCount(1);
    });

    it('leaves out archived products', function () {
        $old = Product::factory()->for($this->food)->create(['is_active' => false]);
        sell($old, [['2026-09-01', 5, 10]]);
        sell($this->nails, [['2026-09-01', 1, 10]]);

        expect(array_column(build(ForecastGranularity::Week)->series, 'product_id'))->toBe([$this->nails->id]);
    });

    it('leaves out products whose only sales are in the unfinished period', function () {
        sell($this->nails, [['2026-09-30', 4, 10]]);

        expect(build(ForecastGranularity::Week)->isEmpty())->toBeTrue()
            ->and(build(ForecastGranularity::Month)->isEmpty())->toBeFalse();
    });

    it('only counts sales at the location asked about', function () {
        $branch = Location::create(['name' => 'Branch', 'code' => 'BR', 'is_default' => false]);
        sell($this->nails, [['2026-09-01', 4, 10]]);
        sell($this->nails, [['2026-09-01', 100, 10]], $branch);

        $here = build(ForecastGranularity::Week)->series[0];
        $there = build(ForecastGranularity::Week, location: $branch)->series[0];

        expect(array_sum(array_column($here['periods'], 'qty')))->toBe(4)
            ->and(array_sum(array_column($there['periods'], 'qty')))->toBe(100)
            ->and($there['series_key'])->toBe("{$this->nails->id}:{$branch->id}");
    });

    it('is empty when there are no sales at all', function () {
        expect(build(ForecastGranularity::Week)->isEmpty())->toBeTrue();
    });
});

describe('the series as a whole', function () {
    it('ends every series on the same period, however long ago the product last sold', function () {
        $milk = Product::factory()->for($this->food)->create();
        sell($this->nails, [['2026-01-05', 1, 10], ['2026-03-02', 1, 10]]);   // nothing since March
        sell($milk, [['2026-09-14', 3, 10]]);

        foreach (build(ForecastGranularity::Week)->series as $series) {
            expect(end($series['periods'])['start'])->toBe('2026-09-21');
        }
    });

    it('is consecutive: every period follows the one before', function () {
        sell($this->nails, [['2025-11-03', 1, 10], ['2026-09-07', 2, 10]]);

        foreach ([ForecastGranularity::Week, ForecastGranularity::Month] as $granularity) {
            $starts = array_column(build($granularity)->series[0]['periods'], 'start');

            for ($i = 1; $i < count($starts); $i++) {
                expect($granularity->next(CarbonImmutable::parse($starts[$i - 1]))->toDateString())->toBe($starts[$i]);
            }
        }
    });

    it('builds a request the ML service will accept (checked against the shared contract)', function () {
        $milk = Product::factory()->for($this->food)->create();
        sell($this->nails, [['2026-01-05', 1, 10], ['2026-09-14', 3, 12]]);
        sell($milk, [['2026-06-01', 7, 5]]);

        $request = build(ForecastGranularity::Week)->toRequest(8, 3);

        expect(Contracts::errors('forecast-request', $request))->toBe([])
            ->and($request['granularity'])->toBe('week')
            ->and($request['horizon'])->toBe(8)
            ->and($request['options'])->toBe(['backtest_folds' => 3]);
    });

    it('builds a monthly request that fits the contract too', function () {
        sell($this->nails, [['2026-01-05', 1, 10], ['2026-09-14', 3, 12]]);

        expect(Contracts::errors('forecast-request', build(ForecastGranularity::Month)->toRequest(3, 0)))->toBe([]);
    });

    it('carries nothing personal: only product, category and units', function () {
        sell($this->nails, [['2026-09-07', 2, 10]]);

        $series = build(ForecastGranularity::Week)->series[0];

        expect(array_keys($series))->toBe(['series_key', 'product_id', 'category', 'periods'])
            ->and(array_keys($series['periods'][0]))->toBe(['start', 'qty', 'avg_price']);
    });

    it('lists the product ids it covers', function () {
        sell($this->nails, [['2026-09-07', 2, 10]]);

        expect(build(ForecastGranularity::Week)->productIds())->toBe([$this->nails->id]);
    });
});

describe('a product\'s own history, for its chart', function () {
    it('gives the last periods up to the given one, zero-filled', function () {
        sell($this->nails, [['2026-08-03', 5, 10], ['2026-09-14', 2, 10]]);

        $history = app(SeriesBuilder::class)->history(
            $this->nails, $this->location, ForecastGranularity::Week, CarbonImmutable::parse('2026-09-21'), 4,
        );

        expect($history)->toBe([
            ['period' => '2026-08-31', 'qty' => 0],
            ['period' => '2026-09-07', 'qty' => 0],
            ['period' => '2026-09-14', 'qty' => 2],
            ['period' => '2026-09-21', 'qty' => 0],
        ]);
    });

    it('starts at the first sale when the product is younger than the window', function () {
        sell($this->nails, [['2026-09-08', 5, 10]]);

        $history = app(SeriesBuilder::class)->history(
            $this->nails, $this->location, ForecastGranularity::Week, CarbonImmutable::parse('2026-09-21'), 52,
        );

        expect(array_column($history, 'period'))->toBe(['2026-09-07', '2026-09-14', '2026-09-21']);
    });

    it('works for months and for a product that has never sold', function () {
        sell($this->nails, [['2026-07-15', 5, 10]]);
        $builder = app(SeriesBuilder::class);

        expect($builder->history($this->nails, $this->location, ForecastGranularity::Month, CarbonImmutable::parse('2026-09-01'), 12))
            ->toBe([['period' => '2026-07-01', 'qty' => 5], ['period' => '2026-08-01', 'qty' => 0], ['period' => '2026-09-01', 'qty' => 0]])
            ->and($builder->history(Product::factory()->for($this->food)->create(), $this->location, ForecastGranularity::Month, CarbonImmutable::parse('2026-09-01'), 12))
            ->toBe([]);
    });
});
