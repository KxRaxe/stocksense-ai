<?php

namespace App\Services\Sales;

use App\Models\ImportBatch;
use App\Models\Product;
use App\Services\Imports\ImportRowsFile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

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
     *     importable_rows: int,
     *     import_label: string,
     *     figures: list<array{label: string, value: int, tone: 'good'|'neutral'|'warn'}>,
     *     notes: list<string>,
     *     sample: list<array{row: int, cells: list<mixed>, status: 'ok'|'skip'|'error', label: string, messages: list<string>}>,
     *     problems: list<array{row: int, messages: list<string>}>,
     *     invalid_rows: int
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

        $validator = new SalesRowValidator($products, $settings['columns'], (string) $batch->option('date_format'), CarbonImmutable::today());

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
        $importable = count($valid) - count($duplicateRows);

        $sample = [];
        foreach (array_slice($rows, 0, self::SAMPLE_ROWS) as [$number, $cells]) {
            $isError = isset($invalid[$number]);
            $isRepeat = isset($duplicateRows[$number]);

            $sample[] = [
                'row' => $number,
                'cells' => $cells,
                'status' => $isError ? 'error' : ($isRepeat ? 'skip' : 'ok'),
                'label' => $isRepeat ? 'Already imported' : 'OK',
                'messages' => $invalid[$number] ?? [],
            ];
        }

        $problems = [];
        foreach (array_slice($invalid, 0, self::LISTED_PROBLEMS, true) as $number => $messages) {
            $problems[] = ['row' => $number, 'messages' => $messages];
        }

        return [
            'importable_rows' => $importable,
            'import_label' => 'Import '.Number::format($importable).' '.Str::plural('row', $importable),
            'figures' => [
                ['label' => 'Will be imported', 'value' => $importable, 'tone' => 'good'],
                ['label' => 'Already imported, skipped', 'value' => count($duplicateRows), 'tone' => 'neutral'],
                ['label' => 'Have a problem, skipped', 'value' => count($invalid), 'tone' => 'warn'],
            ],
            'notes' => [],
            'sample' => $sample,
            'problems' => $problems,
            'invalid_rows' => count($invalid),
        ];
    }
}
