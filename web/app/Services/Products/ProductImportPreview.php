<?php

namespace App\Services\Products;

use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Product;
use App\Services\Imports\ImportRowsFile;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * Checks a whole uploaded product file against the chosen column mapping and
 * options, without saving anything, so the person can see what would happen
 * before they confirm.
 */
class ProductImportPreview
{
    private const SAMPLE_ROWS = 15;

    private const LISTED_PROBLEMS = 20;

    private const LISTED_CATEGORIES = 10;

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
        $updating = $batch->option('existing_skus') === 'update';
        $rows = iterator_to_array((new ImportRowsFile($batch))->read(), false);

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
            $updating,
            (bool) $batch->option('create_categories'),
            $batch->id,
        );

        /** @var array<int, ParsedProductRow> $valid Keyed by row number */
        $valid = [];
        /** @var array<int, list<string>> $invalid Keyed by row number */
        $invalid = [];

        foreach ($rows as [$number, $cells]) {
            $result = $validator->validate($number, $cells);

            if ($result instanceof ParsedProductRow) {
                $valid[$number] = $result;
            } else {
                $invalid[$number] = $result;
            }
        }

        $counts = [ParsedProductRow::CREATE => 0, ParsedProductRow::UPDATE => 0, ParsedProductRow::SKIP => 0];
        $newCategories = [];
        $restored = 0;

        foreach ($valid as $row) {
            $counts[$row->action]++;

            if ($row->newCategory !== null) {
                $newCategories[mb_strtolower($row->newCategory)] ??= $row->newCategory;
            }

            if ($row->action === ParsedProductRow::UPDATE && ($row->values['is_active'] ?? false) === true) {
                $restored++;
            }
        }

        // Opening stock is read for new products only; say so when a row gives one for a product that exists.
        $openingColumn = $settings['columns'][ProductImportFields::OPENING_STOCK] ?? null;
        $openingIgnored = 0;

        if ($openingColumn !== null) {
            foreach ($rows as [$number, $cells]) {
                $cell = trim((string) ($cells[$openingColumn] ?? ''));
                // A zero (or nothing) asks for no stock, so there is nothing to ignore.
                $given = $cell !== '' && ! (is_numeric($cell) && (float) $cell === 0.0);

                if ($given && ($valid[$number]->action ?? null) === ParsedProductRow::UPDATE) {
                    $openingIgnored++;
                }
            }
        }

        $archivedSkipped = $updating
            ? 0
            : count(array_filter($valid, fn (ParsedProductRow $row) => $row->action === ParsedProductRow::SKIP && $row->existing?->is_active === false));

        $importable = $counts[ParsedProductRow::CREATE] + $counts[ParsedProductRow::UPDATE];

        $sample = [];
        foreach (array_slice($rows, 0, self::SAMPLE_ROWS) as [$number, $cells]) {
            $row = $valid[$number] ?? null;

            $sample[] = [
                'row' => $number,
                'cells' => $cells,
                'status' => $row === null ? 'error' : ($row->action === ParsedProductRow::SKIP ? 'skip' : 'ok'),
                'label' => match ($row?->action) {
                    ParsedProductRow::CREATE => 'New product',
                    ParsedProductRow::UPDATE => 'Update',
                    ParsedProductRow::SKIP => 'Already exists',
                    default => '',
                },
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
                ['label' => 'Will be created', 'value' => $counts[ParsedProductRow::CREATE], 'tone' => 'good'],
                $updating
                    ? ['label' => 'Will be updated', 'value' => $counts[ParsedProductRow::UPDATE], 'tone' => 'good']
                    : ['label' => 'Already exist, skipped', 'value' => $counts[ParsedProductRow::SKIP], 'tone' => 'neutral'],
                ['label' => 'Have a problem, skipped', 'value' => count($invalid), 'tone' => 'warn'],
            ],
            'notes' => $this->notes($newCategories, $restored, $archivedSkipped, $openingIgnored),
            'sample' => $sample,
            'problems' => $problems,
            'invalid_rows' => count($invalid),
        ];
    }

    /**
     * @param  array<string, string>  $newCategories
     * @return list<string>
     */
    private function notes(array $newCategories, int $restored, int $archivedSkipped, int $openingIgnored): array
    {
        $notes = [];

        if ($newCategories !== []) {
            $shown = array_slice(array_values($newCategories), 0, self::LISTED_CATEGORIES);
            $more = count($newCategories) - count($shown);

            $notes[] = Number::format(count($newCategories)).' new '.Str::plural('category', count($newCategories))
                .' will be created ('.implode(', ', $shown).($more > 0 ? ", and {$more} more" : '').')'
                .' with a service level of '.(float) ProductImportFields::newCategoryServiceLevel().'%. You can change that on the Categories page.';
        }

        if ($restored > 0) {
            $notes[] = Number::format($restored).' archived '.Str::plural('product', $restored).' will be brought back by the update.';
        }

        if ($archivedSkipped > 0) {
            $notes[] = Number::format($archivedSkipped).' archived '.Str::plural('product', $archivedSkipped).' in the file will stay archived. Choose "Update them" to bring '.($archivedSkipped === 1 ? 'it' : 'them').' back.';
        }

        if ($openingIgnored > 0) {
            $notes[] = 'Opening stock is only used for new products. Stock of products that already exist changes through restocks and stock-takes.';
        }

        return $notes;
    }
}
