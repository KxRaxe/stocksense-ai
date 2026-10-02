<?php

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Enums\StockMovementType;
use App\Jobs\ProcessImportChunk;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Imports\ImportRowsFile;
use App\Services\Inventory\StockService;
use App\Services\Products\ProductImportProcessor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

const PRODUCT_HEADER = ['sku', 'name', 'category', 'unit', 'unit_cost', 'unit_price', 'lead_time_days', 'moq', 'pack_size', 'reorder_point', 'safety_stock', 'opening_stock'];

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);

    // Small slices, so even a handful of rows takes several queued jobs.
    config(['imports.chunk_size' => 2]);

    $this->user = User::factory()->manager()->create();
    $this->hardware = Category::factory()->create(['name' => 'Hardware', 'service_level' => 90]);

    $this->nails = Product::factory()->for($this->hardware)->create([
        'sku' => 'HW-001', 'name' => 'Nails', 'unit' => 'kg', 'unit_cost' => 40, 'unit_price' => 85, 'lead_time_days' => 4,
    ]);
    app(StockService::class)->openingStock($this->nails, 100);
});

/**
 * A product file as a CSV upload. The first row is the header unless given one.
 *
 * @param  list<list<mixed>>  $rows
 * @param  list<string>|null  $header
 */
function productsCsv(array $rows, ?array $header = PRODUCT_HEADER, string $name = 'products.csv'): UploadedFile
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

/**
 * A row for a new product: SKU, name and category, then the rest as given.
 *
 * @param  array<int, mixed>  $change
 * @return list<mixed>
 */
function newProductRow(string $sku, array $change = []): array
{
    return array_replace([$sku, "Product {$sku}", 'Hardware', 'pc', '10.00', '20.00', 5, 1, 1, '', '', 0], $change);
}

/**
 * Uploads a file as the manager and returns the batch it created.
 *
 * @param  array<string, mixed>  $options
 */
function uploadProducts(UploadedFile $file, array $options = []): ImportBatch
{
    test()->actingAs(test()->user)
        ->post(route('products.imports.store'), ['file' => $file, ...$options])
        ->assertSessionHasNoErrors();

    return ImportBatch::latest('id')->firstOrFail();
}

/**
 * Uploads, then confirms, which runs the whole import (the test queue is synchronous).
 *
 * @param  array<string, mixed>  $options
 */
function importProducts(UploadedFile $file, array $options = []): ImportBatch
{
    $batch = uploadProducts($file, $options);

    test()->actingAs(test()->user)->post(route('products.imports.confirm', $batch))->assertSessionHasNoErrors();

    return $batch->fresh();
}

/**
 * What the preview says about a file, with the person's choices applied.
 *
 * @param  array<string, mixed>  $options
 * @return array<string, mixed>
 */
function productPreview(UploadedFile $file, array $options = []): array
{
    $batch = uploadProducts($file, $options);

    return test()->actingAs(test()->user)->get(route('products.imports.show', $batch))->viewData('page')['props']['preview'];
}

function onHandOf(Product $product): int
{
    return (int) InventoryLevel::where('product_id', $product->id)->where('location_id', Location::defaultLocation()->id)->value('on_hand');
}

