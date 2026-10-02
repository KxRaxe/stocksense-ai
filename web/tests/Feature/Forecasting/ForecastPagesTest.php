<?php

use App\Enums\ForecastGranularity;
use App\Enums\ForecastStatus;
use App\Jobs\RunForecastJob;
use App\Models\Category;
use App\Models\ForecastRun;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = User::factory()->manager()->create();
    $this->hardware = Category::factory()->create(['name' => 'Hardware']);
    $this->food = Category::factory()->create(['name' => 'Food']);

    $this->nails = Product::factory()->for($this->hardware)->create(['sku' => 'HW-1', 'name' => 'Nails', 'unit' => 'kg']);
    $this->milk = Product::factory()->for($this->food)->create(['sku' => 'FB-1', 'name' => 'Milk', 'unit' => 'box']);
    $this->newcomer = Product::factory()->for($this->food)->create(['sku' => 'FB-2', 'name' => 'Cheese', 'unit' => 'pc']);
});

/**
 * A weekly run as of Monday 21 September 2026 looking 4 weeks ahead, with forecasts for three products.
 * Nails sold 20 + 30 + 10 + 40 = 100 over the last four weeks; the forecast totals 110.
 */
function weeklyRunWithForecasts(): ForecastRun
{
    $run = completedRun();

    forecastFor($run, test()->nails, [['2026-09-28', 30, 20, 40], ['2026-10-05', 25, 15, 35], ['2026-10-12', 25, 15, 35], ['2026-10-19', 30, 20, 40]]);
    forecastFor($run, test()->milk, [['2026-09-28', 8, 4, 12], ['2026-10-05', 8, 4, 12], ['2026-10-12', 8, 4, 12], ['2026-10-19', 8, 4, 12]]);
    forecastFor($run, test()->newcomer, [['2026-09-28', 3, 1, 6], ['2026-10-05', 3, 1, 6], ['2026-10-12', 3, 1, 6], ['2026-10-19', 3, 1, 6]], 'moving_average', true);

    sell(test()->nails, [['2026-09-01', 20, 10], ['2026-09-08', 30, 10], ['2026-09-15', 10, 10], ['2026-09-21', 40, 10]]);
    // Before the four weeks being compared, and after them: neither counts.
    sell(test()->nails, [['2026-08-20', 999, 10], ['2026-09-29', 777, 10]]);

    return $run;
}

describe('who may look', function () {
    it('sends guests to log in', function (string $routeName) {
        $this->get(route($routeName, $routeName === 'forecasts.product' ? [$this->nails] : []))->assertRedirect(route('login'));
    })->with(['forecasts.index', 'forecasts.accuracy', 'forecasts.product']);

    it('lets the Owner and Manager look', function (string $routeName, string $role) {
        $user = User::factory()->{$role}()->create();

        $this->actingAs($user)->get(route($routeName, $routeName === 'forecasts.product' ? [$this->nails] : []))->assertOk();
    })->with(['forecasts.index', 'forecasts.accuracy', 'forecasts.product'])->with(['owner', 'manager']);

    it('keeps inventory staff out', function (string $routeName) {
        $staff = User::factory()->inventoryStaff()->create();

        $this->actingAs($staff)->get(route($routeName, $routeName === 'forecasts.product' ? [$this->nails] : []))->assertForbidden();
    })->with(['forecasts.index', 'forecasts.accuracy', 'forecasts.product']);

    it('does not find a product that does not exist', function () {
        $this->actingAs($this->manager)->get(route('forecasts.product', 999999))->assertNotFound();
    });
});

