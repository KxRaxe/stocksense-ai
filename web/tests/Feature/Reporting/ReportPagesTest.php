<?php

use App\Enums\Role;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Replenishment\RecommendationDecisions;
use App\Services\Reporting\Reports\Exports\ReportPdf;
use App\Services\Reporting\Reports\ReportColumn;
use App\Services\Reporting\Reports\ReportResult;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(testToday()->setTime(8, 0));   // Monday 5 October 2026

    $this->owner = User::factory()->owner()->create(['name' => 'Olive Owner']);
    $this->manager = User::factory()->manager()->create();
    $this->staff = User::factory()->inventoryStaff()->create();
});

/**
 * The spreadsheet inside a downloaded response.
 */
function workbook(TestResponse $response): Spreadsheet
{
    return IOFactory::load($response->baseResponse->getFile()->getPathname());
}

describe('who may open which report', function () {
    it('sends guests to log in', function (string $route) {
        $this->get($route === 'index' ? route('reports.index') : route('reports.show', 'sales'))->assertRedirect(route('login'));
        $this->get(route('reports.export', ['sales', 'xlsx']))->assertRedirect(route('login'));
    })->with(['index', 'show']);

    it('lets every role with a report open the list', function (Role $role) {
        $this->actingAs(User::factory()->withRole($role)->create())->get(route('reports.index'))->assertOk();
    })->with(Role::cases());

    it('turns away someone who may open no report', function () {
        $this->actingAs(User::factory()->create())->get(route('reports.index'))->assertForbidden();
    });

    it('lists only what the person may open', function () {
        $this->actingAs($this->staff)->get(route('reports.index'))
            ->assertInertia(fn ($page) => $page
                ->component('reports/index')
                ->where('reports', fn ($reports) => $reports->pluck('key')->all() === ['inventory']));

        $this->actingAs($this->manager)->get(route('reports.index'))
            ->assertInertia(fn ($page) => $page->where('reports', fn ($reports) => $reports->pluck('key')->all() === ['sales', 'inventory', 'forecast-accuracy', 'replenishment']
                && $reports[0]['title'] === 'Sales'
                && $reports[0]['filters'] === ['date', 'category']
                && $reports[1]['filters'] === ['category']));
    });

    it('lets each role open the reports it has the permission for, on screen and as a download', function (string $report, string $format, bool $owner, bool $manager, bool $staff) {
        foreach ([[$this->owner, $owner], [$this->manager, $manager], [$this->staff, $staff]] as [$user, $allowed]) {
            $show = $this->actingAs($user)->get(route('reports.show', $report));
            $export = $this->actingAs($user)->get(route('reports.export', [$report, $format]));

            $allowed ? $show->assertOk() : $show->assertForbidden();
            $allowed ? $export->assertOk() : $export->assertForbidden();
        }
    })->with([
        'sales as Excel' => ['sales', 'xlsx', true, true, false],
        'sales as PDF' => ['sales', 'pdf', true, true, false],
        'inventory as Excel' => ['inventory', 'xlsx', true, true, true],
        'inventory as PDF' => ['inventory', 'pdf', true, true, true],
        'accuracy as Excel' => ['forecast-accuracy', 'xlsx', true, true, false],
        'replenishment as PDF' => ['replenishment', 'pdf', true, true, false],
    ]);

    it('does not find a report that does not exist', function () {
        $this->actingAs($this->owner)->get(route('reports.show', 'nonsense'))->assertNotFound();
        $this->actingAs($this->owner)->get('/reports/nonsense/export/xlsx')->assertNotFound();
    });

    it('does not offer other file types', function () {
        $this->actingAs($this->owner)->get('/reports/sales/export/csv')->assertNotFound();
        $this->actingAs($this->owner)->get('/reports/sales/export/docx')->assertNotFound();
    });
});

