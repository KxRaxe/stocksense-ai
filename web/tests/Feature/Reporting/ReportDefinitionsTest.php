<?php

use App\Enums\ForecastGranularity;
use App\Models\Category;
use App\Models\Product;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\Replenishment\RecommendationDecisions;
use App\Services\Replenishment\RecommendationGenerator;
use App\Services\Reporting\Reports\ForecastAccuracyReport;
use App\Services\Reporting\Reports\InventoryReport;
use App\Services\Reporting\Reports\ReplenishmentHistoryReport;
use App\Services\Reporting\Reports\Report;
use App\Services\Reporting\Reports\ReportColumn;
use App\Services\Reporting\Reports\ReportFilters;
use App\Services\Reporting\Reports\ReportRegistry;
use App\Services\Reporting\Reports\ReportResult;
use App\Services\Reporting\Reports\SalesReport;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(testToday()->setTime(8, 0));   // Monday 5 October 2026
});

/**
 * Runs a report as the page would, from a query string.
 *
 * @param  array<string, mixed>  $query
 */
function runReport(Report $report, array $query = []): ReportResult
{
    [$filters] = ReportFilters::fromInput($query, $report, testToday());

    return $report->run($filters);
}

describe('the registry', function () {
    it('has the four reports', function () {
        expect(app(ReportRegistry::class)->all()->keys()->all())->toBe(['sales', 'inventory', 'forecast-accuracy', 'replenishment']);
    });

    it('finds a report by its key, or says there is none', function () {
        expect(app(ReportRegistry::class)->find('sales'))->toBeInstanceOf(SalesReport::class)
            ->and(app(ReportRegistry::class)->find('nonsense'))->toBeNull();
    });

    it('gives each role the reports it may open', function () {
        $registry = app(ReportRegistry::class);

        expect($registry->availableTo(User::factory()->owner()->create())->keys()->all())->toBe(['sales', 'inventory', 'forecast-accuracy', 'replenishment'])
            ->and($registry->availableTo(User::factory()->manager()->create())->keys()->all())->toBe(['sales', 'inventory', 'forecast-accuracy', 'replenishment'])
            ->and($registry->availableTo(User::factory()->inventoryStaff()->create())->keys()->all())->toBe(['inventory'])
            ->and($registry->availableTo(User::factory()->create())->keys()->all())->toBe([]);
    });

    it('describes every report, with unique keys', function () {
        $reports = app(ReportRegistry::class)->all();

        foreach ($reports as $report) {
            expect($report->title())->not->toBe('')->and($report->description())->not->toBe('');
        }

        expect($reports->count())->toBe(count(array_unique($reports->map->key()->values()->all())));
    });

    it('builds every column and row with matching keys', function () {
        smallShop();
        completedRun();

        foreach (app(ReportRegistry::class)->all() as $report) {
            $result = runReport($report);
            $keys = array_map(fn (ReportColumn $column) => $column->key, $result->columns);

            foreach ($result->rows as $row) {
                expect(array_keys($row))->toBe($keys);
            }
        }
    });
});

