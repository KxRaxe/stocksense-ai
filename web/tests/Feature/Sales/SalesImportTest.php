<?php

use App\Enums\ImportStatus;
use App\Enums\SaleSource;
use App\Jobs\ProcessImportChunk;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Imports\ImportRowsFile;
use App\Services\Inventory\StockService;
use App\Services\Sales\SalesImportProcessor;
use App\Services\Sales\SalesService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);

    // Small slices, so even a handful of rows takes several queued jobs.
    config(['imports.chunk_size' => 2]);

    $this->user = User::factory()->manager()->create();
    $category = Category::factory()->create();

    $this->nails = Product::factory()->for($category)->create(['sku' => 'HW-001', 'unit_price' => 85]);
    $this->cement = Product::factory()->for($category)->create(['sku' => 'HW-002', 'unit_price' => 275]);

    $stock = app(StockService::class);
    $stock->openingStock($this->nails, 100);
    $stock->openingStock($this->cement, 40);
});

/**
 * A CSV upload from rows. The first row is the header unless given one.
 *
 * @param  list<list<mixed>>  $rows
 */
function csvUpload(array $rows, ?array $header = ['date', 'sku', 'quantity', 'unit_price'], string $name = 'sales.csv'): UploadedFile
{
    $out = new SplTempFileObject;

    foreach ($header === null ? $rows : [$header, ...$rows] as $row) {
        $out->fputcsv($row);
    }

    $out->rewind();
    $content = '';
    foreach ($out as $line) {
        $content .= $line;
    }

    return UploadedFile::fake()->createWithContent($name, $content);
}

