<?php

use App\Enums\Role;
use App\Models\Category;
use App\Models\ForecastRun;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(testToday()->setTime(8, 0));   // Monday 5 October 2026

    $this->owner = User::factory()->owner()->create();
});

describe('sales figures', function () {
    it('totals the last 30 days and compares them with the 30 before', function () {
        smallShop();

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('period', ['from' => '2026-09-06', 'to' => '2026-10-05', 'days' => 30])
                ->where('sales', [
                    'revenue' => 2300,
                    'units' => 130,
                    'previous_revenue' => 1000,
                    'previous_units' => 20,
                    'revenue_change' => 130,
                    'units_change' => 550,
                ]));
    });

    it('has nothing to compare with when the 30 before were quiet', function () {
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create();
        sell($product, [['2026-10-01', 4, 25]]);

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('sales', fn ($sales) => $sales['revenue'] === 100
                && $sales['previous_revenue'] === 0
                && $sales['revenue_change'] === null
                && $sales['units_change'] === null));
    });

    it('counts the first and the last day, and nothing outside', function () {
        $product = Product::factory()->for(Category::factory()->create())->create();
        sell($product, [
            ['2026-09-05', 1000, 1],   // the day before the 30 days
            ['2026-09-06', 1, 1],      // the first day
            ['2026-10-05', 2, 1],      // today
            ['2026-10-06', 1000, 1],   // tomorrow
        ]);

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('sales.units', 3));
    });

    it('is all zeros, not an error, for a shop with no sales', function () {
        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('sales', ['revenue' => 0, 'units' => 0, 'previous_revenue' => 0, 'previous_units' => 0, 'revenue_change' => null, 'units_change' => null])
                ->where('categories', [])
                ->where('top_movers', [])
                ->where('slow_movers', [])
                ->where('alerts', [])
                ->where('forecast.has_run', false));
    });
});

describe('stock figures', function () {
    it('values the stock on hand at cost and at the shelf price', function () {
        smallShop();

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('stock', [
                'cost_value' => 7500,
                'retail_value' => 10500,
                'products' => 5,
                'in_stock' => 4,
                'out_of_stock' => 1,
            ]));
    });

    it('leaves archived products out', function () {
        smallShop();
        Product::where('sku', 'H-2')->update(['is_active' => false]);

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('stock.cost_value', 6500)->where('stock.products', 4));
    });
});

describe('the sales trend', function () {
    it('gives revenue for each of the last 26 whole weeks, not the week still going', function () {
        smallShop();

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('sales_trend', function ($weeks) {
                $weeks = collect($weeks);
                $byWeek = $weeks->keyBy('period');

                return $weeks->count() === 26
                    && $weeks->first()['period'] === '2026-04-06'
                    && $weeks->last()['period'] === '2026-09-28'
                    && $byWeek['2026-09-28']['revenue'] === 1000
                    && $byWeek['2026-09-28']['units'] === 20
                    && $byWeek['2026-09-14']['revenue'] === 800
                    && $byWeek['2026-09-07']['revenue'] === 500
                    && $byWeek['2026-09-21']['revenue'] === 0     // a quiet week is zero, not missing
                    && ! $byWeek->has('2026-10-05');              // this week is still going
            }));
    });
});

describe('categories', function () {
    it('shares the revenue between them, biggest first', function () {
        smallShop();

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('categories', [
                ['category_id' => Category::where('name', 'Food')->value('id'), 'category' => 'Food', 'revenue' => 1500, 'units' => 30, 'share' => 65.2],
                ['category_id' => Category::where('name', 'Hardware')->value('id'), 'category' => 'Hardware', 'revenue' => 800, 'units' => 100, 'share' => 34.8],
            ]));
    });
});

