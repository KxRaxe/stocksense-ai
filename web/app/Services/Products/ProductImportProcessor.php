<?php

namespace App\Services\Products;

use App\Enums\StockMovementType;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Product;
use App\Services\Imports\ImportProcessor;
use App\Services\Imports\ImportRowsFile;
use App\Services\Inventory\StockService;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Imports the next slice of a product file: validates each row, then creates
 * the new products (with their opening stock), updates or skips the ones that
 * already exist, as the person chose. A slice is all-or-nothing, and the batch
 * remembers how far it got, so a slice that is run twice does no harm.
 */
class ProductImportProcessor implements ImportProcessor
{
    public function __construct(private readonly StockService $stock) {}

    public function processNext(ImportBatch $batch, int $offset): bool
    {
        // Already done (for example a job that was retried): nothing to do.
        if ($batch->rows_processed !== $offset) {
            return $batch->rows_processed < $batch->rows_total;
        }

        $file = new ImportRowsFile($batch);
        $rows = iterator_to_array($file->read($offset, config('imports.chunk_size')), false);

        if ($rows === []) {
            return false;
        }

        // The queue has no signed-in person, so say whose changes these are for
        // the audit log.
        $failed = app(CauserResolver::class)->withCauser(
            $batch->user,
            fn () => DB::transaction(fn () => $this->importSlice($batch, $rows)),
        );

        // Written after the slice is safely saved, so a failed slice leaves no
        // half-finished report behind.
        $file->appendErrors($failed);

        return $batch->rows_processed < $batch->rows_total;
    }

    /**
     * @param  list<array{0: int, 1: list<mixed>}>  $rows
     * @return list<array{row: int, messages: list<string>, cells: list<mixed>}> The rows that failed
     */
    private function importSlice(ImportBatch $batch, array $rows): array
    {
        $settings = $batch->settings;

        $products = Product::query()
            ->whereIn('sku', ProductRowValidator::skusIn(array_column($rows, 1), $settings['columns']))
            ->get()
            ->keyBy('sku')
            ->all();

        /** @var array<string, int> $categories Ids keyed by lower-case name */
        $categories = Category::query()->get(['id', 'name'])->mapWithKeys(fn (Category $category) => [mb_strtolower($category->name) => $category->id])->all();

        $validator = new ProductRowValidator(
            $products,
            $categories,
            $settings['columns'],
            $batch->option('existing_skus') === 'update',
            (bool) $batch->option('create_categories'),
            $batch->id,
        );

        $parsed = [];
        $failed = [];

        foreach ($rows as [$number, $cells]) {
            $result = $validator->validate($number, $cells);

            if ($result instanceof ParsedProductRow) {
                $parsed[] = $result;
            } else {
                $failed[] = ['row' => $number, 'messages' => $result, 'cells' => $cells];
            }
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $openingStock = [];

        foreach ($parsed as $row) {
            if ($row->action === ParsedProductRow::SKIP) {
                $skipped++;

                continue;
            }

            $values = $row->values;

            if ($row->newCategory !== null) {
                $key = mb_strtolower($row->newCategory);

                $categories[$key] ??= Category::create([
                    'name' => $row->newCategory,
                    'service_level' => ProductImportFields::NEW_CATEGORY_SERVICE_LEVEL,
                ])->id;

                $values['category_id'] = $categories[$key];
            }

            if ($row->action === ParsedProductRow::UPDATE && $row->existing !== null) {
                // The same object for every row of this SKU, so two rows in one
                // slice build on each other and the last one wins.
                $row->existing->fill($values)->save();
                $updated++;

                continue;
            }

            $product = Product::create([...$values, 'import_batch_id' => $batch->id]);
            $created++;

            if ($row->openingStock > 0) {
                $openingStock[] = [
                    'product_id' => $product->id,
                    'type' => StockMovementType::Initial,
                    'quantity' => $row->openingStock,
                    'occurred_at' => now(),
                    'note' => "Import #{$batch->id}",
                    'user_id' => $batch->user_id,
                    'reference_type' => $batch->getMorphClass(),
                    'reference_id' => $batch->id,
                ];
            }
        }

        $this->stock->recordMany($openingStock);

        $kept = $batch->errors ?? [];
        foreach ($failed as $problem) {
            if (count($kept) >= config('imports.stored_errors')) {
                break;
            }

            $kept[] = ['row' => $problem['row'], 'messages' => $problem['messages']];
        }

        $batch->forceFill([
            'rows_processed' => $batch->rows_processed + count($rows),
            'rows_ok' => $batch->rows_ok + $created + $updated,
            'rows_updated' => $batch->rows_updated + $updated,
            'rows_duplicate' => $batch->rows_duplicate + $skipped,
            'rows_failed' => $batch->rows_failed + count($failed),
            'errors' => $kept === [] ? null : $kept,
        ])->save();

        return $failed;
    }
}