/** An .xlsx upload; each cell is written as given (dates as real date cells). */
function xlsxUpload(array $rows, string $name = 'sales.xlsx'): UploadedFile
{
    $sheet = new Spreadsheet;
    $active = $sheet->getActiveSheet();

    foreach ($rows as $r => $row) {
        foreach ($row as $c => $value) {
            $cell = [$c + 1, $r + 1];

            if ($value instanceof DateTimeInterface) {
                $active->setCellValue($cell, ExcelDate::PHPToExcel($value));
                $active->getStyle($cell)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
            } else {
                $active->setCellValue($cell, $value);
            }
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    (new Xlsx($sheet))->save($path);

    return UploadedFile::fake()->createWithContent($name, file_get_contents($path));
}

/** Uploads a file as the manager and returns the batch it created. */
function uploadSales(UploadedFile $file, bool $adjustStock = false): ImportBatch
{
    test()->actingAs(test()->user)
        ->post(route('sales.imports.store'), ['file' => $file, 'adjust_stock' => $adjustStock])
        ->assertSessionHasNoErrors();

    return ImportBatch::latest('id')->firstOrFail();
}

/** Uploads, then confirms, which runs the whole import (the test queue is synchronous). */
function importSales(UploadedFile $file, bool $adjustStock = false): ImportBatch
{
    $batch = uploadSales($file, $adjustStock);

    test()->actingAs(test()->user)->post(route('sales.imports.confirm', $batch))->assertSessionHasNoErrors();

    return $batch->fresh();
}

describe('uploading a file', function () {
    it('opens a batch for checking, with the columns guessed', function () {
        $batch = uploadSales(csvUpload([
            ['2026-03-05', 'HW-001', 12, '85.00'],
            ['2026-03-05', 'HW-002', 3, ''],
        ], ['Sale Date', 'Product Code', 'Qty', 'Price']));

        expect($batch->status)->toBe(ImportStatus::Preview)
            ->and($batch->filename)->toBe('sales.csv')
            ->and($batch->rows_total)->toBe(2)
            ->and($batch->user_id)->toBe($this->user->id)
            ->and($batch->settings)->toEqual([
                'headers' => ['Sale Date', 'Product Code', 'Qty', 'Price'],
                'columns' => ['date' => 0, 'sku' => 1, 'quantity' => 2, 'unit_price' => 3],
                'date_format' => 'iso',
                'adjust_stock' => false,
            ]);

        Storage::disk('local')->assertExists($batch->rowsPath());
    });

    it('takes the user to the preview', function () {
        $this->actingAs($this->user)
            ->post(route('sales.imports.store'), ['file' => csvUpload([['2026-03-05', 'HW-001', 1, '']]), 'adjust_stock' => false])
            ->assertRedirect(route('sales.imports.show', ImportBatch::firstOrFail()));
    });

    it('reads an Excel file, including real date cells', function () {
        $batch = importSales(xlsxUpload([
            ['date', 'sku', 'quantity', 'unit_price'],
            [new DateTimeImmutable('2026-03-05'), 'HW-001', 12, 85],
            [new DateTimeImmutable('2026-03-06'), 'HW-002', 3, 280.5],
        ]));

        expect($batch->status)->toBe(ImportStatus::Completed)
            ->and($batch->rows_ok)->toBe(2);

        $sales = Sale::orderBy('id')->get();
        expect($sales[0]->sold_on->toDateString())->toBe('2026-03-05')
            ->and($sales[1]->sold_on->toDateString())->toBe('2026-03-06')
            ->and($sales[1]->unit_price)->toBe('280.50');
    });

    it('skips blank lines but keeps the real row numbers', function () {
        $batch = uploadSales(csvUpload([
            ['2026-03-05', 'HW-001', 1, ''],
            [null, null, null, null],
            ['2026-03-06', 'HW-001', 2, ''],
        ]));

        expect($batch->rows_total)->toBe(2);

        $rows = iterator_to_array((new ImportRowsFile($batch))->read(), false);
        expect(array_column($rows, 0))->toBe([2, 4]);   // the header is row 1, the blank line row 3
    });

    it('finds the header below leading blank lines', function () {
        $batch = uploadSales(csvUpload([
            [null, null, null, null],
            ['date', 'sku', 'quantity', 'unit_price'],
            ['2026-03-05', 'HW-001', 1, ''],
        ], header: null));

        expect($batch->settings['headers'])->toBe(['date', 'sku', 'quantity', 'unit_price'])
            ->and($batch->rows_total)->toBe(1);
    });

    it('accepts a semicolon-separated file', function () {
        $file = UploadedFile::fake()->createWithContent('sales.csv', "date;sku;quantity;unit_price\n2026-03-05;HW-001;4;85.00\n");

        $batch = uploadSales($file);

        expect($batch->settings['headers'])->toBe(['date', 'sku', 'quantity', 'unit_price'])
            ->and($batch->rows_total)->toBe(1);
    });

    it('names blank headers', function () {
        $batch = uploadSales(csvUpload([['2026-03-05', 'note', 'HW-001', 1]], ['date', '', 'sku', 'quantity']));

        expect($batch->settings['headers'])->toBe(['date', 'Column 2', 'sku', 'quantity']);
    });
});

describe('files that cannot be used', function () {
    it('rejects a file with the wrong problem, saying what it is', function (UploadedFile $file, string $message) {
        $this->actingAs($this->user)
            ->post(route('sales.imports.store'), ['file' => $file, 'adjust_stock' => false])
            ->assertSessionHasErrors(['file' => $message]);

        expect(ImportBatch::count())->toBe(0);
    })->with([
        'an empty file' => [fn () => UploadedFile::fake()->createWithContent('sales.csv', ''), 'The file is empty.'],
        'only blank lines' => [fn () => UploadedFile::fake()->createWithContent('sales.csv', "\n\n,,\n"), 'The file is empty.'],
        'a header and nothing else' => [fn () => csvUpload([], ['date', 'sku', 'quantity']), 'The file has a header row but no data rows.'],
        'the wrong kind of file' => [fn () => UploadedFile::fake()->createWithContent('sales.pdf', '%PDF-1.4'), 'Upload a CSV or Excel (.xlsx) file.'],
        'an old Excel format' => [fn () => UploadedFile::fake()->createWithContent('sales.xls', 'not really'), 'Upload a CSV or Excel (.xlsx) file.'],
        'a corrupt spreadsheet' => [fn () => UploadedFile::fake()->createWithContent('sales.xlsx', 'this is not a zip file'), 'This file could not be read. Upload a CSV or Excel (.xlsx) file.'],
    ]);

    it('rejects a file with too many rows', function () {
        config(['imports.max_rows' => 3]);

        $rows = array_fill(0, 4, ['2026-03-05', 'HW-001', 1, '']);

        $this->actingAs($this->user)
            ->post(route('sales.imports.store'), ['file' => csvUpload($rows), 'adjust_stock' => false])
            ->assertSessionHasErrors(['file' => 'The file has 4 data rows; the most one file can have is 3. Split it into smaller files.']);
    });

    it('rejects a file that is too big', function () {
        $this->actingAs($this->user)
            ->post(route('sales.imports.store'), [
                'file' => UploadedFile::fake()->create('sales.csv', config('imports.max_file_kb') + 1),
                'adjust_stock' => false,
            ])
            ->assertSessionHasErrors('file');
    });

    it('needs a file and a stock choice', function () {
        $this->actingAs($this->user)->post(route('sales.imports.store'), [])->assertSessionHasErrors(['file', 'adjust_stock']);
    });
});

describe('the preview', function () {
    /** The preview props for a batch. */
    function previewOf(ImportBatch $batch): array
    {
        return test()->actingAs(test()->user)->get(route('sales.imports.show', $batch))->viewData('page')['props']['preview'];
    }

    it('says how many rows are good, repeated or wrong, and why', function () {
        $batch = uploadSales(csvUpload([
            ['2026-03-05', 'HW-001', 12, '85.00'],       // fine
            ['2026-03-05', 'ZZ-999', 1, '1.00'],         // unknown SKU
            ['03/05/2026', 'HW-001', 1, '1.00'],         // wrong date layout
            ['2026-03-06', 'HW-002', 'lots', ''],        // bad quantity
            ['2999-01-01', 'HW-002', 1, ''],             // future
            ['2026-03-07', 'HW-002', 3, ''],             // fine
        ]));

        $preview = previewOf($batch);

        expect($preview['importable_rows'])->toBe(2)
            ->and($preview['invalid_rows'])->toBe(4)
            ->and(array_column($preview['figures'], 'value', 'label'))->toBe([
                'Will be imported' => 2,
                'Already imported, skipped' => 0,
                'Have a problem, skipped' => 4,
            ])
            ->and($preview['import_label'])->toBe('Import 2 rows')
            ->and(collect($preview['problems'])->pluck('row')->all())->toBe([3, 4, 5, 6])
            ->and($preview['problems'][0]['messages'])->toBe(["Unknown SKU 'ZZ-999'."])
            ->and($preview['sample'][0])->toMatchArray(['row' => 2, 'status' => 'ok'])
            ->and($preview['sample'][1])->toMatchArray(['row' => 3, 'status' => 'error']);
    });

    it('saves nothing', function () {
        $batch = uploadSales(csvUpload([['2026-03-05', 'HW-001', 12, '']]));

        previewOf($batch);

        expect(Sale::count())->toBe(0)
            ->and(StockMovement::where('type', 'sale')->count())->toBe(0);
    });

    it('offers the columns and date layouts to choose from', function () {
        $batch = uploadSales(csvUpload([['2026-03-05', 'HW-001', 1, '']]));

        $this->actingAs($this->user)
            ->get(route('sales.imports.show', $batch))
            ->assertInertia(fn ($page) => $page
                ->component('sales/imports/show')
                ->where('batch.status', 'preview')
                ->where('batch.headers', ['date', 'sku', 'quantity', 'unit_price'])
                ->where('fields.0', ['key' => 'date', 'label' => 'Date', 'required' => true])
                ->where('fields.3.required', false)
                ->where('options.0.name', 'adjust_stock')
                ->where('options.1.name', 'date_format')
                ->has('options.1.choices', 3)
                ->where('batch.options', ['date_format' => 'iso', 'adjust_stock' => '0']));
    });

    it('changes with the chosen column mapping and date layout', function () {
        $batch = uploadSales(csvUpload(
            [['03/05/2026', 'HW-001', 12, '85.00']],
            ['Fecha', 'Codigo', 'Cantidad', 'Precio'],   // none of these are recognised
        ));

        expect($batch->settings['columns'])->toEqual(['date' => null, 'sku' => null, 'quantity' => null, 'unit_price' => null])
            ->and(previewOf($batch)['importable_rows'])->toBe(0);

        $this->actingAs($this->user)->put(route('sales.imports.update', $batch), [
            'columns' => ['date' => 0, 'sku' => 1, 'quantity' => 2, 'unit_price' => 3],
            'date_format' => 'mdy',
            'adjust_stock' => false,
        ])->assertSessionHasNoErrors();

        expect(previewOf($batch->fresh()))->toMatchArray(['importable_rows' => 1, 'invalid_rows' => 0]);
    });

    it('marks rows already imported before', function () {
        importSales(csvUpload([['2026-03-05', 'HW-001', 12, '85.00']]));

        $preview = previewOf(uploadSales(csvUpload([
            ['2026-03-05', 'HW-001', 12, '85.00'],   // same as before
            ['2026-03-06', 'HW-001', 4, '85.00'],    // new
        ])));

        expect($preview['importable_rows'])->toBe(1)
            ->and(array_column($preview['figures'], 'value', 'label')['Already imported, skipped'])->toBe(1)
            ->and($preview['sample'][0])->toMatchArray(['status' => 'skip', 'label' => 'Already imported']);
    });
});

describe('changing the settings', function () {
    beforeEach(function () {
        $this->batch = uploadSales(csvUpload([['2026-03-05', 'HW-001', 1, '']]));
    });

    it('saves them', function () {
        $this->actingAs($this->user)->put(route('sales.imports.update', $this->batch), [
            'columns' => ['date' => 0, 'sku' => 1, 'quantity' => 2, 'unit_price' => null],
            'date_format' => 'dmy',
            'adjust_stock' => true,
        ])->assertSessionHasNoErrors();

        expect($this->batch->fresh()->settings)->toEqual([
            'headers' => ['date', 'sku', 'quantity', 'unit_price'],
            'columns' => ['date' => 0, 'sku' => 1, 'quantity' => 2, 'unit_price' => null],
            'date_format' => 'dmy',
            'adjust_stock' => true,
        ]);
    });

    it('rejects a column that is not in the file or a layout that does not exist', function (array $change, string $field) {
        $this->actingAs($this->user)
            ->put(route('sales.imports.update', $this->batch), array_replace_recursive([
                'columns' => ['date' => 0, 'sku' => 1, 'quantity' => 2, 'unit_price' => 3],
                'date_format' => 'iso',
                'adjust_stock' => false,
            ], $change))
            ->assertSessionHasErrors($field);
    })->with([
        'a column past the end' => [['columns' => ['sku' => 9]], 'columns.sku'],
        'a negative column' => [['columns' => ['date' => -1]], 'columns.date'],
        'an unknown date layout' => [['date_format' => 'ymd'], 'date_format'],
    ]);

    it('cannot be changed once the import has started', function () {
        $this->actingAs($this->user)->post(route('sales.imports.confirm', $this->batch));

        $this->actingAs($this->user)
            ->put(route('sales.imports.update', $this->batch), [
                'columns' => ['date' => 0, 'sku' => 1, 'quantity' => 2, 'unit_price' => 3],
                'date_format' => 'iso',
                'adjust_stock' => false,
            ])
            ->assertSessionHasErrors('import');
    });
});

describe('importing', function () {
    it('queues the first slice and waits', function () {
        Queue::fake();

        $batch = uploadSales(csvUpload([['2026-03-05', 'HW-001', 1, '']]));
        $this->actingAs($this->user)->post(route('sales.imports.confirm', $batch))->assertRedirect();

        Queue::assertPushedOn('imports', ProcessImportChunk::class, fn ($job) => $job->batchId === $batch->id && $job->offset === 0);
        expect($batch->fresh()->status)->toBe(ImportStatus::Queued)
            ->and(Sale::count())->toBe(0);
    });

    it('imports every row, however many slices that takes', function () {
        $batch = importSales(csvUpload([
            ['2026-03-01', 'HW-001', 1, '85.00'],
            ['2026-03-02', 'HW-001', 2, '85.00'],
            ['2026-03-03', 'HW-002', 3, '275.00'],
            ['2026-03-04', 'HW-002', 4, '275.00'],
            ['2026-03-05', 'HW-001', 5, '85.00'],
        ]));

        expect($batch->status)->toBe(ImportStatus::Completed)
            ->and($batch->rows_total)->toBe(5)
            ->and($batch->rows_processed)->toBe(5)
            ->and($batch->rows_ok)->toBe(5)
            ->and($batch->rows_failed)->toBe(0)
            ->and($batch->started_at)->not->toBeNull()
            ->and($batch->finished_at)->not->toBeNull()
            ->and(Sale::where('import_batch_id', $batch->id)->count())->toBe(5)
            ->and(Sale::where('source', 'import')->count())->toBe(5);
    });

    it('uses the product price when the price is blank', function () {
        importSales(csvUpload([['2026-03-05', 'HW-002', 2, '']]));

        expect(Sale::firstOrFail())->unit_price->toBe('275.00')->total->toBe('550.00');
    });

    it('reads prices written with a currency sign', function () {
        importSales(csvUpload([['2026-03-05', 'HW-001', 1, '₱1,250.50']]));

        expect(Sale::firstOrFail()->unit_price)->toBe('1250.50');
    });

    it('imports the good rows and reports the bad ones', function () {
        $batch = importSales(csvUpload([
            ['2026-03-05', 'HW-001', 12, '85.00'],
            ['2026-03-05', 'ZZ-999', 1, '1.00'],
            ['2026-03-06', 'HW-002', 'lots', ''],
            ['2026-03-07', 'HW-002', 3, ''],
        ]));

        expect($batch->status)->toBe(ImportStatus::Completed)
            ->and($batch->rows_ok)->toBe(2)
            ->and($batch->rows_failed)->toBe(2)
            ->and(Sale::count())->toBe(2)
            ->and($batch->errors)->toBe([
                ['row' => 3, 'messages' => ["Unknown SKU 'ZZ-999'."]],
                ['row' => 4, 'messages' => ["Quantity 'lots' is not a number."]],
            ]);
    });

    it('does not import anything when nothing in the file is valid', function () {
        $batch = importSales(csvUpload([['soon', 'ZZ-1', 'x', 'y']]));

        expect($batch->status)->toBe(ImportStatus::Completed)
            ->and($batch->rows_ok)->toBe(0)
            ->and($batch->rows_failed)->toBe(1)
            ->and(Sale::count())->toBe(0);
    });

    it('will not start until the required columns are chosen', function () {
        $batch = uploadSales(csvUpload([['2026-03-05', 'HW-001', 1, '']], ['A', 'B', 'C', 'D']));

        $this->actingAs($this->user)
            ->post(route('sales.imports.confirm', $batch))
            ->assertSessionHasErrors(['columns' => 'Choose a column for: Date, SKU (product code), Quantity sold.']);

        expect($batch->fresh()->status)->toBe(ImportStatus::Preview);
    });

    it('cannot be started twice', function () {
        $batch = importSales(csvUpload([['2026-03-05', 'HW-001', 1, '']]));

        $this->actingAs($this->user)->post(route('sales.imports.confirm', $batch))->assertSessionHasErrors('import');

        expect(Sale::count())->toBe(1);
    });

    it('logs who started it', function () {
        $batch = importSales(csvUpload([['2026-03-05', 'HW-001', 1, '']]));

        $activity = Activity::where('event', 'import_started')->firstOrFail();
        expect($activity->causer_id)->toBe($this->user->id)
            ->and($activity->subject_id)->toBe($batch->id)
            ->and($activity->getProperty('rows'))->toBe(1);
    });

    it('marks the batch failed if a slice crashes, keeping what was already saved', function () {
        $calls = 0;
        $real = app(SalesImportProcessor::class);

        $this->mock(SalesImportProcessor::class, function ($mock) use (&$calls, $real) {
            $mock->shouldReceive('processNext')->andReturnUsing(function ($batch, $offset) use (&$calls, $real) {
                if (++$calls === 2) {
                    throw new RuntimeException('database went away');
                }

                return $real->processNext($batch, $offset);
            });
        });

        $this->withoutExceptionHandling();
        $batch = uploadSales(csvUpload([
            ['2026-03-01', 'HW-001', 1, ''], ['2026-03-02', 'HW-001', 1, ''],
            ['2026-03-03', 'HW-001', 1, ''], ['2026-03-04', 'HW-001', 1, ''],
        ]));

        expect(fn () => $this->actingAs($this->user)->post(route('sales.imports.confirm', $batch)))
            ->toThrow(RuntimeException::class);

        $batch->refresh();
        expect($batch->status)->toBe(ImportStatus::Failed)
            ->and($batch->error_message)->toContain('unexpected error')
            ->and($batch->rows_ok)->toBe(2)           // the first slice stayed
            ->and(Sale::count())->toBe(2);
    });

    it('does nothing if a slice is run again after it finished', function () {
        $batch = importSales(csvUpload([['2026-03-01', 'HW-001', 1, ''], ['2026-03-02', 'HW-001', 1, '']]));

        // The same job delivered twice, for example after a worker restart.
        $more = app(SalesImportProcessor::class)->processNext($batch->fresh(), 0);

        expect($more)->toBeFalse()
            ->and(Sale::count())->toBe(2);
    });
});

describe('stock', function () {
    it('takes sales off the shelf when asked to', function () {
        importSales(csvUpload([
            ['2026-03-05', 'HW-001', 12, ''],
            ['2026-03-06', 'HW-001', 8, ''],
            ['2026-03-06', 'HW-002', 3, ''],
        ]), adjustStock: true);

        expect(InventoryLevel::where('product_id', $this->nails->id)->value('on_hand'))->toBe(80)
            ->and(InventoryLevel::where('product_id', $this->cement->id)->value('on_hand'))->toBe(37)
            ->and(StockMovement::where('type', 'sale')->count())->toBe(3);

        $first = StockMovement::where('type', 'sale')->orderBy('id')->first();
        expect($first->occurred_at->toDateString())->toBe('2026-03-05')
            ->and($first->user_id)->toBe($this->user->id);
    });

    it('leaves stock alone for historical data', function () {
        importSales(csvUpload([['2026-03-05', 'HW-001', 12, '']]), adjustStock: false);

        expect(Sale::count())->toBe(1)
            ->and(StockMovement::where('type', 'sale')->count())->toBe(0)
            ->and(InventoryLevel::where('product_id', $this->nails->id)->value('on_hand'))->toBe(100);
    });

    it('can be switched in the preview', function () {
        $batch = uploadSales(csvUpload([['2026-03-05', 'HW-001', 12, '']]), adjustStock: false);

        $this->actingAs($this->user)->put(route('sales.imports.update', $batch), [
            'columns' => $batch->settings['columns'],
            'date_format' => 'iso',
            'adjust_stock' => true,
        ]);
        $this->actingAs($this->user)->post(route('sales.imports.confirm', $batch));

        expect(InventoryLevel::where('product_id', $this->nails->id)->value('on_hand'))->toBe(88);
    });

    it('keeps the ledger adding up to the stock levels', function () {
        importSales(csvUpload([['2026-03-05', 'HW-001', 12, ''], ['2026-03-05', 'HW-002', 5, '']]), adjustStock: true);

        foreach ([$this->nails, $this->cement] as $product) {
            expect((int) StockMovement::where('product_id', $product->id)->sum('quantity'))
                ->toBe((int) InventoryLevel::where('product_id', $product->id)->value('on_hand'));
        }
    });
});

describe('duplicates', function () {
    it('skips rows that an earlier import already brought in', function () {
        $rows = [['2026-03-05', 'HW-001', 12, '85.00'], ['2026-03-06', 'HW-002', 3, '275.00']];

        importSales(csvUpload($rows));
        $second = importSales(csvUpload($rows));

        expect($second->status)->toBe(ImportStatus::Completed)
            ->and($second->rows_ok)->toBe(0)
            ->and($second->rows_duplicate)->toBe(2)
            ->and($second->rows_failed)->toBe(0)
            ->and(Sale::count())->toBe(2);
    });

    it('imports only the new rows of a file that overlaps an earlier one', function () {
        importSales(csvUpload([['2026-03-05', 'HW-001', 12, '85.00'], ['2026-03-06', 'HW-001', 4, '85.00']]));

        $second = importSales(csvUpload([
            ['2026-03-06', 'HW-001', 4, '85.00'],   // already there
            ['2026-03-07', 'HW-001', 6, '85.00'],   // new
            ['2026-03-08', 'HW-001', 1, '85.00'],   // new
        ]));

        expect($second->rows_ok)->toBe(2)
            ->and($second->rows_duplicate)->toBe(1)
            ->and(Sale::count())->toBe(4);
    });

    it('treats a different quantity, price or day as a different sale', function (array $row) {
        importSales(csvUpload([['2026-03-05', 'HW-001', 12, '85.00']]));
        $second = importSales(csvUpload([$row]));

        expect($second->rows_ok)->toBe(1)->and($second->rows_duplicate)->toBe(0);
    })->with([
        'another quantity' => [['2026-03-05', 'HW-001', 13, '85.00']],
        'another price' => [['2026-03-05', 'HW-001', 12, '90.00']],
        'another day' => [['2026-03-06', 'HW-001', 12, '85.00']],
        'another product' => [['2026-03-05', 'HW-002', 12, '85.00']],
    ]);

    it('keeps identical rows within one file, such as two receipts for the same thing', function () {
        $batch = importSales(csvUpload([
            ['2026-03-05', 'HW-001', 1, '85.00'],
            ['2026-03-05', 'HW-001', 1, '85.00'],
            ['2026-03-05', 'HW-001', 1, '85.00'],
        ]));

        expect($batch->rows_ok)->toBe(3)->and(Sale::count())->toBe(3);
    });

    it('does not count a sale entered by hand as a duplicate', function () {
        app(SalesService::class)->record($this->nails, CarbonImmutable::parse('2026-03-05'), 12, '85.00', SaleSource::Manual);

        $batch = importSales(csvUpload([['2026-03-05', 'HW-001', 12, '85.00']]));

        expect($batch->rows_ok)->toBe(1)->and(Sale::count())->toBe(2);
    });

    it('does not count rows of an import that was undone', function () {
        $rows = [['2026-03-05', 'HW-001', 12, '85.00']];

        $first = importSales(csvUpload($rows));
        $this->actingAs($this->user)->post(route('sales.imports.undo', $first));

        $again = importSales(csvUpload($rows));

        expect($again->rows_ok)->toBe(1)->and(Sale::count())->toBe(1);
    });
});

describe('the error report', function () {
    it('lists every failed row, even past the number kept on screen', function () {
        config(['imports.stored_errors' => 1]);

        $batch = importSales(csvUpload([
            ['2026-03-05', 'ZZ-1', 1, ''],
            ['2026-03-06', 'ZZ-2', 1, ''],
            ['2026-03-07', 'HW-001', 1, ''],
            ['2026-03-08', 'ZZ-3', 1, ''],
        ]));

        expect($batch->rows_failed)->toBe(3)
            ->and($batch->errors)->toHaveCount(1);

        $csv = $this->actingAs($this->user)->get(route('sales.imports.errors', $batch))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=utf-8')
            ->streamedContent();

        $lines = array_values(array_filter(explode("\n", trim($csv))));

        expect($lines)->toHaveCount(4)
            ->and($lines[0])->toBe('Row,Problem,date,sku,quantity,unit_price')
            ->and($lines[1])->toBe('2,"Unknown SKU \'ZZ-1\'.",2026-03-05,ZZ-1,1,')
            ->and($lines[3])->toContain("Unknown SKU 'ZZ-3'.");
    });

    it('is not there when nothing failed', function () {
        $batch = importSales(csvUpload([['2026-03-05', 'HW-001', 1, '']]));

        $this->actingAs($this->user)->get(route('sales.imports.errors', $batch))->assertNotFound();
    });
});

describe('progress', function () {
    it('shows the batch while it runs and when it is done', function () {
        $batch = importSales(csvUpload([['2026-03-05', 'HW-001', 1, ''], ['2026-03-06', 'ZZ-1', 1, '']]));

        $this->actingAs($this->user)
            ->get(route('sales.imports.show', $batch))
            ->assertInertia(fn ($page) => $page
                ->component('sales/imports/show')
                ->where('batch.status', 'completed')
                ->where('batch.is_active', false)
                ->where('batch.can_undo', true)
                ->where('batch.rows_total', 2)
                ->where('batch.rows_ok', 1)
                ->where('batch.rows_failed', 1)
                ->where('batch.has_error_report', true)
                ->missing('preview'));
    });

    it('says an import is active while queued', function () {
        Queue::fake();

        $batch = uploadSales(csvUpload([['2026-03-05', 'HW-001', 1, '']]));
        $this->actingAs($this->user)->post(route('sales.imports.confirm', $batch));

        $this->actingAs($this->user)
            ->get(route('sales.imports.show', $batch))
            ->assertInertia(fn ($page) => $page->where('batch.status', 'queued')->where('batch.is_active', true)->where('batch.can_undo', false));
    });

    it('lists past imports, newest first', function () {
        $first = importSales(csvUpload([['2026-03-05', 'HW-001', 1, '']], name: 'march.csv'));
        $second = importSales(csvUpload([['2026-04-05', 'HW-001', 1, '']], name: 'april.csv'));

        $this->actingAs($this->user)
            ->get(route('sales.imports.index'))
            ->assertInertia(fn ($page) => $page
                ->where('batches.data.0.filename', 'april.csv')
                ->where('batches.data.1.filename', 'march.csv')
                ->where('batches.data.0.user', $this->user->name));
    });
});

describe('cancelling', function () {
    it('throws away a file that has not been imported', function () {
        $batch = uploadSales(csvUpload([['2026-03-05', 'HW-001', 1, '']]));

        $this->actingAs($this->user)->delete(route('sales.imports.cancel', $batch))->assertRedirect(route('sales.imports.index'));

        expect(ImportBatch::count())->toBe(0);
        Storage::disk('local')->assertMissing($batch->rowsPath());
    });

    it('cannot cancel an import that has run', function () {
        $batch = importSales(csvUpload([['2026-03-05', 'HW-001', 1, '']]));

        $this->actingAs($this->user)->delete(route('sales.imports.cancel', $batch))->assertSessionHasErrors('import');

        expect(ImportBatch::count())->toBe(1);
    });
});

describe('undoing', function () {
    it('removes the sales and puts the stock back', function () {
        $batch = importSales(csvUpload([
            ['2026-03-05', 'HW-001', 12, ''],
            ['2026-03-06', 'HW-001', 8, ''],
            ['2026-03-06', 'HW-002', 3, ''],
        ]), adjustStock: true);

        $this->actingAs($this->user)->post(route('sales.imports.undo', $batch))->assertRedirect();

        expect($batch->fresh()->status)->toBe(ImportStatus::Undone)
            ->and(Sale::count())->toBe(0)
            ->and(InventoryLevel::where('product_id', $this->nails->id)->value('on_hand'))->toBe(100)
            ->and(InventoryLevel::where('product_id', $this->cement->id)->value('on_hand'))->toBe(40);

        // One correcting movement per product, and the originals are still in the ledger.
        $corrections = StockMovement::where('type', 'adjustment')->where('note', "Import #{$batch->id} undone")->get();
        expect($corrections)->toHaveCount(2)
            ->and($corrections->pluck('quantity')->sort()->values()->all())->toBe([3, 20])
            ->and($corrections->pluck('user_id')->unique()->all())->toBe([$this->user->id])
            ->and(StockMovement::where('type', 'sale')->count())->toBe(3);
    });

    it('keeps the ledger adding up to the stock levels', function () {
        $batch = importSales(csvUpload([['2026-03-05', 'HW-001', 12, '']]), adjustStock: true);
        $this->actingAs($this->user)->post(route('sales.imports.undo', $batch));

        expect((int) StockMovement::where('product_id', $this->nails->id)->sum('quantity'))
            ->toBe((int) InventoryLevel::where('product_id', $this->nails->id)->value('on_hand'));
    });

    it('removes only that import, leaving other sales alone', function () {
        $keep = importSales(csvUpload([['2026-03-01', 'HW-001', 1, '']]));
        $undo = importSales(csvUpload([['2026-03-02', 'HW-001', 1, '']]));
        app(SalesService::class)->record($this->nails, CarbonImmutable::parse('2026-03-03'), 1, '85.00', SaleSource::Manual);

        $this->actingAs($this->user)->post(route('sales.imports.undo', $undo));

        expect(Sale::count())->toBe(2)
            ->and(Sale::where('import_batch_id', $keep->id)->count())->toBe(1)
            ->and(Sale::where('source', 'manual')->count())->toBe(1);
    });

    it('works on a historical import that left stock alone', function () {
        $batch = importSales(csvUpload([['2026-03-05', 'HW-001', 12, '']]), adjustStock: false);

        $this->actingAs($this->user)->post(route('sales.imports.undo', $batch));

        expect(Sale::count())->toBe(0)
            ->and(StockMovement::where('type', 'adjustment')->count())->toBe(0)
            ->and(InventoryLevel::where('product_id', $this->nails->id)->value('on_hand'))->toBe(100);
    });

    it('works on an import that failed part-way', function () {
        $batch = importSales(csvUpload([['2026-03-05', 'HW-001', 12, '']]), adjustStock: true);
        $batch->forceFill(['status' => ImportStatus::Failed])->save();

        $this->actingAs($this->user)->post(route('sales.imports.undo', $batch))->assertRedirect();

        expect($batch->fresh()->status)->toBe(ImportStatus::Undone)
            ->and(InventoryLevel::where('product_id', $this->nails->id)->value('on_hand'))->toBe(100);
    });

    it('cannot be done twice or before the import has run', function () {
        $done = importSales(csvUpload([['2026-03-05', 'HW-001', 1, '']]));
        $this->actingAs($this->user)->post(route('sales.imports.undo', $done))->assertRedirect();
        $this->actingAs($this->user)->post(route('sales.imports.undo', $done))->assertSessionHasErrors('import');

        $waiting = uploadSales(csvUpload([['2026-03-05', 'HW-001', 1, '']]));
        $this->actingAs($this->user)->post(route('sales.imports.undo', $waiting))->assertSessionHasErrors('import');
    });

    it('logs who undid it', function () {
        $batch = importSales(csvUpload([['2026-03-05', 'HW-001', 1, '']]));
        $this->actingAs($this->user)->post(route('sales.imports.undo', $batch));

        $activity = Activity::where('event', 'import_undone')->firstOrFail();
        expect($activity->causer_id)->toBe($this->user->id)->and($activity->subject_id)->toBe($batch->id);
    });
});

describe('the template', function () {
    it('is a CSV with the right columns and real SKUs', function () {
        $csv = $this->actingAs($this->user)->get(route('sales.imports.template'))->assertOk()->streamedContent();

        $lines = array_values(array_filter(explode("\n", trim($csv))));

        expect($lines[0])->toBe('date,sku,quantity,unit_price')
            ->and($lines[1])->toContain('HW-001')
            ->and($lines[2])->toContain('HW-002');
    });

    it('imports cleanly as downloaded', function () {
        $csv = $this->actingAs($this->user)->get(route('sales.imports.template'))->streamedContent();

        $batch = importSales(UploadedFile::fake()->createWithContent('template.csv', $csv));

        expect($batch->rows_failed)->toBe(0)->and($batch->rows_ok)->toBe(2);
    });
});