describe('filters', function () {
    it('use the report\'s default when none are given', function () {
        [$filters, $errors] = ReportFilters::fromInput([], app(SalesReport::class), testToday());

        expect($filters->from->toDateString())->toBe('2026-09-06')
            ->and($filters->to->toDateString())->toBe('2026-10-05')
            ->and($filters->categoryId)->toBeNull()
            ->and($filters->granularity)->toBe(ForecastGranularity::Week)
            ->and($errors)->toBe([]);
    });

    it('take a period, category and granularity the report understands', function () {
        $category = Category::factory()->create();

        [$filters, $errors] = ReportFilters::fromInput(['from' => '2026-01-01', 'to' => '2026-03-31', 'category' => (string) $category->id, 'granularity' => 'month'], app(ForecastAccuracyReport::class), testToday());

        expect($filters->from->toDateString())->toBe('2026-01-01')
            ->and($filters->to->toDateString())->toBe('2026-03-31')
            ->and($filters->categoryId)->toBe($category->id)
            ->and($filters->granularity)->toBe(ForecastGranularity::Month)
            ->and($errors)->toBe([]);
    });

    it('ignore what the report does not understand', function () {
        [$filters] = ReportFilters::fromInput(['from' => '2020-01-01', 'to' => '2020-02-01', 'granularity' => 'month'], app(InventoryReport::class), testToday());

        expect($filters->from->toDateString())->toBe('2026-10-05')
            ->and($filters->to->toDateString())->toBe('2026-10-05')
            ->and($filters->granularity)->toBe(ForecastGranularity::Week);
    });

    it('fall back to the default and say what was wrong', function (array $input, string $field, string $message) {
        [$filters, $errors] = ReportFilters::fromInput($input, app(SalesReport::class), testToday());

        expect($errors)->toHaveKey($field)
            ->and($errors[$field])->toContain($message)
            ->and($filters->from->toDateString())->toBe('2026-09-06')
            ->and($filters->to->toDateString())->toBe('2026-10-05');
    })->with([
        'a start that is not a date' => [['from' => 'garbage'], 'from', 'must be a date'],
        'an end that is not a date' => [['to' => '31/12/2026'], 'to', 'must be a date'],
        'an end before the start' => [['from' => '2026-10-01', 'to' => '2026-09-01'], 'to', 'cannot be before'],
        'more than three years' => [['from' => '2022-01-01', 'to' => '2026-01-01'], 'to', 'at most three years'],
    ]);

    it('drop a category that does not exist', function () {
        [$filters, $errors] = ReportFilters::fromInput(['category' => '9999'], app(SalesReport::class), testToday());

        expect($filters->categoryId)->toBeNull()->and($errors['category'])->toBe('That category does not exist.');
    });

    it('drop a granularity that does not exist', function () {
        [$filters, $errors] = ReportFilters::fromInput(['granularity' => 'daily'], app(ForecastAccuracyReport::class), testToday());

        expect($filters->granularity)->toBe(ForecastGranularity::Week)->and($errors)->toHaveKey('granularity');
    });

    it('keep a good value when another is bad', function () {
        [$filters, $errors] = ReportFilters::fromInput(['from' => '2026-09-20', 'category' => '9999'], app(SalesReport::class), testToday());

        expect($filters->from->toDateString())->toBe('2026-09-20')->and($errors)->toHaveKey('category');
    });

    it('allow a single day', function () {
        [$filters, $errors] = ReportFilters::fromInput(['from' => '2026-10-01', 'to' => '2026-10-01'], app(SalesReport::class), testToday());

        expect($filters->from->toDateString())->toBe('2026-10-01')->and($filters->to->toDateString())->toBe('2026-10-01')->and($errors)->toBe([]);
    });

    it('are described for the top of an export', function () {
        $hardware = Category::factory()->create(['name' => 'Hardware']);

        [$all] = ReportFilters::fromInput([], app(SalesReport::class), testToday());
        [$one] = ReportFilters::fromInput(['category' => (string) $hardware->id], app(SalesReport::class), testToday());
        [$accuracy] = ReportFilters::fromInput(['granularity' => 'month'], app(ForecastAccuracyReport::class), testToday());
        [$stock] = ReportFilters::fromInput([], app(InventoryReport::class), testToday());

        expect($all->describe(app(SalesReport::class)))->toBe('6 Sep 2026 to 5 Oct 2026 · All categories')
            ->and($one->describe(app(SalesReport::class)))->toBe('6 Sep 2026 to 5 Oct 2026 · Category: Hardware')
            ->and($accuracy->describe(app(ForecastAccuracyReport::class)))->toBe('8 Jul 2026 to 5 Oct 2026 · Monthly forecasts · All categories')
            ->and($stock->describe(app(InventoryReport::class)))->toBe('All categories');
    });

    it('become a query string for the export links', function () {
        $hardware = Category::factory()->create();

        [$filters] = ReportFilters::fromInput(['from' => '2026-09-01', 'to' => '2026-09-30', 'category' => (string) $hardware->id], app(SalesReport::class), testToday());

        expect($filters->toQuery(app(SalesReport::class)))->toBe(['from' => '2026-09-01', 'to' => '2026-09-30', 'category' => $hardware->id]);
    });
});

