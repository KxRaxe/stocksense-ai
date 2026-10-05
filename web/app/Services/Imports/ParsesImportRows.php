<?php

namespace App\Services\Imports;

/**
 * What every row validator shares: reading a cell by field, and turning money
 * and whole-number cells into values or problems. The using class has the
 * column mapping in `$columns` and a `validate(int $row, array $cells)` that
 * returns the parsed row or a list of problems.
 *
 * @template TRow of object
 */
trait ParsesImportRows
{
    private const MAX_MONEY = 9_999_999_999.99;

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
        $index = $columns['sku'] ?? null;

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
     * Checks rows in file order: the parsed ones and the problems of the rest,
     * both keyed by row number.
     *
     * @param  list<array{0: int, 1: list<mixed>}>  $rows
     * @return array{0: array<int, TRow>, 1: array<int, list<string>>}
     */
    public function validateAll(array $rows): array
    {
        $valid = [];
        $invalid = [];

        foreach ($rows as [$number, $cells]) {
            $result = $this->validate($number, $cells);

            if (is_array($result)) {
                $invalid[$number] = $result;
            } else {
                $valid[$number] = $result;
            }
        }

        return [$valid, $invalid];
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

    /**
     * @return numeric-string|RowProblem|null Null when blank
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

    /**
     * @return int|RowProblem|null Null when blank
     */
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