describe('the forecast list', function () {
    it('says there is no forecast yet when there is not one', function () {
        $this->actingAs($this->manager)
            ->get(route('forecasts.index'))
            ->assertInertia(fn ($page) => $page
                ->component('forecasts/index')
                ->where('granularity', 'week')
                ->where('run', null)
                ->where('products', null)
                ->where('periods', [])
                ->where('activeRun', null)
                ->where('failure', null)
                ->where('can.run', true));
    });

    it('offers weekly and monthly', function () {
        $this->actingAs($this->manager)
            ->get(route('forecasts.index'))
            ->assertInertia(fn ($page) => $page->where('granularities', [
                ['value' => 'week', 'label' => 'Weekly'],
                ['value' => 'month', 'label' => 'Monthly'],
            ]));
    });

    it('describes the latest completed run', function () {
        $run = weeklyRunWithForecasts();

        $this->actingAs($this->manager)
            ->get(route('forecasts.index'))
            ->assertInertia(fn ($page) => $page
                ->where('run.id', $run->id)
                ->where('run.granularity', 'week')
                ->where('run.horizon', 4)
                ->where('run.model_version', 'xgb-week-20261001T000000Z')
                ->where('run.as_of', '2026-09-21')
                ->where('run.first_period', '2026-09-28')
                ->where('run.last_period', '2026-10-19')
                ->where('run.n_products', 3)
                ->where('run.n_model_products', 2)
                ->where('run.n_low_confidence', 1)
                ->where('run.headline', ['model' => 16, 'seasonal_naive' => 20, 'moving_average' => 18, 'coverage' => 70])
                ->where('periods', ['2026-09-28', '2026-10-05', '2026-10-12', '2026-10-19']));
    });

    it('lists each forecast product with what is expected', function () {
        weeklyRunWithForecasts();

        $this->actingAs($this->manager)
            ->get(route('forecasts.index'))
            ->assertInertia(fn ($page) => $page
                ->has('products.data', 3)
                ->where('products.data.0', [
                    'id' => $this->newcomer->id, 'sku' => 'FB-2', 'name' => 'Cheese', 'category' => 'Food', 'unit' => 'pc',
                    'next' => 3, 'next_lower' => 1, 'next_upper' => 6, 'total' => 12, 'recent' => 0, 'change' => null, 'low_confidence' => true,
                ])
                ->where('products.data.2.name', 'Nails')
                ->where('products.data.2.next', 30)
                ->where('products.data.2.next_lower', 20)
                ->where('products.data.2.next_upper', 40)
                ->where('products.data.2.total', 110)
                ->where('products.data.2.low_confidence', false));
    });

    it('compares the forecast with the same number of periods just gone', function () {
        weeklyRunWithForecasts();

        $this->actingAs($this->manager)
            ->get(route('forecasts.index'))
            ->assertInertia(fn ($page) => $page
                // Sold 100 in the four weeks to 27 September; the forecast is 110: up 10%.
                ->where('products.data.2.recent', 100)
                ->where('products.data.2.change', 10)
                // Milk sold nothing, so there is nothing to compare with.
                ->where('products.data.1.recent', 0)
                ->where('products.data.1.change', null));
    });

    it('leaves out products with no forecast in the run', function () {
        weeklyRunWithForecasts();
        $unforecast = Product::factory()->for($this->food)->create(['name' => 'Zzz never sold']);

        $this->actingAs($this->manager)
            ->get(route('forecasts.index'))
            ->assertInertia(fn ($page) => $page->has('products.data', 3)->where('products.total', 3));

        expect($unforecast->exists)->toBeTrue();
    });

    it('can be searched by name or SKU', function () {
        weeklyRunWithForecasts();

        $this->actingAs($this->manager)->get(route('forecasts.index', ['search' => 'nail']))
            ->assertInertia(fn ($page) => $page->has('products.data', 1)->where('products.data.0.name', 'Nails')->where('filters.search', 'nail'));

        $this->actingAs($this->manager)->get(route('forecasts.index', ['search' => 'fb-']))
            ->assertInertia(fn ($page) => $page->has('products.data', 2));
    });

    it('can be narrowed to a category', function () {
        weeklyRunWithForecasts();

        $this->actingAs($this->manager)->get(route('forecasts.index', ['category' => $this->food->id]))
            ->assertInertia(fn ($page) => $page->has('products.data', 2)->where('filters.category', $this->food->id));
    });

    it('can show only the low-confidence products', function () {
        weeklyRunWithForecasts();

        $this->actingAs($this->manager)->get(route('forecasts.index', ['confidence' => 'low']))
            ->assertInertia(fn ($page) => $page->has('products.data', 1)->where('products.data.0.name', 'Cheese')->where('filters.confidence', 'low'));
    });

    it('can be sorted by what is expected', function () {
        weeklyRunWithForecasts();

        $this->actingAs($this->manager)->get(route('forecasts.index', ['sort' => 'forecast']))
            ->assertInertia(fn ($page) => $page
                ->where('products.data', fn ($rows) => $rows->pluck('name')->all() === ['Nails', 'Milk', 'Cheese'])
                ->where('filters.sort', 'forecast'));
    });

    it('is sorted by name unless asked otherwise, and ignores a sort it does not know', function () {
        weeklyRunWithForecasts();

        $this->actingAs($this->manager)->get(route('forecasts.index', ['sort' => 'nonsense']))
            ->assertInertia(fn ($page) => $page->where('products.data', fn ($rows) => $rows->pluck('name')->all() === ['Cheese', 'Milk', 'Nails'])->where('filters.sort', 'name'));
    });

    it('pages through a long list', function () {
        $run = weeklyRunWithForecasts();

        foreach (Product::factory()->count(14)->for($this->hardware)->create() as $product) {
            forecastFor($run, $product, [['2026-09-28', 1, 0, 2], ['2026-10-05', 1, 0, 2], ['2026-10-12', 1, 0, 2], ['2026-10-19', 1, 0, 2]]);
        }

        $this->actingAs($this->manager)->get(route('forecasts.index'))
            ->assertInertia(fn ($page) => $page->has('products.data', 15)->where('products.total', 17)->where('products.last_page', 2));

        $this->actingAs($this->manager)->get(route('forecasts.index', ['page' => 2]))
            ->assertInertia(fn ($page) => $page->has('products.data', 2));
    });

    it('uses the newest completed run and ignores older ones', function () {
        $old = completedRun(['model_version' => 'old']);
        forecastFor($old, $this->nails, [['2026-09-21', 999, 900, 1000]]);
        $new = weeklyRunWithForecasts();

        $this->actingAs($this->manager)->get(route('forecasts.index'))
            ->assertInertia(fn ($page) => $page->where('run.id', $new->id)->where('products.data.2.total', 110));
    });

    it('keeps weekly and monthly apart', function () {
        weeklyRunWithForecasts();
        $monthly = completedRun(['granularity' => 'month', 'horizon' => 2, 'as_of' => '2026-09-01', 'model_version' => 'xgb-month-x']);
        forecastFor($monthly, $this->nails, [['2026-10-01', 120, 90, 150], ['2026-11-01', 110, 80, 140]]);

        $this->actingAs($this->manager)->get(route('forecasts.index', ['granularity' => 'month']))
            ->assertInertia(fn ($page) => $page
                ->where('granularity', 'month')
                ->where('run.id', $monthly->id)
                ->where('run.first_period', '2026-10-01')
                ->where('run.last_period', '2026-11-01')
                ->where('periods', ['2026-10-01', '2026-11-01'])
                ->has('products.data', 1)
                ->where('products.data.0.total', 230));
    });

    it('falls back to weekly for a granularity it does not know', function () {
        $this->actingAs($this->manager)->get(route('forecasts.index', ['granularity' => 'day']))
            ->assertInertia(fn ($page) => $page->where('granularity', 'week'));
    });

    it('shows a run that is under way', function (ForecastStatus $status) {
        $run = ForecastRun::create(['granularity' => 'week', 'horizon' => 8, 'status' => $status, 'location_id' => Location::defaultLocation()->id]);

        $this->actingAs($this->manager)->get(route('forecasts.index'))
            ->assertInertia(fn ($page) => $page
                ->where('activeRun.id', $run->id)
                ->where('activeRun.status', $status->value)
                ->where('activeRun.status_label', $status->label()));
    })->with([ForecastStatus::Queued, ForecastStatus::Running]);

    it('shows a run under way for the granularity being looked at only', function () {
        ForecastRun::create(['granularity' => 'month', 'horizon' => 3, 'status' => 'running', 'location_id' => Location::defaultLocation()->id]);

        $this->actingAs($this->manager)->get(route('forecasts.index'))->assertInertia(fn ($page) => $page->where('activeRun', null));
    });

    it('says why the latest attempt failed', function () {
        ForecastRun::create(['granularity' => 'week', 'horizon' => 8, 'status' => 'failed', 'location_id' => Location::defaultLocation()->id, 'error_message' => 'The forecasting service could not be reached.']);

        $this->actingAs($this->manager)->get(route('forecasts.index'))
            ->assertInertia(fn ($page) => $page->where('failure', 'The forecasting service could not be reached.'));
    });

    it('does not mention an old failure once a newer run has worked', function () {
        ForecastRun::create(['granularity' => 'week', 'horizon' => 8, 'status' => 'failed', 'location_id' => Location::defaultLocation()->id, 'error_message' => 'Old trouble.']);
        weeklyRunWithForecasts();

        $this->actingAs($this->manager)->get(route('forecasts.index'))->assertInertia(fn ($page) => $page->where('failure', null));
    });

    it('still shows the last good forecast beside a newer failure', function () {
        $good = weeklyRunWithForecasts();
        ForecastRun::create(['granularity' => 'week', 'horizon' => 8, 'status' => 'failed', 'location_id' => Location::defaultLocation()->id, 'error_message' => 'Newer trouble.']);

        $this->actingAs($this->manager)->get(route('forecasts.index'))
            ->assertInertia(fn ($page) => $page->where('run.id', $good->id)->where('failure', 'Newer trouble.')->has('products.data', 3));
    });

    it('lists the categories to filter by', function () {
        $this->actingAs($this->manager)->get(route('forecasts.index'))
            ->assertInertia(fn ($page) => $page->where('categories', fn ($rows) => $rows->pluck('name')->all() === ['Food', 'Hardware']));
    });
});