describe('the sales report', function () {
    it('lists what each product sold, most revenue first, with its share', function () {
        smallShop();

        $result = runReport(app(SalesReport::class));

        expect($result->rows)->toBe([
            ['sku' => 'F-1', 'name' => 'Rice', 'category' => 'Food', 'unit' => 'pc', 'units' => 30, 'revenue' => 1500.0, 'average_price' => 50.0, 'share' => 65.2],
            ['sku' => 'H-1', 'name' => 'Nails', 'category' => 'Hardware', 'unit' => 'pc', 'units' => 100, 'revenue' => 800.0, 'average_price' => 8.0, 'share' => 34.8],
        ])
            ->and(array_map(fn ($column) => $column->type, $result->columns))->toBe(['text', 'text', 'text', 'text', 'integer', 'money', 'money', 'percent']);
    });

    it('adds up the total and the headline figures', function () {
        smallShop();

        $result = runReport(app(SalesReport::class));

        expect($result->totals)->toBe(['name' => 'Total', 'units' => 130, 'revenue' => 2300.0, 'share' => 100.0])
            ->and(collect($result->summary)->pluck('value', 'label')->all())->toBe([
                'Revenue' => 2300.0,
                'Units sold' => 130,
                'Products sold' => 2,
                'Best seller' => 'Rice',
            ]);
    });

    it('covers only the period asked for', function () {
        smallShop();

        $result = runReport(app(SalesReport::class), ['from' => '2026-08-01', 'to' => '2026-08-31']);

        expect(collect($result->rows)->pluck('name')->all())->toBe(['Rice'])
            ->and($result->rows[0]['units'])->toBe(20)
            ->and($result->rows[0]['share'])->toBe(100.0);
    });

    it('narrows to a category', function () {
        smallShop();

        $result = runReport(app(SalesReport::class), ['category' => (string) Category::where('name', 'Hardware')->value('id')]);

        expect(collect($result->rows)->pluck('name')->all())->toBe(['Nails'])
            ->and($result->totals['revenue'])->toBe(800.0)
            ->and($result->rows[0]['share'])->toBe(100.0);
    });

    it('says how many active products sold nothing', function () {
        smallShop();

        $notes = runReport(app(SalesReport::class))->notes;

        expect(implode(' ', $notes))->toContain('3 active products had no sales in this period');
    });

    it('does not count archived products as quiet', function () {
        smallShop();
        Product::whereIn('sku', ['F-2', 'F-3'])->update(['is_active' => false]);

        expect(implode(' ', runReport(app(SalesReport::class))->notes))->toContain('1 active product had no sales');
    });

    it('is empty, without a totals row, when nothing sold', function () {
        $result = runReport(app(SalesReport::class));

        expect($result->rows)->toBe([])
            ->and($result->totals)->toBe([])
            ->and(collect($result->summary)->pluck('value', 'label')['Best seller'])->toBe('-');
    });

    it('breaks ties by name, so the order is the same every time', function () {
        $category = Category::factory()->create();

        foreach (['Delta', 'Alpha', 'Charlie', 'Bravo'] as $name) {
            sell(Product::factory()->for($category)->create(['name' => $name]), [['2026-10-01', 1, 10]]);
        }

        expect(collect(runReport(app(SalesReport::class))->rows)->pluck('name')->all())->toBe(['Alpha', 'Bravo', 'Charlie', 'Delta']);
    });
});

