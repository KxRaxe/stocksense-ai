<?php

namespace App\Services\Products;

use App\Models\Product;
use App\Services\Imports\RowProblem;

/**
 * Checks one row of an uploaded product file and says what the import will do
 * with it, or what is wrong with it. The limits are the ones the product form
 * enforces (see ProductRequest). Does not touch the database: the products and
 * categories it needs are handed in.
 *
 * Rows are checked in file order with one validator, because a row can depend
 * on the ones before it: a SKU that appears twice in the file is only valid
 * the first time.
 */
class ProductRowValidator
{
    private const MAX_MONEY = 9_999_999_999.99;

    /** @var array<string, true> New SKUs an earlier row of this file has already claimed */
    private array $claimed = [];

    /**
     * @param  array<string, Product>  $products  Products that already exist, keyed by upper-case SKU
     * @param  array<string, int>  $categories  Category ids keyed by lower-case name
     * @param  array<string, int|null>  $columns  Field => 0-based column number
     * @param  bool  $updateExisting  Whether a SKU that already exists updates that product (otherwise it is skipped)
     * @param  bool  $createCategories  Whether a category that does not exist yet is created
     * @param  int  $batchId  The import being checked: products it has already created count as earlier rows of the file
     */
    public function __construct(
        private readonly array $products,
        private readonly array $categories,
        private readonly array $columns,
        private readonly bool $updateExisting,
        private readonly bool $createCategories,
        private readonly int $batchId,
    ) {}

    /**
     * @param  list<mixed>  $cells
     * @return ParsedProductRow|list<string> The parsed row, or every problem found
     */
    public function validate(int $row, array $cells): ParsedProductRow|array
    {
        $sku = $this->parseSku($this->cell($cells, ProductImportFields::SKU));

        if ($sku instanceof RowProblem) {
            return [$sku->message];
        }

        $existing = $this->products[$sku] ?? null;

        if (isset($this->claimed[$sku]) || $existing?->import_batch_id === $this->batchId) {
            return ["SKU '{$sku}' appears more than once in the file."];
        }

        if ($existing !== null && ! $this->updateExisting) {
            return new ParsedProductRow($row, $sku, ParsedProductRow::SKIP, $existing);
        }

        $creating = $existing === null;
        $values = $creating ? ['sku' => $sku] : [];
        $problems = [];
        $newCategory = null;

        $name = $this->parseText($this->cell($cells, ProductImportFields::NAME), 'Product name', 255, $creating);
        $category = $this->parseCategory($this->cell($cells, ProductImportFields::CATEGORY), $creating);
        $unit = $this->parseText($this->cell($cells, ProductImportFields::UNIT), 'Unit', 20, false);
        $cost = $this->parseMoney($this->cell($cells, ProductImportFields::UNIT_COST), 'Unit cost');
        $price = $this->parseMoney($this->cell($cells, ProductImportFields::UNIT_PRICE), 'Selling price');
        $lead = $this->parseWhole($this->cell($cells, ProductImportFields::LEAD_TIME_DAYS), 'Lead time', 0, 365);
        $moq = $this->parseWhole($this->cell($cells, ProductImportFields::MOQ), 'Minimum order quantity', 1, 1_000_000);
        $pack = $this->parseWhole($this->cell($cells, ProductImportFields::PACK_SIZE), 'Pack size', 1, 1_000_000);
        $reorder = $this->parseWhole($this->cell($cells, ProductImportFields::REORDER_POINT), 'Reorder point', 0, 10_000_000);
        $safety = $this->parseWhole($this->cell($cells, ProductImportFields::SAFETY_STOCK), 'Safety stock', 0, 10_000_000);
        // Stock of a product that already exists changes through restocks and
        // stock-takes, so this column is not read for those.
        $opening = $creating
            ? $this->parseWhole($this->cell($cells, ProductImportFields::OPENING_STOCK), 'Opening stock', 0, 10_000_000)
            : null;

        $parsed = [
            'name' => $name,
            'category' => $category,
            'unit' => $unit,
            'unit_cost' => $cost,
            'unit_price' => $price,
            'lead_time_days' => $lead,
            'moq' => $moq,
            'pack_size' => $pack,
            'reorder_point_override' => $reorder,
            'safety_stock_override' => $safety,
            'opening_stock' => $opening,
        ];

        foreach ($parsed as $column => $result) {
            if ($result instanceof RowProblem) {
                $problems[] = $result->message;

                continue;
            }

            // Blank means "not given": a new product gets its default (below),
            // an existing one keeps what it has.
            if ($result === null) {
                continue;
            }

            if ($column === 'category') {
                if (is_int($result)) {
                    $values['category_id'] = $result;
                } else {
                    $newCategory = $result;
                }

                continue;
            }

            if ($column !== 'opening_stock') {
                $values[$column] = $result;
            }
        }

        if ($problems !== []) {
            return $problems;
        }

        // A product that was archived (or whose import was undone) comes back
        // when a file updates it: the person has just supplied it again.
        if ($existing !== null && ! $existing->is_active) {
            $values['is_active'] = true;
        }

        $openingStock = 0;

        if ($creating) {
            $this->claimed[$sku] = true;

            foreach (ProductImportFields::DEFAULTS as $field => $default) {
                $column = match ($field) {
                    ProductImportFields::REORDER_POINT => 'reorder_point_override',
                    ProductImportFields::SAFETY_STOCK => 'safety_stock_override',
                    default => $field,
                };

                if ($field !== ProductImportFields::OPENING_STOCK) {
                    $values[$column] ??= $default;
                }
            }

            $openingStock = is_int($opening) ? $opening : 0;
        }

        return new ParsedProductRow(
            $row,
            $sku,
            $creating ? ParsedProductRow::CREATE : ParsedProductRow::UPDATE,
            $existing,
            $values,
            $newCategory,
            $openingStock,
        );
    }