describe('on screen', function () {
    it('shows the report with its table, figures and exports', function () {
        smallShop();

        $this->actingAs($this->owner)->get(route('reports.show', 'sales'))
            ->assertInertia(fn ($page) => $page
                ->component('reports/show')
                ->where('report', ['key' => 'sales', 'title' => 'Sales', 'description' => 'Units and revenue for each product over a period, with its share of the total.', 'filters' => ['date', 'category']])
                ->where('reports', fn ($reports) => $reports->pluck('key')->all() === ['sales', 'inventory', 'forecast-accuracy', 'replenishment'])
                ->where('filters', ['from' => '2026-09-06', 'to' => '2026-10-05', 'category' => null, 'granularity' => 'week'])
                ->where('filter_errors', [])
                ->where('subtitle', '6 Sep 2026 to 5 Oct 2026 · All categories')
                ->where('columns.0', ['key' => 'sku', 'label' => 'SKU', 'type' => 'text'])
                ->where('columns.5', ['key' => 'revenue', 'label' => 'Revenue', 'type' => 'money'])
                ->where('rows.total', 2)
                ->where('rows.data.0.name', 'Rice')
                ->where('rows.data.0.revenue', 1500)
                ->where('summary.0', ['label' => 'Revenue', 'value' => 2300, 'type' => 'money'])
                ->where('totals', fn ($totals) => $totals['revenue'] === 2300 && $totals['name'] === 'Total')
                ->where('notes.0', fn ($note) => str_contains($note, 'rough size'))
                ->where('categories', fn ($categories) => $categories->pluck('name')->all() === ['Food', 'Hardware'])
                ->where('exports', fn ($exports) => $exports->pluck('format')->all() === ['xlsx', 'pdf']
                    && $exports->pluck('label')->all() === ['Excel', 'PDF']
                    && $exports[0]['url'] === route('reports.export', ['sales', 'xlsx', 'from' => '2026-09-06', 'to' => '2026-10-05'])));
    });

    it('narrows by the filters in the address, and carries them to the exports', function () {
        smallShop();
        $hardware = Category::where('name', 'Hardware')->value('id');

        $this->actingAs($this->owner)->get(route('reports.show', ['sales', 'from' => '2026-09-01', 'to' => '2026-09-30', 'category' => $hardware]))
            ->assertInertia(fn ($page) => $page
                ->where('filters.category', $hardware)
                ->where('subtitle', '1 Sep 2026 to 30 Sep 2026 · Category: Hardware')
                ->where('rows.total', 1)
                ->where('rows.data.0.name', 'Nails')
                ->where('exports.1.url', route('reports.export', ['sales', 'pdf', 'from' => '2026-09-01', 'to' => '2026-09-30', 'category' => $hardware])));
    });

    it('says what was wrong with the filters and shows the default instead', function () {
        smallShop();

        $this->actingAs($this->owner)->get(route('reports.show', ['sales', 'from' => 'garbage', 'category' => 9999]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filter_errors.from', 'The start date must be a date such as 2026-09-01.')
                ->where('filter_errors.category', 'That category does not exist.')
                ->where('filters.from', '2026-09-06')
                ->where('rows.total', 2));
    });

    it('pages through a long report, keeping the filters', function () {
        $category = Category::factory()->create();

        foreach (range(1, 30) as $n) {
            sell(Product::factory()->for($category)->create(['name' => sprintf('Product %02d', $n), 'sku' => "P-{$n}"]), [['2026-10-01', 1, $n]]);
        }

        $this->actingAs($this->owner)->get(route('reports.show', 'sales'))
            ->assertInertia(fn ($page) => $page
                ->where('rows.total', 30)
                ->where('rows.per_page', 25)
                ->where('rows.last_page', 2)
                ->has('rows.data', 25)
                ->where('rows.next_page_url', fn ($url) => str_contains($url, 'page=2')));

        $this->actingAs($this->owner)->get(route('reports.show', ['sales', 'page' => 2, 'category' => $category->id]))
            ->assertInertia(fn ($page) => $page
                ->has('rows.data', 5)
                ->where('rows.from', 26)
                ->where('rows.prev_page_url', fn ($url) => str_contains($url, 'category='.$category->id)));
    });

    it('shows an empty report, not an error, when there is nothing to report', function () {
        $this->actingAs($this->owner)->get(route('reports.show', 'sales'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('rows.total', 0)->where('totals', null));
    });

    it('shows the inventory report as of now, with no dates', function () {
        smallShop();

        $this->actingAs($this->staff)->get(route('reports.show', 'inventory'))
            ->assertInertia(fn ($page) => $page
                ->where('report.filters', ['category'])
                ->where('subtitle', 'All categories')
                ->where('rows.total', 5)
                ->where('exports.0.url', route('reports.export', ['inventory', 'xlsx'])));
    });

    it('lets the staff see only the stock report in the report switcher', function () {
        $this->actingAs($this->staff)->get(route('reports.show', 'inventory'))
            ->assertInertia(fn ($page) => $page->where('reports', fn ($reports) => $reports->pluck('key')->all() === ['inventory']));
    });
});

describe('as an Excel file', function () {
    it('downloads with a name that says what and when', function () {
        smallShop();

        $response = $this->actingAs($this->owner)->get(route('reports.export', ['sales', 'xlsx']));

        $response->assertOk()->assertDownload('stocksense-sales-2026-09-06_2026-10-05.xlsx');

        expect($response->headers->get('content-type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    });

    it('is named for the day when the report is as of now', function () {
        smallShop();

        $this->actingAs($this->owner)->get(route('reports.export', ['inventory', 'xlsx']))->assertDownload('stocksense-inventory-2026-10-05.xlsx');
    });

    it('has the table on the first sheet, with real numbers', function () {
        smallShop();

        $book = workbook($this->actingAs($this->owner)->get(route('reports.export', ['sales', 'xlsx'])));
        $sheet = $book->getSheet(0);
        $rows = $sheet->toArray(null, false, false, false);

        expect($sheet->getTitle())->toBe('Sales')
            ->and($rows[0])->toBe(['SKU', 'Product', 'Category', 'Unit', 'Units sold', 'Revenue', 'Average price', 'Share of revenue'])
            ->and($rows[1])->toEqual(['F-1', 'Rice', 'Food', 'pc', 30, 1500, 50, 65.2])
            ->and($rows[2])->toEqual(['H-1', 'Nails', 'Hardware', 'pc', 100, 800, 8, 34.8])
            ->and($rows[3])->toEqual([null, 'Total', null, null, 130, 2300, null, 100])
            ->and($rows)->toHaveCount(4);
    });

    it('formats the cells so they sort and add up', function () {
        smallShop();

        $sheet = workbook($this->actingAs($this->owner)->get(route('reports.export', ['sales', 'xlsx'])))->getSheet(0);

        expect($sheet->getStyle('E2')->getNumberFormat()->getFormatCode())->toBe('#,##0')
            ->and($sheet->getStyle('F2')->getNumberFormat()->getFormatCode())->toBe('"₱"#,##0.00')
            ->and($sheet->getStyle('H2')->getNumberFormat()->getFormatCode())->toBe('0.0"%"')
            ->and($sheet->getStyle('A1')->getFont()->getBold())->toBeTrue()
            ->and($sheet->getStyle('B4')->getFont()->getBold())->toBeTrue()
            ->and($sheet->getFreezePane())->toBe('A2');
    });

    it('writes dates as dates', function () {
        smallShop();
        $manager = $this->manager;
        app(RecommendationDecisions::class)->accept(soapRecommendation(), $manager);

        $sheet = workbook($this->actingAs($this->owner)->get(route('reports.export', ['replenishment', 'xlsx'])))->getSheet(0);

        expect($sheet->getStyle('A2')->getNumberFormat()->getFormatCode())->toBe('yyyy-mm-dd')
            ->and($sheet->getStyle('K2')->getNumberFormat()->getFormatCode())->toBe('yyyy-mm-dd hh:mm')
            ->and(Date::excelToDateTimeObject($sheet->getCell('A2')->getValue())->format('Y-m-d'))->toBe('2026-10-05');
    });

    it('leaves a gap, not a zero or a dash, where there is no value', function () {
        smallShop();

        $rows = workbook($this->actingAs($this->owner)->get(route('reports.export', ['inventory', 'xlsx'])))->getSheet(0)->toArray(null, false, false, false);
        $rice = collect($rows)->firstWhere(1, 'Rice');

        expect($rice[6])->toBeNull()    // no reorder point
            ->and($rice[8])->toBeNull(); // no advice
    });

    it('has an About sheet with what it covers, when, the figures and the notes', function () {
        smallShop();

        $about = workbook($this->actingAs($this->owner)->get(route('reports.export', ['sales', 'xlsx'])))->getSheetByName('About');
        $rows = $about->toArray(null, false, false, false);

        expect($rows[0])->toBe(['StockSense AI', 'Sales'])
            ->and($rows[1])->toBe(['Covers', '6 Sep 2026 to 5 Oct 2026 · All categories'])
            ->and($rows[2][0])->toBe('Made')
            ->and($rows[2][1])->toContain('5 Oct 2026')
            ->and(collect($rows)->firstWhere(0, 'Revenue'))->toBe(['Revenue', '₱2,300.00'])
            ->and(collect($rows)->firstWhere(0, 'Best seller'))->toBe(['Best seller', 'Rice'])
            ->and(collect($rows)->pluck(0)->implode(' '))->toContain('3 active products had no sales');
    });

    it('is still a valid file when the report is empty', function () {
        $book = workbook($this->actingAs($this->owner)->get(route('reports.export', ['sales', 'xlsx'])));

        expect($book->getSheet(0)->toArray(null, false, false, false))->toBe([['SKU', 'Product', 'Category', 'Unit', 'Units sold', 'Revenue', 'Average price', 'Share of revenue']]);
    });

    it('follows the filters in the address', function () {
        smallShop();

        $rows = workbook($this->actingAs($this->owner)->get(route('reports.export', ['sales', 'xlsx', 'from' => '2026-08-01', 'to' => '2026-08-31'])))->getSheet(0)->toArray(null, false, false, false);

        expect(collect($rows)->pluck(1)->all())->toBe(['Product', 'Rice', 'Total']);
    });
});

describe('as a PDF', function () {
    it('downloads as a PDF with a name that says what and when', function () {
        smallShop();

        $response = $this->actingAs($this->owner)->get(route('reports.export', ['sales', 'pdf']));

        $response->assertOk();

        expect($response->headers->get('content-type'))->toBe('application/pdf')
            ->and($response->headers->get('content-disposition'))->toContain('attachment')->toContain('stocksense-sales-2026-09-06_2026-10-05.pdf')
            ->and(substr($response->getContent(), 0, 5))->toBe('%PDF-')
            ->and(strlen($response->getContent()))->toBeGreaterThan(2000);
    });

    it('is a real PDF for every report', function (string $report) {
        smallShop();
        completedRun();

        $response = $this->actingAs($this->owner)->get(route('reports.export', [$report, 'pdf']));

        expect(substr($response->getContent(), 0, 5))->toBe('%PDF-')->and(str_contains($response->getContent(), '%%EOF'))->toBeTrue();
    })->with(['sales', 'inventory', 'forecast-accuracy', 'replenishment']);

    it('is still a valid PDF when the report is empty', function () {
        $response = $this->actingAs($this->owner)->get(route('reports.export', ['sales', 'pdf']));

        expect(substr($response->getContent(), 0, 5))->toBe('%PDF-');
    });
});

describe('what goes into the PDF', function () {
    $result = fn () => new ReportResult(
        columns: [
            new ReportColumn('name', 'Product'),
            new ReportColumn('units', 'Units', ReportColumn::INTEGER),
            new ReportColumn('revenue', 'Revenue', ReportColumn::MONEY),
            new ReportColumn('share', 'Share', ReportColumn::PERCENT),
            new ReportColumn('when', 'When', ReportColumn::DATE),
        ],
        rows: [['name' => 'Rice <b>&</b>', 'units' => 1250, 'revenue' => 1500.5, 'share' => 65.22, 'when' => '2026-10-05'], ['name' => 'Nails', 'units' => 1, 'revenue' => 8.0, 'share' => null, 'when' => null]],
        summary: [['label' => 'Revenue', 'value' => 1508.5, 'type' => ReportColumn::MONEY]],
        totals: ['name' => 'Total', 'units' => 1251],
        notes: ['Read this first.'],
    );

    it('is every value written as text, by its type', function () use ($result) {
        $data = app(ReportPdf::class)->data('Sales', '6 Sep to 5 Oct', '5 Oct 2026, 8:00 am', $result());

        expect($data['rows'][0])->toBe(['name' => 'Rice <b>&</b>', 'units' => '1,250', 'revenue' => '₱1,500.50', 'share' => '65.2%', 'when' => '5 Oct 2026'])
            ->and($data['rows'][1])->toBe(['name' => 'Nails', 'units' => '1', 'revenue' => '₱8.00', 'share' => '-', 'when' => '-'])
            ->and($data['totals'])->toBe(['name' => 'Total', 'units' => '1,251', 'revenue' => '', 'share' => '', 'when' => ''])
            ->and($data['summary'])->toBe([['label' => 'Revenue', 'text' => '₱1,508.50']])
            ->and($data['columns'][1])->toBe(['key' => 'units', 'label' => 'Units', 'numeric' => true])
            ->and($data['columns'][0]['numeric'])->toBeFalse()
            ->and($data['truncated'])->toBeNull();
    });

    it('is cut off at a thousand rows, and says so', function () {
        $rows = array_map(fn (int $n) => ['name' => "Product {$n}"], range(1, 1500));
        $data = app(ReportPdf::class)->data('Sales', '', 'now', new ReportResult([new ReportColumn('name', 'Product')], $rows));

        expect($data['rows'])->toHaveCount(1000)
            ->and($data['truncated'])->toBe(['shown' => 1000, 'total' => 1500]);
    });

    it('is laid out with the title, figures, table, totals and notes, and nothing unescaped', function () use ($result) {
        $html = view('reports.pdf', app(ReportPdf::class)->data('Sales', '6 Sep 2026 to 5 Oct 2026', '5 Oct 2026, 8:00 am', $result()))->render();

        expect($html)->toContain('<h1>Sales</h1>')
            ->toContain('6 Sep 2026 to 5 Oct 2026')
            ->toContain('₱1,508.50')
            ->toContain('<th class="num">Units</th>')
            ->toContain('1,250')
            ->toContain('Rice &lt;b&gt;&amp;&lt;/b&gt;')
            ->not->toContain('Rice <b>')
            ->toContain('class="total"')
            ->toContain('Read this first.')
            ->toContain('made 5 Oct 2026, 8:00 am');
    });

    it('says so when there is nothing to show', function () {
        $html = view('reports.pdf', app(ReportPdf::class)->data('Sales', '', 'now', new ReportResult([new ReportColumn('name', 'Product')], [])))->render();

        expect($html)->toContain('Nothing to show for this period.')->not->toContain('<table class="data">');
    });

    it('turns off everything in the PDF library that could run or fetch anything', function () {
        $options = app(ReportPdf::class)->make('Sales', '', 'now', new ReportResult([new ReportColumn('name', 'Product')], []))->getDomPDF()->getOptions();

        expect($options->getIsPhpEnabled())->toBeFalse()
            ->and($options->getIsRemoteEnabled())->toBeFalse()
            ->and($options->getDefaultFont())->toBe('DejaVu Sans');
    });
});

describe('the audit log', function () {
    it('records who exported what', function () {
        smallShop();

        $this->actingAs($this->owner)->get(route('reports.export', ['sales', 'xlsx', 'from' => '2026-09-01', 'to' => '2026-09-30']));
        $this->actingAs($this->manager)->get(route('reports.export', ['inventory', 'pdf']));

        $entries = Activity::where('log_name', 'reports')->orderBy('id')->get();

        expect($entries)->toHaveCount(2)
            ->and($entries[0]->description)->toBe('Exported the sales report as an Excel file')
            ->and($entries[0]->causer_id)->toBe($this->owner->id)
            ->and($entries[0]->properties['report'])->toBe('sales')
            ->and($entries[0]->properties['format'])->toBe('xlsx')
            ->and($entries[0]->properties['filters'])->toBe(['from' => '2026-09-01', 'to' => '2026-09-30'])
            ->and($entries[0]->properties['rows'])->toBe(2)
            ->and($entries[1]->description)->toBe('Exported the inventory status report as a PDF')
            ->and($entries[1]->causer_id)->toBe($this->manager->id);
    });

    it('does not record a refused export, or just looking', function () {
        smallShop();

        $this->actingAs($this->staff)->get(route('reports.export', ['sales', 'xlsx']))->assertForbidden();
        $this->actingAs($this->owner)->get(route('reports.show', 'sales'));

        expect(Activity::where('log_name', 'reports')->count())->toBe(0);
    });
});