describe('the inventory report', function () {
    it('lists every active product with its stock and what it is worth', function () {
        smallShop();

        $rows = collect(runReport(app(InventoryReport::class))->rows)->keyBy('name');

        expect($rows->keys()->all())->toBe(['Rice', 'Salt', 'Soap', 'Nails', 'Paint'])    // by category, then name
            ->and($rows['Rice'])->toMatchArray(['sku' => 'F-1', 'category' => 'Food', 'on_hand' => 100, 'on_order' => 0, 'status' => 'In stock', 'unit_cost' => 40.0, 'stock_value' => 4000.0])
            ->and($rows['Nails']['stock_value'])->toBe(2500.0)
            ->and($rows['Paint']['stock_value'])->toBe(1000.0);
    });

    it('flags what is out of stock', function () {
        smallShop();

        $salt = collect(runReport(app(InventoryReport::class))->rows)->firstWhere('name', 'Salt');

        expect($salt['on_hand'])->toBe(0)->and($salt['status'])->toBe('Out of stock')->and($salt['stock_value'])->toBe(0.0);
    });

    it('compares stock with the reorder point set on the product', function () {
        smallShop();
        Product::where('sku', 'H-1')->update(['reorder_point_override' => 600]);

        $nails = collect(runReport(app(InventoryReport::class))->rows)->firstWhere('name', 'Nails');

        expect($nails['status'])->toBe('Low stock')->and($nails['reorder_point'])->toBe(600);
    });

    it('adds what the recommendations say', function () {
        smallShop();

        $rows = collect(runReport(app(InventoryReport::class))->rows)->keyBy('name');

        expect($rows['Soap'])->toMatchArray(['risk' => 'Critical', 'reorder_point' => 70, 'days_of_cover' => 1.0])
            ->and($rows['Rice']['risk'])->toBeNull()
            ->and($rows['Rice']['reorder_point'])->toBeNull();
    });

    it('counts on order too', function () {
        smallShop();
        app(RecommendationDecisions::class)->accept(soapRecommendation(), User::factory()->owner()->create());

        $soap = collect(runReport(app(InventoryReport::class))->rows)->firstWhere('name', 'Soap');

        expect($soap['on_order'])->toBe(130);
    });

    it('totals the value and counts what needs attention', function () {
        smallShop();

        $result = runReport(app(InventoryReport::class));

        expect($result->totals)->toBe(['name' => 'Total', 'stock_value' => 7500.0])
            ->and(collect($result->summary)->pluck('value', 'label')->all())->toBe([
                'Stock value (at cost)' => 7500.0,
                'Products' => 5,
                'Out of stock' => 1,
                'Below reorder point' => 0,
                'Need ordering' => 1,
            ]);
    });

    it('leaves out archived products and other categories', function () {
        smallShop();
        Product::where('sku', 'H-2')->update(['is_active' => false]);

        expect(collect(runReport(app(InventoryReport::class))->rows)->pluck('name')->all())->not->toContain('Paint');

        $food = Category::where('name', 'Food')->value('id');

        expect(collect(runReport(app(InventoryReport::class), ['category' => (string) $food])->rows)->pluck('name')->all())->toBe(['Rice', 'Salt', 'Soap']);
    });

    it('has no date to choose: it is as of now', function () {
        expect(app(InventoryReport::class)->filters())->toBe(['category']);
    });
});