describe('a product\'s forecast', function () {
    it('gives its recent sales and what is expected next', function () {
        weeklyRunWithForecasts();

        $this->actingAs($this->manager)->get(route('forecasts.product', $this->nails))
            ->assertInertia(fn ($page) => $page
                ->component('forecasts/product')
                ->where('product', ['id' => $this->nails->id, 'sku' => 'HW-1', 'name' => 'Nails', 'category' => 'Hardware', 'unit' => 'kg', 'is_active' => true])
                ->where('granularity', 'week')
                ->where('forecast', [
                    ['period' => '2026-09-28', 'yhat' => 30, 'lower' => 20, 'upper' => 40],
                    ['period' => '2026-10-05', 'yhat' => 25, 'lower' => 15, 'upper' => 35],
                    ['period' => '2026-10-12', 'yhat' => 25, 'lower' => 15, 'upper' => 35],
                    ['period' => '2026-10-19', 'yhat' => 30, 'lower' => 20, 'upper' => 40],
                ])
                ->where('method', 'xgboost')
                ->where('method_label', 'Forecasting model')
                ->where('low_confidence', false));
    });

    it('gives past sales up to the end of the run\'s history, quiet weeks as zero, and nothing after', function () {
        weeklyRunWithForecasts();

        $this->actingAs($this->manager)->get(route('forecasts.product', $this->nails))
            ->assertInertia(fn ($page) => $page
                ->where('history.0.period', '2026-08-17')   // the week of the 999 sale
                ->where('history.0.qty', 999)
                ->where('history.1', ['period' => '2026-08-24', 'qty' => 0])
                ->where('history', fn ($rows) => $rows->last() === ['period' => '2026-09-21', 'qty' => 40]));
    });

    it('says how far off its forecasts have been', function () {
        $run = weeklyRunWithForecasts();
        $run->forceFill(['residual_std' => [$this->nails->id => 4.25]])->save();

        $this->actingAs($this->manager)->get(route('forecasts.product', $this->nails))
            ->assertInertia(fn ($page) => $page->where('typical_error', 4.25));

        $this->actingAs($this->manager)->get(route('forecasts.product', $this->milk))
            ->assertInertia(fn ($page) => $page->where('typical_error', null));
    });

    it('flags a product with under a year of history, and says how much it has', function () {
        weeklyRunWithForecasts();
        sell($this->newcomer, [['2026-08-31', 3, 5], ['2026-09-14', 2, 5]]);

        $this->actingAs($this->manager)->get(route('forecasts.product', $this->newcomer))
            ->assertInertia(fn ($page) => $page
                ->where('low_confidence', true)
                ->where('method', 'moving_average')
                ->where('method_label', 'Recent average')
                ->where('history_periods', 4));   // the weeks of 31 Aug, 7, 14 and 21 September
    });

    it('says a product that has never sold has no history', function () {
        weeklyRunWithForecasts();

        $this->actingAs($this->manager)->get(route('forecasts.product', $this->milk))
            ->assertInertia(fn ($page) => $page->where('history_periods', 0)->where('history', []));
    });

    it('has nothing to show before the first run', function () {
        $this->actingAs($this->manager)->get(route('forecasts.product', $this->nails))
            ->assertInertia(fn ($page) => $page
                ->where('run', null)
                ->where('forecast', [])
                ->where('history', [])
                ->where('method', null)
                ->where('low_confidence', false));
    });

    it('has no forecast for a product that was not in the run', function () {
        weeklyRunWithForecasts();
        $other = Product::factory()->for($this->food)->create();

        $this->actingAs($this->manager)->get(route('forecasts.product', $other))
            ->assertInertia(fn ($page) => $page->where('forecast', [])->where('method', null)->where('run.id', ForecastRun::first()->id));
    });

    it('can be looked at monthly', function () {
        $monthly = completedRun(['granularity' => 'month', 'horizon' => 2, 'as_of' => '2026-09-01']);
        forecastFor($monthly, $this->nails, [['2026-10-01', 120, 90, 150], ['2026-11-01', 110, 80, 140]]);
        sell($this->nails, [['2026-07-15', 50, 10], ['2026-09-10', 70, 10]]);

        $this->actingAs($this->manager)->get(route('forecasts.product', [$this->nails, 'granularity' => 'month']))
            ->assertInertia(fn ($page) => $page
                ->where('granularity', 'month')
                ->where('history', [['period' => '2026-07-01', 'qty' => 50], ['period' => '2026-08-01', 'qty' => 0], ['period' => '2026-09-01', 'qty' => 70]])
                ->where('forecast.0.period', '2026-10-01')
                ->where('history_periods', 3));
    });

    it('shows an archived product, marked as such', function () {
        weeklyRunWithForecasts();
        $this->nails->update(['is_active' => false]);

        $this->actingAs($this->manager)->get(route('forecasts.product', $this->nails))
            ->assertInertia(fn ($page) => $page->where('product.is_active', false)->has('forecast', 4));
    });
});

