<?php

namespace App\Services\Sales;

use App\Models\ImportBatch;
use App\Models\Product;
use Carbon\CarbonImmutable;

/**
 * Checks a whole uploaded file against the chosen column mapping, without
 * saving anything, so the person can see what would happen before they
 * confirm.
 */
class SalesImportPreview
{
    private const SAMPLE_ROWS = 15;

    private const LISTED_PROBLEMS = 20;

    public function __construct(private readonly DuplicateFinder $duplicates) {}

    /**
     * @return array{
     *     total_rows: int,
     *     importable_rows: int,
     *     duplicate_rows: int,
     *     invalid_rows: int,
     *     sample: list<array{row: int, cells: list<mixed>, status: string, messages: list<string>}>,
     *     problems: list<array{row: int, messages: list<string>}>
     * }
     */
    public function build(ImportBatch $batch): array
    {
        $settings = $batch->settings;
        $rows = iterator_to_array((new ImportRowsFile($batch))->read(), false);

        $products = Product::query()
            ->whereIn('sku', SalesRowValidator::skusIn(array_column($rows, 1), $settings['columns']))
            ->get()
            ->keyBy('sku')
            ->all();

        $validator = new SalesRowValidator($products, $settings['columns'], $settings['date_format'], CarbonImmutable::today());

        /** @var array<int, ParsedSalesRow> $valid Keyed by row number */
        $valid = [];
        /** @var array<int, list<string>> $invalid Keyed by row number */
        $invalid = [];

        foreach ($rows as [$number, $cells]) {
            $result = $validator->validate($number, $cells);

            if ($result instanceof ParsedSalesRow) {
                $valid[$number] = $result;
            } else {
                $invalid[$number] = $result;
            }
        }

        $known = [];
        foreach (array_chunk($valid, 1000) as $slice) {
            $known += $this->duplicates->existing($slice, $batch->id);
        }

        $duplicateRows = array_filter($valid, fn (ParsedSalesRow $row) => isset($known[$row->duplicateKey()]));

        $sample = [];
        foreach (array_slice($rows, 0, self::SAMPLE_ROWS) as [$number, $cells]) {
            $sample[] = [
                'row' => $number,
                'cells' => $cells,
                'status' => isset($invalid[$number]) ? 'error' : (isset($duplicateRows[$number]) ? 'duplicate' : 'ok'),
                'messages' => $invalid[$number] ?? [],
            ];
        }

        $problems = [];
        foreach (array_slice($invalid, 0, self::LISTED_PROBLEMS, true) as $number => $messages) {
            $problems[] = ['row' => $number, 'messages' => $messages];
        }

        return [
            'total_rows' => count($rows),
            'importable_rows' => count($valid) - count($duplicateRows),
            'duplicate_rows' => count($duplicateRows),
            'invalid_rows' => count($invalid),
            'sample' => $sample,
            'problems' => $problems,
        ];
    }
}