describe('the forecast accuracy report', function () {
    it('gives the figures for all products and for each category, run by run', function () {
        completedRun();

        $result = runReport(app(ForecastAccuracyReport::class));

        expect($result->rows)->toBe([
            ['run_date' => '2026-10-01', 'scope' => 'All products', 'mae' => 9.5, 'rmse' => 16.0, 'mape' => 26.0, 'wape' => 16.0, 'naive_wape' => 20.0, 'average_wape' => 18.0, 'observations' => 100],
            ['run_date' => '2026-10-01', 'scope' => 'Hardware', 'mae' => 3.0, 'rmse' => 4.0, 'mape' => 25.0, 'wape' => 15.0, 'naive_wape' => 19.0, 'average_wape' => 17.0, 'observations' => 40],
        ]);
    });

    it('summarises the latest run against last year', function () {
        completedRun(['horizon' => 8]);

        $summary = collect(runReport(app(ForecastAccuracyReport::class))->summary)->pluck('value', 'label');

        expect($summary['Runs in this period'])->toBe(1)
            ->and($summary['Latest typical error (WAPE)'])->toBe(16.0)
            ->and($summary['Same period last year'])->toBe(20.0)
            ->and($summary['Forecast looks ahead'])->toBe('8 weeks');
    });

    it('lists the newest run first', function () {
        completedRun(['finished_at' => '2026-09-10 02:00:00', 'per_category_metrics' => []]);
        completedRun(['finished_at' => '2026-09-24 02:00:00', 'per_category_metrics' => []]);

        expect(collect(runReport(app(ForecastAccuracyReport::class))->rows)->pluck('run_date')->all())->toBe(['2026-09-24', '2026-09-10']);
    });

    it('covers only the runs that finished in the period', function () {
        completedRun(['finished_at' => '2026-08-01 02:00:00']);
        completedRun(['finished_at' => '2026-09-24 02:00:00']);

        $rows = runReport(app(ForecastAccuracyReport::class), ['from' => '2026-09-01', 'to' => '2026-09-30'])->rows;

        expect(collect($rows)->pluck('run_date')->unique()->all())->toBe(['2026-09-24']);
    });

    it('keeps weekly and monthly apart', function () {
        completedRun();
        completedRun(['granularity' => 'month', 'horizon' => 3, 'per_category_metrics' => []]);

        $weekly = runReport(app(ForecastAccuracyReport::class), ['granularity' => 'week']);
        $monthly = runReport(app(ForecastAccuracyReport::class), ['granularity' => 'month']);

        expect($weekly->rows)->toHaveCount(2)
            ->and($monthly->rows)->toHaveCount(1)
            ->and(collect($monthly->summary)->pluck('value', 'label')['Forecast looks ahead'])->toBe('3 months');
    });

    it('narrows to one category, which has no whole-shop row', function () {
        completedRun();
        $hardware = Category::factory()->create(['name' => 'Hardware']);
        Category::factory()->create(['name' => 'Food']);

        $rows = runReport(app(ForecastAccuracyReport::class), ['category' => (string) $hardware->id])->rows;

        expect(collect($rows)->pluck('scope')->all())->toBe(['Hardware']);
    });

    it('ignores runs that did not finish', function () {
        completedRun(['status' => 'failed', 'metrics' => null, 'baseline_metrics' => null, 'per_category_metrics' => null]);

        expect(runReport(app(ForecastAccuracyReport::class))->rows)->toBe([]);
    });

    it('says so when no forecast finished in the period', function () {
        $result = runReport(app(ForecastAccuracyReport::class));

        expect($result->rows)->toBe([])
            ->and(implode(' ', $result->notes))->toContain('No weekly forecast finished in this period.')
            ->and(collect($result->summary)->pluck('value', 'label')['Latest typical error (WAPE)'])->toBeNull();
    });

    it('copes with a run that measured nothing', function () {
        completedRun(['metrics' => null, 'baseline_metrics' => null, 'per_category_metrics' => null]);

        expect(runReport(app(ForecastAccuracyReport::class))->rows)->toBe([
            ['run_date' => '2026-10-01', 'scope' => 'All products', 'mae' => null, 'rmse' => null, 'mape' => null, 'wape' => null, 'naive_wape' => null, 'average_wape' => null, 'observations' => null],
        ]);
    });
});