describe('movers', function () {
    it('lists what sold the most units', function () {
        smallShop();

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('top_movers', fn ($rows) => collect($rows)->pluck('name')->all() === ['Nails', 'Rice']
                && $rows[0]['units'] === 100
                && $rows[0]['revenue'] === 800
                && $rows[0]['sku'] === 'H-1'));
    });

    it('lists what has stock but sells least, never-sold first and the most money on the shelf first among those', function () {
        smallShop();

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('slow_movers', fn ($rows) => collect($rows)->pluck('name')->all() === ['Paint', 'Soap', 'Rice', 'Nails']
                && $rows[0]['units'] === 0
                && $rows[0]['on_hand'] === 10
                && $rows[0]['stock_value'] === 1000
                && $rows[2]['units'] === 30));
    });

    it('leaves out products with no stock, which cannot be slow stock', function () {
        smallShop();

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('slow_movers', fn ($rows) => ! collect($rows)->pluck('name')->contains('Salt')));
    });

    it('shows at most five', function () {
        $category = Category::factory()->create();

        foreach (range(1, 8) as $n) {
            $product = Product::factory()->for($category)->create(['sku' => "P-{$n}"]);
            putInStock($product, 10);
            sell($product, [['2026-10-01', $n, 10]]);
        }

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->has('top_movers', 5)->has('slow_movers', 5));
    });
});

describe('what needs attention', function () {
    it('counts the products at each level of risk', function () {
        smallShop();

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('risk', ['critical' => 1, 'low' => 0, 'watch' => 0, 'needs_attention' => 1]));
    });

    it('lists the most urgent products with how much to order', function () {
        smallShop();

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('alerts', fn ($alerts) => count($alerts) === 1
                && $alerts[0]['product']['name'] === 'Soap'
                && $alerts[0]['risk'] === 'critical'
                && $alerts[0]['risk_label'] === 'Critical'
                && $alerts[0]['on_hand'] === 10
                && $alerts[0]['lead_time_demand'] === 70
                && $alerts[0]['recommended_qty'] === 130));
    });
});

describe('the forecast', function () {
    it('gives the accuracy of the latest weekly run', function () {
        smallShop();

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('forecast.has_run', true)
                ->where('forecast.granularity', 'week')
                ->where('forecast.accuracy', ['model' => 16, 'seasonal_naive' => 20, 'moving_average' => 18])
                ->where('forecast.age_days', 4)
                ->where('forecast.stale', false));
    });

    it('warns when the forecast is old', function () {
        smallShop();
        ForecastRun::query()->update(['finished_at' => testToday()->subDays(30)]);

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('forecast.stale', true)->where('forecast.age_days', 30));
    });

    it('adds up what is expected for all products, week by week, with the range', function () {
        smallShop();

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('forecast.forecast', fn ($weeks) => count($weeks) === 8
                && $weeks[0] === ['period' => '2026-10-05', 'yhat' => 100, 'lower' => 80, 'upper' => 120]
                && $weeks[7]['period'] === '2026-11-23'));
    });

    it('shows the sales leading up to it, week by week', function () {
        smallShop();

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('forecast.history', function ($weeks) {
                $weeks = collect($weeks)->keyBy('period');

                return $weeks->count() === 26
                    && $weeks->keys()->last() === '2026-09-28'
                    && $weeks['2026-09-28']['qty'] === 20
                    && $weeks['2026-09-14']['qty'] === 100;
            }));
    });

    it('says there is no forecast yet, rather than leaving it out', function () {
        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('forecast', fn ($forecast) => $forecast['has_run'] === false
                && $forecast['accuracy'] === null
                && $forecast['history'] === []
                && $forecast['forecast'] === []));
    });
});

describe('what each role is shown', function () {
    it('shows an Owner and a Manager everything', function (Role $role) {
        smallShop();

        $this->actingAs(User::factory()->withRole($role)->create())->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->whereNot('sales', null)->whereNot('sales_trend', null)->whereNot('categories', null)
                ->whereNot('top_movers', null)->whereNot('slow_movers', null)->whereNot('stock', null)
                ->whereNot('risk', null)->whereNot('alerts', null)->whereNot('forecast', null));
    })->with([Role::Owner, Role::Manager]);

    it('keeps the forecast from inventory staff, who cannot see forecasts', function () {
        smallShop();

        $this->actingAs(User::factory()->inventoryStaff()->create())->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('forecast', null)
                ->whereNot('sales', null)->whereNot('stock', null)->whereNot('alerts', null));
    });

    it('shows someone with no role nothing but the page', function () {
        smallShop();

        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sales', null)->where('sales_trend', null)->where('categories', null)
                ->where('top_movers', null)->where('slow_movers', null)->where('stock', null)
                ->where('risk', null)->where('alerts', null)->where('forecast', null));
    });
});
