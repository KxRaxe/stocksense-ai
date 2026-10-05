<?php

namespace App\Services\Sales;

use App\Models\ImportBatch;
use App\Models\Product;
use App\Services\Imports\ImportProcessor;
use Carbon\CarbonImmutable;

/**
 * Imports the next slice of a sales file: validates each row, skips the ones
 * that were imported before, and records the rest as sales.
 */
class SalesImportProcessor extends ImportProcessor
{
    public function __construct(
        private readonly SalesService $sales,
        private readonly DuplicateFinder $duplicates,
    ) {}

    protected function importSlice(ImportBatch $batch, array $rows): array
    {
        $settings = $batch->settings;

        $products = Product::query()
            ->whereIn('sku', SalesRowValidator::skusIn(array_column($rows, 1), $settings['columns']))
            ->get()
            ->keyBy('sku')
            ->all();

        $validator = new SalesRowValidator($products, $settings['columns'], (string) $batch->option('date_format'), CarbonImmutable::today());

        [$valid, $invalid] = $validator->validateAll($rows);
        $parsed = array_values($valid);
        $failed = $this->failedRows($rows, $invalid);

        $known = $this->duplicates->existing($parsed, $batch->id);

        $new = array_values(array_filter($parsed, fn (ParsedSalesRow $row) => ! isset($known[$row->duplicateKey()])));
        $imported = count($new);
        $skipped = count($parsed) - $imported;

        $this->sales->recordMany($new, $batch, $batch->user, (bool) $batch->option('adjust_stock'));

        $batch->forceFill([
            'rows_processed' => $batch->rows_processed + count($rows),
            'rows_ok' => $batch->rows_ok + $imported,
            'rows_duplicate' => $batch->rows_duplicate + $skipped,
            'rows_failed' => $batch->rows_failed + count($failed),
            'errors' => $this->keptErrors($batch, $failed),
        ])->save();

        return $failed;
    }
}