describe('the replenishment history report', function () {
    /** Three products that all need ordering: the first accepted, the second dismissed, the third left. */
    function threeDecisions(): User
    {
        $category = Category::factory()->create(['name' => 'Hardware']);
        $products = collect(['Alpha', 'Bravo', 'Charlie'])->map(function (string $name, int $n) use ($category) {
            $product = Product::factory()->for($category)->create(['name' => $name, 'sku' => 'S-'.($n + 1), 'lead_time_days' => 7]);
            putInStock($product, 10);

            return $product;
        });

        forecastWith($products->mapWithKeys(fn (Product $product) => [$product->id => 70])->all());
        app(RecommendationGenerator::class)->generate(testToday());

        $manager = User::factory()->manager()->create(['name' => 'Pat Manager']);
        $decisions = app(RecommendationDecisions::class);
        $decisions->accept(Recommendation::open()->whereHas('product', fn ($q) => $q->where('name', 'Alpha'))->sole(), $manager, 'Phoned the supplier');
        $decisions->dismiss(Recommendation::open()->whereHas('product', fn ($q) => $q->where('name', 'Bravo'))->sole(), $manager);

        return $manager;
    }

    it('lists each recommendation and what became of it', function () {
        threeDecisions();

        $rows = collect(runReport(app(ReplenishmentHistoryReport::class))->rows)->keyBy('name');

        expect($rows->keys()->sort()->values()->all())->toBe(['Alpha', 'Bravo', 'Charlie'])
            ->and($rows['Alpha'])->toMatchArray([
                'raised_on' => '2026-10-05', 'sku' => 'S-1', 'category' => 'Hardware', 'risk' => 'Critical',
                'recommended_qty' => 130, 'final_qty' => 130, 'status' => 'Accepted', 'decided_by' => 'Pat Manager', 'note' => 'Phoned the supplier',
            ])
            ->and($rows['Alpha']['decided_at'])->not->toBeNull()
            ->and($rows['Bravo'])->toMatchArray(['status' => 'Dismissed', 'final_qty' => null, 'decided_by' => 'Pat Manager'])
            ->and($rows['Charlie'])->toMatchArray(['status' => 'Needs a decision', 'final_qty' => null, 'decided_by' => null, 'decided_at' => null]);
    });

    it('counts the outcomes', function () {
        threeDecisions();

        expect(collect(runReport(app(ReplenishmentHistoryReport::class))->summary)->pluck('value', 'label')->all())->toBe([
            'Recommendations' => 3,
            'Accepted' => 1,
            'Dismissed' => 1,
            'Waiting for a decision' => 1,
            'Accepted, of those decided' => 50.0,
        ]);
    });

    it('counts a changed quantity and a cancelled order as accepted', function () {
        $manager = threeDecisions();
        $decisions = app(RecommendationDecisions::class);

        $decisions->adjust(Recommendation::open()->whereHas('product', fn ($q) => $q->where('name', 'Charlie'))->sole(), $manager, 200);
        $decisions->cancel(Recommendation::where('status', 'accepted')->sole(), $manager);

        $summary = collect(runReport(app(ReplenishmentHistoryReport::class))->summary)->pluck('value', 'label');

        expect($summary['Accepted'])->toBe(2)
            ->and($summary['Accepted, of those decided'])->toBe(66.7);
    });

    it('has no acceptance rate when nothing has been decided', function () {
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create(['lead_time_days' => 7]);
        putInStock($product, 10);
        forecastWith([$product->id => 70]);
        app(RecommendationGenerator::class)->generate(testToday());

        expect(collect(runReport(app(ReplenishmentHistoryReport::class))->summary)->pluck('value', 'label')['Accepted, of those decided'])->toBeNull();
    });

    it('covers only what was raised in the period', function () {
        threeDecisions();

        expect(runReport(app(ReplenishmentHistoryReport::class), ['from' => '2026-10-06', 'to' => '2026-10-10'])->rows)->toBe([])
            ->and(runReport(app(ReplenishmentHistoryReport::class), ['from' => '2026-10-05', 'to' => '2026-10-05'])->rows)->toHaveCount(3);
    });

    it('narrows to a category', function () {
        threeDecisions();

        expect(runReport(app(ReplenishmentHistoryReport::class), ['category' => (string) Category::factory()->create()->id])->rows)->toBe([]);
    });

    it('leaves out overstock notices, which are only information', function () {
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create(['lead_time_days' => 7]);
        putInStock($product, 9000);
        forecastWith([$product->id => 10]);
        app(RecommendationGenerator::class)->generate(testToday());

        expect(Recommendation::count())->toBe(1)
            ->and(runReport(app(ReplenishmentHistoryReport::class))->rows)->toBe([]);
    });

    it('lists the newest first', function () {
        threeDecisions();
        $this->travelTo(testToday()->addDay()->setTime(8, 0));

        $product = Product::factory()->for(Category::first())->create(['name' => 'Later', 'sku' => 'S-9', 'lead_time_days' => 7]);
        putInStock($product, 10);
        forecastWith([$product->id => 70]);
        app(RecommendationGenerator::class)->generate(CarbonImmutable::parse('2026-10-06'));

        $rows = runReport(app(ReplenishmentHistoryReport::class), ['to' => '2026-10-06'])->rows;

        expect($rows[0]['name'])->toBe('Later')->and($rows[0]['raised_on'])->toBe('2026-10-06');
    });
});