describe('starting a forecast', function () {
    beforeEach(fn () => Queue::fake());

    it('queues a run for a Manager', function () {
        $this->actingAs($this->manager)
            ->post(route('forecasts.run'), ['granularity' => 'week'])
            ->assertRedirect(route('forecasts.index', ['granularity' => 'week']))
            ->assertSessionHas('inertia.flash_data.toast.type', 'success');

        Queue::assertPushedOn('ml', RunForecastJob::class);
        expect(ForecastRun::first())
            ->granularity->toBe(ForecastGranularity::Week)
            ->horizon->toBe(8)
            ->triggered_by->toBe($this->manager->id);
    });

    it('queues a monthly run', function () {
        $this->actingAs($this->manager)
            ->post(route('forecasts.run'), ['granularity' => 'month'])
            ->assertRedirect(route('forecasts.index', ['granularity' => 'month']));

        expect(ForecastRun::first())->granularity->toBe(ForecastGranularity::Month)->horizon->toBe(3);
    });

    it('takes a horizon', function () {
        $this->actingAs($this->manager)->post(route('forecasts.run'), ['granularity' => 'week', 'horizon' => 12])->assertSessionHasNoErrors();

        expect(ForecastRun::first()->horizon)->toBe(12);
    });

    it('says one is already going instead of starting another', function () {
        $this->actingAs($this->manager)->post(route('forecasts.run'), ['granularity' => 'week']);
        $this->actingAs($this->manager)
            ->post(route('forecasts.run'), ['granularity' => 'week'])
            ->assertSessionHas('inertia.flash_data.toast.type', 'info')
            ->assertSessionHas('inertia.flash_data.toast.message', 'A weekly forecast is already running.');

        expect(ForecastRun::count())->toBe(1);
        Queue::assertPushed(RunForecastJob::class, 1);
    });

    it('turns down a granularity it does not know', function () {
        $this->actingAs($this->manager)->post(route('forecasts.run'), ['granularity' => 'day'])->assertSessionHasErrors('granularity');
        $this->actingAs($this->manager)->post(route('forecasts.run'), [])->assertSessionHasErrors('granularity');

        expect(ForecastRun::count())->toBe(0);
    });

    it('turns down a horizon that is too far ahead', function (string $granularity, int $horizon) {
        $this->actingAs($this->manager)->post(route('forecasts.run'), ['granularity' => $granularity, 'horizon' => $horizon])->assertSessionHasErrors('horizon');

        expect(ForecastRun::count())->toBe(0);
    })->with([
        'weeks, past the limit' => ['week', 27],
        'months, past the limit' => ['month', 13],
        'none at all' => ['week', 0],
        'a negative number' => ['week', -2],
    ]);

    it('is for Owners and Managers only', function () {
        $staff = User::factory()->inventoryStaff()->create();

        $this->actingAs($staff)->post(route('forecasts.run'), ['granularity' => 'week'])->assertForbidden();

        expect(ForecastRun::count())->toBe(0);
    });

    it('sends a guest to log in', function () {
        $this->post(route('forecasts.run'), ['granularity' => 'week'])->assertRedirect(route('login'));

        expect(ForecastRun::count())->toBe(0);
    });

    it('lets the Owner start one too, and records them', function () {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post(route('forecasts.run'), ['granularity' => 'week'])->assertSessionHasNoErrors();

        expect(ForecastRun::first()->triggered_by)->toBe($owner->id);
    });
});