describe('uploading a file', function () {
    it('opens a batch for checking, with the columns guessed and the default choices', function () {
        $batch = uploadProducts(productsCsv([['NEW-1', 'Nails', 'Hardware']], ['Item Code', 'Product Name', 'Department']));

        expect($batch->type)->toBe(ImportType::Products)
            ->and($batch->status)->toBe(ImportStatus::Preview)
            ->and($batch->rows_total)->toBe(1)
            ->and($batch->user_id)->toBe($this->user->id)
            ->and($batch->settings['headers'])->toBe(['Item Code', 'Product Name', 'Department'])
            ->and($batch->settings['columns']['sku'])->toBe(0)
            ->and($batch->settings['columns']['name'])->toBe(1)
            ->and($batch->settings['columns']['category'])->toBe(2)
            ->and($batch->settings['columns']['unit_price'])->toBeNull()
            ->and($batch->settings['existing_skus'])->toBe('skip')
            ->and($batch->settings['create_categories'])->toBeFalse();
    });

    it('keeps the choices made at upload', function () {
        $batch = uploadProducts(productsCsv([newProductRow('NEW-1')]), ['existing_skus' => 'update', 'create_categories' => '1']);

        expect($batch->settings['existing_skus'])->toBe('update')
            ->and($batch->settings['create_categories'])->toBeTrue();
    });

    it('sends the person to the preview', function () {
        $this->actingAs($this->user)
            ->post(route('products.imports.store'), ['file' => productsCsv([newProductRow('NEW-1')])])
            ->assertRedirect(route('products.imports.show', ImportBatch::firstOrFail()));
    });

    it('saves the rows for the queue to read and changes nothing yet', function () {
        $batch = uploadProducts(productsCsv([newProductRow('NEW-1'), newProductRow('NEW-2')]));

        $rows = iterator_to_array((new ImportRowsFile($batch))->read(), false);

        expect(array_column($rows, 0))->toBe([2, 3])
            ->and(Product::count())->toBe(1);
    });

    it('rejects a file that is not a spreadsheet, or a choice that does not exist', function () {
        $this->actingAs($this->user)
            ->post(route('products.imports.store'), ['file' => UploadedFile::fake()->create('products.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('file');

        $this->actingAs($this->user)
            ->post(route('products.imports.store'), ['file' => productsCsv([newProductRow('NEW-1')]), 'existing_skus' => 'delete'])
            ->assertSessionHasErrors('existing_skus');

        expect(ImportBatch::count())->toBe(0);
    });

    it('says so when the file has no rows', function () {
        $this->actingAs($this->user)
            ->post(route('products.imports.store'), ['file' => productsCsv([])])
            ->assertSessionHasErrors('file');
    });
});

describe('the preview', function () {
    it('says how many products would be created, and which rows are wrong', function () {
        $preview = productPreview(productsCsv([
            newProductRow('NEW-1'),                          // fine
            newProductRow('NEW-2', [2 => 'Toys']),           // unknown category
            newProductRow('NEW-3', [1 => '']),               // no name
            newProductRow('NEW-4', [4 => 'cheap']),          // bad cost
            newProductRow('NEW-5'),                          // fine
        ]));

        expect($preview['importable_rows'])->toBe(2)
            ->and($preview['invalid_rows'])->toBe(3)
            ->and($preview['import_label'])->toBe('Import 2 rows')
            ->and(array_column($preview['figures'], 'value', 'label'))->toBe([
                'Will be created' => 2,
                'Already exist, skipped' => 0,
                'Have a problem, skipped' => 3,
            ])
            ->and(collect($preview['problems'])->pluck('row')->all())->toBe([3, 4, 5])
            ->and($preview['problems'][0]['messages'])->toBe(["Unknown category 'Toys'."])
            ->and($preview['sample'][0])->toMatchArray(['row' => 2, 'status' => 'ok', 'label' => 'New product'])
            ->and($preview['sample'][1])->toMatchArray(['row' => 3, 'status' => 'error']);
    });

    it('counts the products that already exist as skipped', function () {
        $preview = productPreview(productsCsv([newProductRow('hw-001'), newProductRow('NEW-1')]));

        expect($preview['importable_rows'])->toBe(1)
            ->and(array_column($preview['figures'], 'value', 'label'))->toMatchArray(['Will be created' => 1, 'Already exist, skipped' => 1])
            ->and($preview['sample'][0])->toMatchArray(['status' => 'skip', 'label' => 'Already exists']);
    });

    it('counts them as updates when asked to update', function () {
        $preview = productPreview(productsCsv([newProductRow('hw-001'), newProductRow('NEW-1')]), ['existing_skus' => 'update']);

        expect($preview['importable_rows'])->toBe(2)
            ->and(array_column($preview['figures'], 'value', 'label'))->toMatchArray(['Will be created' => 1, 'Will be updated' => 1])
            ->and($preview['sample'][0])->toMatchArray(['status' => 'ok', 'label' => 'Update']);
    });

    it('flags a SKU that is repeated in the file', function () {
        $preview = productPreview(productsCsv([newProductRow('NEW-1'), newProductRow('new-1')]));

        expect($preview['importable_rows'])->toBe(1)
            ->and($preview['problems'])->toBe([['row' => 3, 'messages' => ["SKU 'NEW-1' appears more than once in the file."]]]);
    });

    it('lists the categories that would be created, instead of calling them problems', function () {
        $preview = productPreview(
            productsCsv([newProductRow('NEW-1', [2 => 'Toys']), newProductRow('NEW-2', [2 => 'TOYS']), newProductRow('NEW-3', [2 => 'Garden'])]),
            ['create_categories' => '1'],
        );

        expect($preview['invalid_rows'])->toBe(0)
            ->and($preview['importable_rows'])->toBe(3)
            ->and($preview['notes'])->toBe(['2 new categories will be created (Toys, Garden) with a service level of 95%. You can change that on the Categories page.']);
    });

    it('mentions archived products', function () {
        $this->nails->update(['is_active' => false]);

        $skipping = productPreview(productsCsv([newProductRow('HW-001')]));
        $updating = productPreview(productsCsv([newProductRow('HW-001')]), ['existing_skus' => 'update']);

        expect($skipping['notes'])->toBe(['1 archived product in the file will stay archived. Choose "Update them" to bring it back.'])
            ->and($updating['notes'])->toBe(['1 archived product will be brought back by the update.']);
    });

    it('explains that opening stock is for new products only', function () {
        $preview = productPreview(productsCsv([newProductRow('HW-001', [11 => 50])]), ['existing_skus' => 'update']);

        expect($preview['notes'])->toBe(['Opening stock is only used for new products. Stock of products that already exist changes through restocks and stock-takes.']);
    });

    it('saves nothing', function () {
        productPreview(
            productsCsv([newProductRow('NEW-1', [2 => 'Toys', 11 => 30]), newProductRow('HW-001')]),
            ['create_categories' => '1', 'existing_skus' => 'update'],
        );

        expect(Product::count())->toBe(1)
            ->and(Category::count())->toBe(1)
            ->and(StockMovement::count())->toBe(1)
            ->and($this->nails->fresh()->unit_price)->toBe('85.00');
    });

    it('offers the columns and the choices', function () {
        $batch = uploadProducts(productsCsv([newProductRow('NEW-1')]));

        $this->actingAs($this->user)
            ->get(route('products.imports.show', $batch))
            ->assertInertia(fn ($page) => $page
                ->component('products/imports/show')
                ->where('batch.status', 'preview')
                ->where('batch.headers', PRODUCT_HEADER)
                ->where('fields.0', ['key' => 'sku', 'label' => 'SKU (product code)', 'required' => true])
                ->where('fields.3.required', false)
                ->has('fields', 12)
                ->where('options.0.name', 'existing_skus')
                ->where('options.1.name', 'create_categories')
                ->where('options.1.kind', 'checkbox')
                ->where('batch.options', ['existing_skus' => 'skip', 'create_categories' => '0'])
                ->where('batch.links.confirm', route('products.imports.confirm', $batch, false)));
    });

    it('changes with the chosen columns and choices', function () {
        $batch = uploadProducts(productsCsv([['hw-001', 'Nails', 'Hardware']], ['code', 'label', 'dept']));

        $this->actingAs($this->user)->put(route('products.imports.update', $batch), [
            'columns' => ['sku' => 0, 'name' => 1, 'category' => 2, 'unit' => '', 'unit_price' => ''],
            'existing_skus' => 'update',
            'create_categories' => '1',
        ])->assertRedirect();

        $preview = $this->actingAs($this->user)->get(route('products.imports.show', $batch))->viewData('page')['props']['preview'];

        expect($batch->fresh()->settings['columns'])->toMatchArray(['sku' => 0, 'name' => 1, 'category' => 2, 'unit' => null, 'unit_price' => null])
            ->and($batch->fresh()->settings['existing_skus'])->toBe('update')
            ->and($batch->fresh()->settings['create_categories'])->toBeTrue()
            ->and(array_column($preview['figures'], 'value', 'label'))->toMatchArray(['Will be updated' => 1]);
    });

    it('rejects a column that is not in the file or a choice that does not exist', function (array $change, string $field) {
        $batch = uploadProducts(productsCsv([newProductRow('NEW-1')]));

        $this->actingAs($this->user)
            ->put(route('products.imports.update', $batch), array_replace_recursive([
                'columns' => ['sku' => 0, 'name' => 1, 'category' => 2],
                'existing_skus' => 'skip',
                'create_categories' => '0',
            ], $change))
            ->assertSessionHasErrors($field);
    })->with([
        'a column past the end' => [['columns' => ['sku' => 99]], 'columns.sku'],
        'a negative column' => [['columns' => ['name' => -1]], 'columns.name'],
        'an unknown choice' => [['existing_skus' => 'merge'], 'existing_skus'],
    ]);
});

describe('importing', function () {
    it('creates the products, with defaults for what the file leaves out', function () {
        $batch = importProducts(productsCsv([
            ['new-1', 'Roofing nails', 'hardware', '', '', '', '', '', '', '', '', ''],
            newProductRow('NEW-2', [1 => 'Cement', 3 => 'bag', 4 => '210.50', 5 => '₱265', 6 => 14, 7 => 10, 8 => 10, 9 => 40, 10 => 20]),
        ]));

        $plain = Product::where('sku', 'NEW-1')->firstOrFail();
        $full = Product::where('sku', 'NEW-2')->firstOrFail();

        expect($batch->status)->toBe(ImportStatus::Completed)
            ->and($batch->rows_ok)->toBe(2)
            ->and($batch->rows_updated)->toBe(0)
            ->and($batch->rows_failed)->toBe(0)
            ->and($plain->only(['name', 'category_id', 'unit', 'unit_cost', 'unit_price', 'lead_time_days', 'moq', 'pack_size', 'reorder_point_override', 'safety_stock_override', 'is_active', 'import_batch_id']))
            ->toBe([
                'name' => 'Roofing nails', 'category_id' => $this->hardware->id, 'unit' => 'pc', 'unit_cost' => '0.00', 'unit_price' => '0.00',
                'lead_time_days' => 7, 'moq' => 1, 'pack_size' => 1, 'reorder_point_override' => null, 'safety_stock_override' => null,
                'is_active' => true, 'import_batch_id' => $batch->id,
            ])
            ->and($full->only(['name', 'unit', 'unit_cost', 'unit_price', 'lead_time_days', 'moq', 'pack_size', 'reorder_point_override', 'safety_stock_override']))
            ->toBe([
                'name' => 'Cement', 'unit' => 'bag', 'unit_cost' => '210.50', 'unit_price' => '265.00',
                'lead_time_days' => 14, 'moq' => 10, 'pack_size' => 10, 'reorder_point_override' => 40, 'safety_stock_override' => 20,
            ]);
    });

    it('records opening stock in the ledger, tied to the import', function () {
        $batch = importProducts(productsCsv([
            newProductRow('NEW-1', [11 => 120]),
            newProductRow('NEW-2', [11 => 0]),
            newProductRow('NEW-3', [11 => '']),
        ]));

        $first = Product::where('sku', 'NEW-1')->firstOrFail();
        $movements = StockMovement::where('product_id', $first->id)->get();

        expect($movements)->toHaveCount(1)
            ->and($movements[0]->type)->toBe(StockMovementType::Initial)
            ->and($movements[0]->quantity)->toBe(120)
            ->and($movements[0]->reference_type)->toBe($batch->getMorphClass())
            ->and($movements[0]->reference_id)->toBe($batch->id)
            ->and($movements[0]->user_id)->toBe($this->user->id)
            ->and(onHandOf($first))->toBe(120)
            // No stock, no movement: zero and blank both mean none.
            ->and(StockMovement::whereIn('product_id', Product::whereIn('sku', ['NEW-2', 'NEW-3'])->pluck('id'))->count())->toBe(0);
    });

    it('keeps every stock level equal to the sum of its movements', function () {
        importProducts(productsCsv([newProductRow('NEW-1', [11 => 120]), newProductRow('NEW-2', [11 => 7]), newProductRow('NEW-3', [11 => 1])]));

        foreach (Product::all() as $product) {
            $sum = (int) StockMovement::where('product_id', $product->id)->sum('quantity');

            expect(onHandOf($product))->toBe($sum);
        }
    });

    it('stores SKUs in capitals and finds categories whatever their capitals', function () {
        importProducts(productsCsv([newProductRow('new-1', [2 => 'HARDWARE'])]));

        expect(Product::where('sku', 'NEW-1')->firstOrFail()->category_id)->toBe($this->hardware->id);
    });

    it('skips products that already exist, leaving them exactly as they are', function () {
        $batch = importProducts(productsCsv([
            newProductRow('HW-001', [1 => 'Renamed', 5 => '999', 11 => 500]),
            newProductRow('NEW-1'),
        ]));

        expect($batch->rows_ok)->toBe(1)
            ->and($batch->rows_duplicate)->toBe(1)
            ->and($batch->rows_failed)->toBe(0)
            ->and($this->nails->fresh()->only(['name', 'unit_price']))->toBe(['name' => 'Nails', 'unit_price' => '85.00'])
            ->and(onHandOf($this->nails))->toBe(100);
    });

    it('updates products that already exist, changing only what the row gives', function () {
        $batch = importProducts(productsCsv([
            // New price and lead time; every other cell blank, so the product keeps its own.
            ['hw-001', '', '', '', '', '95', 6, '', '', '', '', 500],
            newProductRow('NEW-1'),
        ]), ['existing_skus' => 'update']);

        $nails = $this->nails->fresh();

        expect($batch->rows_ok)->toBe(2)
            ->and($batch->rows_updated)->toBe(1)
            ->and($batch->rows_duplicate)->toBe(0)
            ->and($nails->only(['name', 'category_id', 'unit', 'unit_cost', 'unit_price', 'lead_time_days']))->toBe([
                'name' => 'Nails', 'category_id' => $this->hardware->id, 'unit' => 'kg', 'unit_cost' => '40.00', 'unit_price' => '95.00', 'lead_time_days' => 6,
            ])
            // Opening stock is for new products; this one keeps the stock it has.
            ->and(onHandOf($nails))->toBe(100)
            ->and(StockMovement::where('product_id', $nails->id)->count())->toBe(1);
    });

    it('lets the last row win when a product is updated twice', function () {
        importProducts(productsCsv([
            ['HW-001', '', '', '', '', '90', '', '', '', '', '', ''],
            ['HW-001', '', '', '', '', '95', '', '', '', '', '', ''],
            ['HW-001', 'Nails (box)', '', '', '', '', '', '', '', '', '', ''],
        ]), ['existing_skus' => 'update']);

        expect($this->nails->fresh()->only(['name', 'unit_price']))->toBe(['name' => 'Nails (box)', 'unit_price' => '95.00']);
    });

    it('brings back an archived product that is updated', function () {
        $this->nails->update(['is_active' => false]);

        importProducts(productsCsv([['HW-001', '', '', '', '', '', '', '', '', '', '', '']]), ['existing_skus' => 'update']);

        expect($this->nails->fresh()->is_active)->toBeTrue();
    });

    it('leaves an archived product archived when skipping', function () {
        $this->nails->update(['is_active' => false]);

        importProducts(productsCsv([newProductRow('HW-001')]));

        expect($this->nails->fresh()->is_active)->toBeFalse();
    });

    it('creates categories that do not exist yet, once each, when asked to', function () {
        importProducts(productsCsv([
            newProductRow('NEW-1', [2 => 'Toys']),
            newProductRow('NEW-2', [2 => 'TOYS']),   // same slice as the first
            newProductRow('NEW-3', [2 => 'toys']),   // the next slice: finds the category the first one made
            newProductRow('NEW-4', [2 => 'Garden']),
        ]), ['create_categories' => '1']);

        $toys = Category::where('name', 'Toys')->get();

        expect($toys)->toHaveCount(1)
            ->and($toys[0]->service_level)->toBe('95.00')
            ->and(Category::count())->toBe(3)
            ->and(Product::whereIn('sku', ['NEW-1', 'NEW-2', 'NEW-3'])->pluck('category_id')->unique()->all())->toBe([$toys[0]->id]);
    });

    it('reports a missing category as a problem otherwise, and creates nothing for that row', function () {
        $batch = importProducts(productsCsv([newProductRow('NEW-1', [2 => 'Toys']), newProductRow('NEW-2')]));

        expect($batch->rows_ok)->toBe(1)
            ->and($batch->rows_failed)->toBe(1)
            ->and($batch->errors)->toBe([['row' => 2, 'messages' => ["Unknown category 'Toys'."]]])
            ->and(Product::where('sku', 'NEW-1')->exists())->toBeFalse()
            ->and(Category::where('name', 'Toys')->exists())->toBeFalse();
    });

    it('accepts the first of a repeated SKU, even when the repeat is in a later slice', function () {
        // Slices of two rows: row 2 is in the first, row 4 in the second.
        $batch = importProducts(productsCsv([
            newProductRow('NEW-1', [1 => 'First']),
            newProductRow('NEW-2'),
            newProductRow('NEW-1', [1 => 'Second']),
            newProductRow('NEW-3'),
        ]));

        expect($batch->rows_ok)->toBe(3)
            ->and($batch->rows_failed)->toBe(1)
            ->and($batch->errors)->toBe([['row' => 4, 'messages' => ["SKU 'NEW-1' appears more than once in the file."]]])
            ->and(Product::where('sku', 'NEW-1')->value('name'))->toBe('First');
    });

    it('imports the good rows and reports the bad ones, across slices', function () {
        $batch = importProducts(productsCsv([
            newProductRow('NEW-1'),
            newProductRow('NEW-2', [5 => 'free']),
            newProductRow('NEW-3', [1 => '']),
            newProductRow('NEW-4'),
            newProductRow('NEW-5', [6 => 999]),
        ]));

        expect($batch->status)->toBe(ImportStatus::Completed)
            ->and($batch->rows_total)->toBe(5)
            ->and($batch->rows_processed)->toBe(5)
            ->and($batch->rows_ok)->toBe(2)
            ->and($batch->rows_failed)->toBe(3)
            ->and(collect($batch->errors)->pluck('row')->all())->toBe([3, 4, 6])
            ->and(Product::orderBy('sku')->pluck('sku')->all())->toBe(['HW-001', 'NEW-1', 'NEW-4']);
    });

    it('keeps the failed rows for the error report', function () {
        $batch = importProducts(productsCsv([newProductRow('NEW-1', [5 => 'free']), newProductRow('NEW-2')]));

        $csv = $this->actingAs($this->user)->get(route('products.imports.errors', $batch))
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=utf-8')->streamedContent();
        $lines = array_map('str_getcsv', array_filter(explode("\n", $csv)));

        expect($lines[0])->toBe(['Row', 'Problem', ...PRODUCT_HEADER])
            ->and($lines[1][0])->toBe('2')
            ->and($lines[1][1])->toBe("Selling price 'free' is not a number.")
            ->and($lines[1][2])->toBe('NEW-1')
            ->and($lines)->toHaveCount(2);
    });

    it('queues the work on the imports queue, a slice at a time', function () {
        Queue::fake();

        $batch = uploadProducts(productsCsv([newProductRow('NEW-1')]));
        $this->actingAs($this->user)->post(route('products.imports.confirm', $batch))->assertRedirect();

        Queue::assertPushedOn('imports', ProcessImportChunk::class, fn ($job) => $job->batchId === $batch->id && $job->offset === 0);
        expect($batch->fresh()->status)->toBe(ImportStatus::Queued)
            ->and(Product::count())->toBe(1);
    });

    it('does nothing the second time a slice is run', function () {
        $batch = importProducts(productsCsv([newProductRow('NEW-1'), newProductRow('NEW-2')]));

        $more = app(ProductImportProcessor::class)->processNext($batch->fresh(), 0);

        expect($more)->toBeFalse()
            ->and(Product::count())->toBe(3)
            ->and($batch->fresh()->rows_ok)->toBe(2);
    });

    it('will not start until the required columns are chosen', function () {
        $batch = uploadProducts(productsCsv([['a', 'b']], ['foo', 'bar']));

        $this->actingAs($this->user)
            ->post(route('products.imports.confirm', $batch))
            ->assertSessionHasErrors(['columns' => 'Choose a column for: SKU (product code), Product name, Category.']);

        expect($batch->fresh()->status)->toBe(ImportStatus::Preview);
    });

    it('cannot be started twice', function () {
        $batch = importProducts(productsCsv([newProductRow('NEW-1')]));

        $this->actingAs($this->user)->post(route('products.imports.confirm', $batch))->assertSessionHasErrors('import');
        expect(Product::count())->toBe(2);
    });

    it('logs the products it creates and updates against the person who uploaded the file', function () {
        $batch = uploadProducts(productsCsv([newProductRow('NEW-1'), ['HW-001', '', '', '', '', '99', '', '', '', '', '', '']]), ['existing_skus' => 'update']);

        $before = Activity::max('id') ?? 0;

        // The queue has nobody signed in.
        auth()->guard('web')->forgetUser();
        app(ProductImportProcessor::class)->processNext($batch, 0);

        $activities = Activity::where('id', '>', $before)->where('subject_type', (new Product)->getMorphClass())->whereIn('event', ['created', 'updated'])->get();
        $created = $activities->firstWhere('event', 'created');
        $updated = $activities->firstWhere('event', 'updated');

        expect($created->causer_id)->toBe($this->user->id)
            ->and($updated->causer_id)->toBe($this->user->id)
            ->and($updated->subject_id)->toBe($this->nails->id);
    });

    it('logs that the import started', function () {
        importProducts(productsCsv([newProductRow('NEW-1')]));

        expect(Activity::where('event', 'import_started')->where('log_name', 'products')->first()->causer_id)->toBe($this->user->id);
    });
});

describe('after the import', function () {
    it('shows the result and what can be done', function () {
        $batch = importProducts(productsCsv([newProductRow('NEW-1'), newProductRow('HW-001'), newProductRow('NEW-2', [5 => 'free'])]));

        $this->actingAs($this->user)
            ->get(route('products.imports.show', $batch))
            ->assertInertia(fn ($page) => $page
                ->component('products/imports/show')
                ->where('batch.status', 'completed')
                ->where('batch.can_undo', true)
                ->where('batch.rows_ok', 1)
                ->where('batch.rows_duplicate', 1)
                ->where('batch.rows_failed', 1)
                ->where('batch.has_error_report', true)
                ->where('batch.result_figures', [
                    ['label' => 'Created', 'value' => 1],
                    ['label' => 'Updated', 'value' => 0],
                    ['label' => 'Already there, skipped', 'value' => 1],
                    ['label' => 'Failed', 'value' => 1],
                ])
                ->where('batch.results', ['url' => route('products.index', ['import' => $batch->id]), 'label' => 'View the imported products'])
                ->missing('preview'));
    });

    it('lists only what the import created', function () {
        $batch = importProducts(productsCsv([newProductRow('NEW-1'), newProductRow('NEW-2')]));

        $this->actingAs($this->user)
            ->get(route('products.index', ['import' => $batch->id]))
            ->assertInertia(fn ($page) => $page
                ->where('filters.import', $batch->id)
                ->has('products.data', 2)
                ->where('products.data.0.sku', fn ($sku) => str_starts_with($sku, 'NEW-')));
    });

    it('lists past product imports, newest first, and not sales imports', function () {
        ImportBatch::create([
            'type' => ImportType::Sales, 'filename' => 'sales.csv', 'status' => ImportStatus::Completed,
            'settings' => ['headers' => [], 'columns' => []],
        ]);
        importProducts(productsCsv([newProductRow('NEW-1')], name: 'first.csv'));
        importProducts(productsCsv([newProductRow('NEW-2')], name: 'second.csv'));

        $this->actingAs($this->user)
            ->get(route('products.imports.index'))
            ->assertInertia(fn ($page) => $page
                ->component('products/imports/index')
                ->has('batches.data', 2)
                ->where('batches.data.0.filename', 'second.csv')
                ->where('batches.data.1.filename', 'first.csv')
                ->where('links.create', route('products.imports.create', absolute: false)));
    });

    it('does not offer an undo for an import that only updated products', function () {
        $batch = importProducts(productsCsv([newProductRow('HW-001', [5 => 99])]), ['existing_skus' => 'update']);

        $this->actingAs($this->user)
            ->get(route('products.imports.show', $batch))
            ->assertInertia(fn ($page) => $page->where('batch.can_undo', false)->where('batch.results', null));
    });
});

describe('cancelling', function () {
    it('throws away a file that has not been imported', function () {
        $batch = uploadProducts(productsCsv([newProductRow('NEW-1')]));

        $this->actingAs($this->user)->delete(route('products.imports.cancel', $batch))->assertRedirect(route('products.imports.index'));

        expect(ImportBatch::count())->toBe(0)
            ->and(Storage::disk('local')->exists($batch->rowsPath()))->toBeFalse();
    });

    it('cannot cancel an import that has run', function () {
        $batch = importProducts(productsCsv([newProductRow('NEW-1')]));

        $this->actingAs($this->user)->delete(route('products.imports.cancel', $batch))->assertSessionHasErrors('import');

        expect(ImportBatch::count())->toBe(1);
    });
});

describe('undoing', function () {
    it('archives the products the import created, and only those', function () {
        $batch = importProducts(productsCsv([newProductRow('NEW-1'), newProductRow('NEW-2'), newProductRow('HW-001')]));

        $this->actingAs($this->user)->post(route('products.imports.undo', $batch))->assertRedirect();

        expect($batch->fresh()->status)->toBe(ImportStatus::Undone)
            ->and(Product::where('sku', 'NEW-1')->value('is_active'))->toBeFalse()
            ->and(Product::where('sku', 'NEW-2')->value('is_active'))->toBeFalse()
            ->and($this->nails->fresh()->is_active)->toBeTrue()
            // Products are archived, never deleted, so their history stays whole.
            ->and(Product::count())->toBe(3);
    });

    it('does not revert changes made to products that already existed', function () {
        $batch = importProducts(productsCsv([newProductRow('NEW-1'), newProductRow('HW-001', [5 => 95])]), ['existing_skus' => 'update']);

        $this->actingAs($this->user)->post(route('products.imports.undo', $batch));

        expect($this->nails->fresh()->unit_price)->toBe('95.00')
            ->and($this->nails->fresh()->is_active)->toBeTrue();
    });

    it('says what it will do', function () {
        $batch = importProducts(productsCsv([newProductRow('NEW-1'), newProductRow('HW-001', [5 => 95])]), ['existing_skus' => 'update']);

        $this->actingAs($this->user)
            ->get(route('products.imports.show', $batch))
            ->assertInertia(fn ($page) => $page->where('batch.undo_description', 'This archives the 1 product it created. Archived products are hidden from lists, and keep their history. Changes it made to the 1 product it updated are not reverted. To bring them back, upload the file again and choose to update existing SKUs.'));
    });

    it('lets the same file be uploaded again to bring the products back', function () {
        $first = importProducts(productsCsv([newProductRow('NEW-1', [1 => 'Fixed name'])]));
        $this->actingAs($this->user)->post(route('products.imports.undo', $first));

        importProducts(productsCsv([newProductRow('NEW-1', [1 => 'Fixed name'])]), ['existing_skus' => 'update']);

        expect(Product::where('sku', 'NEW-1')->firstOrFail()->is_active)->toBeTrue()
            ->and(Product::where('sku', 'NEW-1')->count())->toBe(1);
    });

    it('cannot be done twice or before the import has run', function () {
        $pending = uploadProducts(productsCsv([newProductRow('NEW-1')]));
        $this->actingAs($this->user)->post(route('products.imports.undo', $pending))->assertSessionHasErrors('import');

        $done = importProducts(productsCsv([newProductRow('NEW-2')]));
        $this->actingAs($this->user)->post(route('products.imports.undo', $done))->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post(route('products.imports.undo', $done))->assertSessionHasErrors('import');
    });

    it('logs who undid it', function () {
        $batch = importProducts(productsCsv([newProductRow('NEW-1')]));

        $this->actingAs($this->user)->post(route('products.imports.undo', $batch));

        $entry = Activity::where('event', 'import_undone')->firstOrFail();

        expect($entry->causer_id)->toBe($this->user->id)
            ->and($entry->log_name)->toBe('products')
            ->and($entry->subject_id)->toBe($batch->id);
    });
});

describe('the two kinds of import', function () {
    it('keep to their own pages', function () {
        $sales = ImportBatch::create([
            'type' => ImportType::Sales, 'filename' => 'sales.csv', 'status' => ImportStatus::Preview,
            'settings' => ['headers' => ['a'], 'columns' => ['date' => 0], 'date_format' => 'iso', 'adjust_stock' => false],
        ]);
        $products = ImportBatch::create([
            'type' => ImportType::Products, 'filename' => 'products.csv', 'status' => ImportStatus::Preview,
            'settings' => ['headers' => ['a'], 'columns' => ['sku' => 0], 'existing_skus' => 'skip', 'create_categories' => false],
        ]);

        // Everyone may import sales, but only managers may import products, so an
        // import must never be reachable through the other kind's address.
        $staff = User::factory()->inventoryStaff()->create();
        $this->actingAs($staff)->get(route('sales.imports.show', $products))->assertNotFound();
        $this->actingAs($this->user)->get(route('products.imports.show', $sales))->assertNotFound();
        $this->actingAs($this->user)->post(route('products.imports.confirm', $sales))->assertNotFound();
        $this->actingAs($this->user)->delete(route('products.imports.cancel', $sales))->assertNotFound();
        $this->actingAs($this->user)->post(route('products.imports.undo', $sales))->assertNotFound();
        $this->actingAs($this->user)->get(route('products.imports.errors', $sales))->assertNotFound();

        expect($sales->fresh())->not->toBeNull();
    });

    it('turn away anyone who may not manage the catalog', function () {
        $staff = User::factory()->inventoryStaff()->create();

        $this->actingAs($staff)->get(route('products.imports.create'))->assertForbidden();
        $this->actingAs($staff)->post(route('products.imports.store'), ['file' => productsCsv([newProductRow('NEW-1')])])->assertForbidden();

        expect(ImportBatch::count())->toBe(0);
    });
});

describe('the template', function () {
    it('is a CSV with the right columns', function () {
        $csv = $this->actingAs($this->user)->get(route('products.imports.template'))
            ->assertOk()->assertHeader('content-disposition', 'attachment; filename=products-import-template.csv')->streamedContent();
        $lines = array_map('str_getcsv', array_filter(explode("\n", $csv)));

        expect($lines[0])->toBe(PRODUCT_HEADER)
            ->and($lines)->toHaveCount(3)
            // Uses a category that really exists in this catalogue.
            ->and($lines[1][2])->toBe('Hardware');
    });

    it('imports cleanly as downloaded', function () {
        $csv = $this->actingAs($this->user)->get(route('products.imports.template'))->streamedContent();

        $batch = importProducts(UploadedFile::fake()->createWithContent('template.csv', $csv), ['create_categories' => '1']);

        expect($batch->rows_failed)->toBe(0)
            ->and($batch->rows_ok)->toBe(2)
            ->and(Product::whereIn('sku', ['SKU-1001', 'SKU-1002'])->count())->toBe(2);
    });
});