    /**
     * The upper-case SKUs a set of rows mentions, so their products can be
     * loaded in one query.
     *
     * @param  iterable<list<mixed>>  $rows
     * @param  array<string, int|null>  $columns
     * @return list<string>
     */
    public static function skusIn(iterable $rows, array $columns): array
    {
        $index = $columns[ProductImportFields::SKU] ?? null;

        if ($index === null) {
            return [];
        }

        $skus = [];

        foreach ($rows as $cells) {
            $sku = strtoupper(trim((string) ($cells[$index] ?? '')));

            if ($sku !== '') {
                $skus[$sku] = true;
            }
        }

        return array_keys($skus);
    }

    /**
     * @param  list<mixed>  $cells
     */
    private function cell(array $cells, string $field): mixed
    {
        $index = $this->columns[$field] ?? null;

        return $index === null ? null : ($cells[$index] ?? null);
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function parseSku(mixed $value): string|RowProblem
    {
        if ($this->isBlank($value)) {
            return new RowProblem('SKU is missing.');
        }

        $sku = strtoupper(trim((string) $value));

        return mb_strlen($sku) > 64 ? new RowProblem('SKU is too long (64 characters at most).') : $sku;
    }

    /**
     * @return string|RowProblem|null Null when blank and not required
     */
    private function parseText(mixed $value, string $label, int $max, bool $required): string|RowProblem|null
    {
        if ($this->isBlank($value)) {
            return $required ? new RowProblem("{$label} is missing.") : null;
        }

        $text = trim((string) $value);

        return mb_strlen($text) > $max ? new RowProblem("{$label} is too long ({$max} characters at most).") : $text;
    }

    /**
     * @return int|string|RowProblem|null The category's id, the name of one to create, or null when blank and not required
     */
    private function parseCategory(mixed $value, bool $required): int|string|RowProblem|null
    {
        $name = $this->parseText($value, 'Category', 100, $required);

        if ($name === null || $name instanceof RowProblem) {
            return $name;
        }

        return $this->categories[mb_strtolower($name)]
            ?? ($this->createCategories ? $name : new RowProblem("Unknown category '{$name}'."));
    }

    /**
     * @return numeric-string|RowProblem|null
     */
    private function parseMoney(mixed $value, string $label): string|RowProblem|null
    {
        if ($this->isBlank($value)) {
            return null;
        }

        // Allow a currency sign or code and thousands separators: "₱1,250.50".
        $text = is_string($value) ? preg_replace('/[^\d.\-]/u', '', str_replace(',', '', $value)) : $value;

        if ($text === '' || ! is_numeric($text)) {
            return new RowProblem("{$label} '{$value}' is not a number.");
        }

        $amount = (float) $text;

        if ($amount < 0) {
            return new RowProblem("{$label} cannot be negative.");
        }

        if ($amount > self::MAX_MONEY) {
            return new RowProblem("{$label} is too large.");
        }

        return number_format($amount, 2, '.', '');
    }

    private function parseWhole(mixed $value, string $label, int $min, int $max): int|RowProblem|null
    {
        if ($this->isBlank($value)) {
            return null;
        }

        $text = is_string($value) ? str_replace([',', ' '], '', trim($value)) : $value;

        if (! is_numeric($text)) {
            return new RowProblem("{$label} '{$value}' is not a number.");
        }

        $number = (float) $text;

        if (abs($number - round($number)) > 1e-9) {
            return new RowProblem("{$label} '{$value}' is not a whole number.");
        }

        $whole = (int) round($number);

        if ($whole < $min) {
            return new RowProblem("{$label} must be at least {$min}.");
        }

        if ($whole > $max) {
            return new RowProblem("{$label} is too large.");
        }

        return $whole;
    }
}
