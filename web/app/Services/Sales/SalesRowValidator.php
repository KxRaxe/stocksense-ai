<?php

namespace App\Services\Sales;

use App\Models\Product;
use App\Services\Imports\RowProblem;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Checks one row of an uploaded sales file and turns it into a
 * ParsedSalesRow, or says what is wrong with it. Does not touch the database:
 * the products it needs are handed in.
 */
class SalesRowValidator
{
    private const EARLIEST_DATE = '2000-01-01';

    private const MAX_QUANTITY = 1_000_000;

    private const MAX_PRICE = 9_999_999_999.99;

    /**
     * @param  array<string, Product>  $products  Products keyed by upper-case SKU
     * @param  array<string, int|null>  $columns  Field => 0-based column number
     */
    public function __construct(
        private readonly array $products,
        private readonly array $columns,
        private readonly string $dateFormat,
        private readonly CarbonImmutable $today,
    ) {}

    /**
     * @param  list<mixed>  $cells
     * @return ParsedSalesRow|list<string> The parsed row, or every problem found
     */
    public function validate(int $row, array $cells): ParsedSalesRow|array
    {
        $date = $this->parseDate($this->cell($cells, SalesImportFields::DATE));
        $product = $this->findProduct($this->cell($cells, SalesImportFields::SKU));
        $quantity = $this->parseQuantity($this->cell($cells, SalesImportFields::QUANTITY));
        $price = $this->parsePrice($this->cell($cells, SalesImportFields::UNIT_PRICE), $product);

        $problems = [];

        foreach ([$date, $product, $quantity, $price] as $result) {
            if ($result instanceof RowProblem) {
                $problems[] = $result->message;
            }
        }

        if ($problems !== []) {
            return $problems;
        }

        /** @var CarbonImmutable $date */
        /** @var Product $product */
        /** @var int $quantity */
        /** @var numeric-string $price */
        return new ParsedSalesRow($row, $product, $date, $quantity, $price);
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
        $index = $columns[SalesImportFields::SKU] ?? null;

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

    private function parseDate(mixed $value): CarbonImmutable|RowProblem
    {
        if ($this->isBlank($value)) {
            return new RowProblem('Date is missing.');
        }

        // Excel stores dates as a day count. Real spreadsheet date cells arrive
        // that way whatever the chosen text format is.
        if (is_int($value) || is_float($value) || preg_match('/^\d{5}(\.\d+)?$/', trim((string) $value))) {
            $serial = (float) $value;

            if ($serial >= 20000 && $serial <= 80000) {
                $day = ExcelDate::excelToDateTimeObject($serial)->format('Y-m-d');

                return $this->checkRange(CarbonImmutable::createFromFormat('!Y-m-d', $day));
            }
        }

        $text = trim((string) $value);

        $pattern = $this->dateFormat === 'iso'
            ? '/^(\d{4})[\/.\-](\d{1,2})[\/.\-](\d{1,2})(?:[ T].*)?$/'
            : '/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})(?:[ T].*)?$/';

        if (! preg_match($pattern, $text, $parts)) {
            $layout = SalesImportFields::DATE_FORMATS[$this->dateFormat] ?? $this->dateFormat;

            return new RowProblem("Date '{$text}' is not in the format {$layout}.");
        }

        [$year, $month, $day] = match ($this->dateFormat) {
            'mdy' => [(int) $parts[3], (int) $parts[1], (int) $parts[2]],
            'dmy' => [(int) $parts[3], (int) $parts[2], (int) $parts[1]],
            default => [(int) $parts[1], (int) $parts[2], (int) $parts[3]],
        };

        if (! checkdate($month, $day, $year)) {
            return new RowProblem("Date '{$text}' is not a real date.");
        }

        return $this->checkRange(CarbonImmutable::create($year, $month, $day)->startOfDay());
    }

    private function checkRange(CarbonImmutable $date): CarbonImmutable|RowProblem
    {
        if ($date->lt(CarbonImmutable::parse(self::EARLIEST_DATE))) {
            return new RowProblem('Date is before 2000.');
        }

        if ($date->gt($this->today)) {
            return new RowProblem('Date is in the future.');
        }

        return $date;
    }

    private function findProduct(mixed $value): Product|RowProblem
    {
        if ($this->isBlank($value)) {
            return new RowProblem('SKU is missing.');
        }

        $sku = strtoupper(trim((string) $value));

        return $this->products[$sku] ?? new RowProblem("Unknown SKU '{$sku}'.");
    }

    private function parseQuantity(mixed $value): int|RowProblem
    {
        if ($this->isBlank($value)) {
            return new RowProblem('Quantity is missing.');
        }

        $text = is_string($value) ? str_replace([',', ' '], '', trim($value)) : $value;

        if (! is_numeric($text)) {
            return new RowProblem("Quantity '{$value}' is not a number.");
        }

        $number = (float) $text;

        if (abs($number - round($number)) > 1e-9) {
            return new RowProblem("Quantity '{$value}' is not a whole number.");
        }

        $quantity = (int) round($number);

        if ($quantity < 1) {
            return new RowProblem('Quantity must be at least 1.');
        }

        if ($quantity > self::MAX_QUANTITY) {
            return new RowProblem('Quantity is too large.');
        }

        return $quantity;
    }

    /**
     * A blank price means "the product's current price".
     *
     * @return numeric-string|RowProblem
     */
    private function parsePrice(mixed $value, Product|RowProblem $product): string|RowProblem
    {
        if ($this->isBlank($value)) {
            // Without a valid product there is nothing to fall back on; the SKU
            // problem is already reported, so this value is never used.
            return $product instanceof Product ? $product->unit_price : '0.00';
        }

        // Allow a currency sign or code and thousands separators: "₱1,250.50".
        $text = is_string($value) ? preg_replace('/[^\d.\-]/u', '', str_replace(',', '', $value)) : $value;

        if ($text === '' || ! is_numeric($text)) {
            return new RowProblem("Unit price '{$value}' is not a number.");
        }

        $price = (float) $text;

        if ($price < 0) {
            return new RowProblem('Unit price cannot be negative.');
        }

        if ($price > self::MAX_PRICE) {
            return new RowProblem('Unit price is too large.');
        }

        return number_format($price, 2, '.', '');
    }
}
