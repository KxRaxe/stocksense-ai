<?php

namespace App\Services\Sales;

use App\Models\ImportBatch;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Imports the next slice of a file: validates each row, skips the ones that
 * were imported before, and records the rest as sales. A slice is all-or-
 * nothing, and the batch remembers how far it got, so a slice that is run
 * twice does no harm.
 */
class SalesImportProcessor
{
    public function __construct(
        private readonly SalesService $sales,
        private readonly DuplicateFinder $duplicates,
    ) {}

    /**
     * @param  int  $offset  Where this slice starts; ignored if the batch has already got past it
     * @return bool Whether rows remain after this slice
     */
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

        $failed = DB::transaction(fn () => $this->importSlice($batch, $rows));

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
            ->whereIn('sku', SalesRowValidator::skusIn(array_column($rows, 1), $settings['columns']))
            ->get()
            ->keyBy('sku')
            ->all();

        $validator = new SalesRowValidator($products, $settings['columns'], $settings['date_format'], CarbonImmutable::today());

        $parsed = [];
        $failed = [];

        foreach ($rows as [$number, $cells]) {
            $result = $validator->validate($number, $cells);

            if ($result instanceof ParsedSalesRow) {
                $parsed[] = $result;
            } else {
                $failed[] = ['row' => $number, 'messages' => $result, 'cells' => $cells];
            }
        }

        $known = $this->duplicates->existing($parsed, $batch->id);

        $new = array_values(array_filter($parsed, fn (ParsedSalesRow $row) => ! isset($known[$row->duplicateKey()])));
        $imported = count($new);
        $skipped = count($parsed) - $imported;

        $this->sales->recordMany($new, $batch, $batch->user, $settings['adjust_stock']);

        $kept = $batch->errors ?? [];
        foreach ($failed as $problem) {
            if (count($kept) >= config('imports.stored_errors')) {
                break;
            }

            $kept[] = ['row' => $problem['row'], 'messages' => $problem['messages']];
        }

        $batch->forceFill([
            'rows_processed' => $batch->rows_processed + count($rows),
            'rows_ok' => $batch->rows_ok + $imported,
            'rows_duplicate' => $batch->rows_duplicate + $skipped,
            'rows_failed' => $batch->rows_failed + count($failed),
            'errors' => $kept === [] ? null : $kept,
        ])->save();

        return $failed;
    }
}