describe('the accuracy page', function () {
    it('says there is nothing to measure before the first run', function () {
        $this->actingAs($this->manager)->get(route('forecasts.accuracy'))
            ->assertInertia(fn ($page) => $page
                ->component('forecasts/accuracy')
                ->where('run', null)
                ->where('runs', [])
                ->where('trend', []));
    });

    it('gives the latest run\'s accuracy against the yardsticks', function () {
        $run = completedRun();

        $this->actingAs($this->manager)->get(route('forecasts.accuracy'))
            ->assertInertia(fn ($page) => $page
                ->where('run.id', $run->id)
                ->where('run.metrics.wape', 16)
                ->where('run.metrics.coverage', 70)
                ->where('run.baseline_metrics.seasonal_naive.wape', 20)
                ->where('run.baseline_metrics.moving_average.wape', 18)
                ->where('run.headline.model', 16)
                ->where('run.backtest_folds', 3));
    });

    it('breaks accuracy down by category', function () {
        completedRun();

        $this->actingAs($this->manager)->get(route('forecasts.accuracy'))
            ->assertInertia(fn ($page) => $page
                ->has('run.per_category', 1)
                ->where('run.per_category.0.name', 'Hardware')
                ->where('run.per_category.0.model.wape', 15)
                ->where('run.per_category.0.seasonal_naive.wape', 19)
                ->where('run.per_category.0.moving_average.wape', 17));
    });

    it('lists what the model leaned on, the most important dozen', function () {
        completedRun(['feature_importance' => array_map(fn (int $i) => ['feature' => "feature_{$i}", 'importance' => 1 / ($i + 1)], range(1, 20))]);

        $this->actingAs($this->manager)->get(route('forecasts.accuracy'))
            ->assertInertia(fn ($page) => $page->has('run.feature_importance', 12)->where('run.feature_importance.0.feature', 'feature_1'));
    });

    it('lists past runs, newest first', function () {
        $first = completedRun(['finished_at' => '2026-09-01 02:00:00']);
        $second = completedRun(['finished_at' => '2026-09-08 02:00:00']);

        $this->actingAs($this->manager)->get(route('forecasts.accuracy'))
            ->assertInertia(fn ($page) => $page
                ->where('runs', fn ($rows) => $rows->pluck('id')->all() === [$second->id, $first->id])
                ->where('runs.0.wape', 16)
                ->where('runs.0.model_version', 'xgb-week-20261001T000000Z'));
    });

    it('can show an earlier run', function () {
        $first = completedRun(['metrics' => ['mae' => 1, 'rmse' => 2, 'mape' => 3, 'wape' => 12.5, 'n' => 10, 'coverage' => 80]]);
        completedRun();

        $this->actingAs($this->manager)->get(route('forecasts.accuracy', ['run' => $first->id]))
            ->assertInertia(fn ($page) => $page->where('run.id', $first->id)->where('run.metrics.wape', 12.5));
    });

    it('falls back to the latest run for a run that does not exist or is not complete or is another granularity', function () {
        $good = completedRun();
        $failed = ForecastRun::create(['granularity' => 'week', 'horizon' => 8, 'status' => 'failed', 'location_id' => Location::defaultLocation()->id]);
        $monthly = completedRun(['granularity' => 'month', 'horizon' => 3]);

        foreach ([999999, $failed->id, $monthly->id] as $id) {
            $this->actingAs($this->manager)->get(route('forecasts.accuracy', ['run' => $id]))
                ->assertInertia(fn ($page) => $page->where('run.id', $good->id));
        }
    });

    it('charts accuracy over time, oldest first, leaving out runs that could not be measured', function () {
        $first = completedRun(['as_of' => '2026-08-31', 'metrics' => ['mae' => 1, 'rmse' => 1, 'mape' => 1, 'wape' => 18, 'n' => 5, 'coverage' => 70]]);
        $unmeasured = completedRun(['as_of' => '2026-09-07', 'metrics' => null, 'baseline_metrics' => null]);
        $third = completedRun(['as_of' => '2026-09-14']);

        $this->actingAs($this->manager)->get(route('forecasts.accuracy'))
            ->assertInertia(fn ($page) => $page
                ->where('trend', fn ($rows) => $rows->pluck('run')->all() === [$first->id, $third->id])
                ->where('trend.0', ['run' => $first->id, 'date' => '2026-08-31', 'model' => 18, 'seasonal_naive' => 20, 'moving_average' => 18])
                ->where('trend.1.model', 16));

        expect($unmeasured->exists)->toBeTrue();
    });

    it('copes with a run whose accuracy could not be measured', function () {
        completedRun(['metrics' => null, 'baseline_metrics' => null, 'per_category_metrics' => null, 'feature_importance' => null]);

        $this->actingAs($this->manager)->get(route('forecasts.accuracy'))
            ->assertInertia(fn ($page) => $page
                ->where('run.metrics', null)
                ->where('run.headline', null)
                ->where('run.per_category', [])
                ->where('run.feature_importance', []));
    });

    it('keeps weekly and monthly apart', function () {
        completedRun();
        $monthly = completedRun(['granularity' => 'month', 'horizon' => 3]);

        $this->actingAs($this->manager)->get(route('forecasts.accuracy', ['granularity' => 'month']))
            ->assertInertia(fn ($page) => $page->where('granularity', 'month')->where('run.id', $monthly->id)->has('runs', 1));
    });
});

describe('the sidebar', function () {
    it('shares what each role may do, so the menu can show Forecasts to those who may look', function () {
        $staff = User::factory()->inventoryStaff()->create();

        $this->actingAs($this->manager)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($permissions) => $permissions->contains('forecasts.view')));

        $this->actingAs($staff)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($permissions) => ! $permissions->contains('forecasts.view')));
    });
});
