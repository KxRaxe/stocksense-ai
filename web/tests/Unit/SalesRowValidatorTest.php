<?php

use App\Models\Product;
use App\Services\Sales\ParsedSalesRow;
use App\Services\Sales\SalesRowValidator;
use Carbon\CarbonImmutable;

/**
 * A validator over two known products and the columns date, sku, quantity and
 * unit price in that order. "Today" is fixed so date checks never depend on
 * the day the tests run.
 */
function salesValidator(string $dateFormat = 'iso'): SalesRowValidator
{
    $nails = (new Product)->forceFill(['id' => 1, 'sku' => 'HW-001', 'unit_price' => '85.00']);
    $cement = (new Product)->forceFill(['id' => 2, 'sku' => 'HW-002', 'unit_price' => '275.00']);

    return new SalesRowValidator(
        ['HW-001' => $nails, 'HW-002' => $cement],
        ['date' => 0, 'sku' => 1, 'quantity' => 2, 'unit_price' => 3],
        $dateFormat,
        CarbonImmutable::parse('2026-10-02'),
    );
}

it('accepts a good row', function () {
    $row = salesValidator()->validate(2, ['2026-03-05', 'hw-001', 12, '85.50']);

    expect($row)->toBeInstanceOf(ParsedSalesRow::class)
        ->and($row->row)->toBe(2)
        ->and($row->product->sku)->toBe('HW-001')
        ->and($row->soldOn->toDateString())->toBe('2026-03-05')
        ->and($row->quantity)->toBe(12)
        ->and($row->unitPrice)->toBe('85.50');
});

describe('dates', function () {
    it('reads the chosen text format', function (string $format, string $text, string $expected) {
        $row = salesValidator($format)->validate(2, [$text, 'HW-001', 1, '1']);

        expect($row)->toBeInstanceOf(ParsedSalesRow::class)
            ->and($row->soldOn->toDateString())->toBe($expected);
    })->with([
        'ISO with dashes' => ['iso', '2026-03-05', '2026-03-05'],
        'ISO with slashes' => ['iso', '2026/3/5', '2026-03-05'],
        'ISO with a time on the end' => ['iso', '2026-03-05 14:30:00', '2026-03-05'],
        'month first' => ['mdy', '03/05/2026', '2026-03-05'],
        'month first, no leading zeros' => ['mdy', '3/5/2026', '2026-03-05'],
        'day first' => ['dmy', '05/03/2026', '2026-03-05'],
        'day first with dots' => ['dmy', '25.12.2025', '2025-12-25'],
    ]);

    it('reads an Excel date number whatever the text format is', function (string $format) {
        // 46086 is 5 March 2026 in Excel's day numbering.
        foreach ([46086, 46086.0, '46086', '46086.5'] as $cell) {
            $row = salesValidator($format)->validate(2, [$cell, 'HW-001', 1, '1']);

            expect($row)->toBeInstanceOf(ParsedSalesRow::class)
                ->and($row->soldOn->toDateString())->toBe('2026-03-05');
        }
    })->with(['iso', 'mdy', 'dmy']);

    it('tells the formats apart rather than guessing', function () {
        // 13 cannot be a month, so reading 13/02/2026 as month-first fails.
        expect(salesValidator('mdy')->validate(2, ['13/02/2026', 'HW-001', 1, '1']))
            ->toBe(["Date '13/02/2026' is not a real date."]);
        expect(salesValidator('iso')->validate(2, ['13/02/2026', 'HW-001', 1, '1']))
            ->toBe(["Date '13/02/2026' is not in the format YYYY-MM-DD (2026-03-05)."]);
    });

    it('rejects what is not a usable date', function (mixed $cell, string $problem) {
        expect(salesValidator()->validate(2, [$cell, 'HW-001', 1, '1']))->toBe([$problem]);
    })->with([
        'blank' => [null, 'Date is missing.'],
        'only spaces' => ['   ', 'Date is missing.'],
        'words' => ['yesterday', "Date 'yesterday' is not in the format YYYY-MM-DD (2026-03-05)."],
        'impossible day' => ['2026-02-30', "Date '2026-02-30' is not a real date."],
        'in the future' => ['2026-10-03', 'Date is in the future.'],
        'far in the future' => ['2999-01-01', 'Date is in the future.'],
        'before 2000' => ['1999-12-31', 'Date is before 2000.'],
    ]);

    it('accepts today and the first day of 2000', function () {
        expect(salesValidator()->validate(2, ['2026-10-02', 'HW-001', 1, '1']))->toBeInstanceOf(ParsedSalesRow::class)
            ->and(salesValidator()->validate(2, ['2000-01-01', 'HW-001', 1, '1']))->toBeInstanceOf(ParsedSalesRow::class);
    });
});

describe('products', function () {
    it('matches SKUs regardless of capitals and spaces', function () {
        $row = salesValidator()->validate(2, ['2026-03-05', '  hw-002 ', 1, '1']);

        expect($row->product->id)->toBe(2);
    });

    it('rejects a missing or unknown SKU', function (mixed $cell, string $problem) {
        expect(salesValidator()->validate(2, ['2026-03-05', $cell, 1, '1']))->toBe([$problem]);
    })->with([
        'blank' => [null, 'SKU is missing.'],
        'unknown' => ['ZZ-999', "Unknown SKU 'ZZ-999'."],
    ]);
});

describe('quantities', function () {
    it('reads whole numbers however they are written', function (mixed $cell, int $expected) {
        expect(salesValidator()->validate(2, ['2026-03-05', 'HW-001', $cell, '1'])->quantity)->toBe($expected);
    })->with([
        'integer' => [7, 7],
        'text' => ['7', 7],
        'with spaces' => [' 7 ', 7],
        'thousands separator' => ['1,250', 1250],
        'a whole number written as a decimal' => ['5.0', 5],
        'float from a spreadsheet' => [12.0, 12],
    ]);

    it('rejects what cannot be a quantity', function (mixed $cell, string $problem) {
        expect(salesValidator()->validate(2, ['2026-03-05', 'HW-001', $cell, '1']))->toBe([$problem]);
    })->with([
        'blank' => [null, 'Quantity is missing.'],
        'words' => ['many', "Quantity 'many' is not a number."],
        'fraction' => ['2.5', "Quantity '2.5' is not a whole number."],
        'zero' => [0, 'Quantity must be at least 1.'],
        'negative' => [-3, 'Quantity must be at least 1.'],
        'absurdly large' => [5_000_000, 'Quantity is too large.'],
    ]);
});

describe('prices', function () {
    it('reads prices with a currency sign and thousands separators', function (mixed $cell, string $expected) {
        expect(salesValidator()->validate(2, ['2026-03-05', 'HW-001', 1, $cell])->unitPrice)->toBe($expected);
    })->with([
        'plain' => ['85', '85.00'],
        'decimals' => ['85.5', '85.50'],
        'peso sign' => ['₱85.50', '85.50'],
        'peso sign and thousands' => ['₱1,250.50', '1250.50'],
        'currency code' => ['PHP 1,250', '1250.00'],
        'number' => [99.999, '100.00'],
        'zero is allowed' => ['0', '0.00'],
    ]);

    it('falls back to the product price when blank', function (mixed $cell) {
        $row = salesValidator()->validate(2, ['2026-03-05', 'HW-002', 1, $cell]);

        expect($row->unitPrice)->toBe('275.00');
    })->with(['null' => [null], 'empty' => [''], 'spaces' => ['  ']]);

    it('rejects a price that is not a number or is negative', function (mixed $cell, string $problem) {
        expect(salesValidator()->validate(2, ['2026-03-05', 'HW-001', 1, $cell]))->toBe([$problem]);
    })->with([
        'words' => ['cheap', "Unit price 'cheap' is not a number."],
        'negative' => ['-5', 'Unit price cannot be negative.'],
        'absurdly large' => ['99999999999999', 'Unit price is too large.'],
    ]);
});

it('reports every problem in a row, not just the first', function () {
    $problems = salesValidator()->validate(7, ['soon', 'ZZ-1', 'lots', 'cheap']);

    expect($problems)->toHaveCount(4)
        ->and($problems[0])->toContain('Date')
        ->and($problems[1])->toContain('Unknown SKU')
        ->and($problems[2])->toContain('Quantity')
        ->and($problems[3])->toContain('Unit price');
});

it('treats a column that was never mapped as missing', function () {
    $validator = new SalesRowValidator(
        ['HW-001' => (new Product)->forceFill(['id' => 1, 'sku' => 'HW-001', 'unit_price' => '85.00'])],
        ['date' => 0, 'sku' => null, 'quantity' => 1, 'unit_price' => null],
        'iso',
        CarbonImmutable::parse('2026-10-02'),
    );

    expect($validator->validate(2, ['2026-03-05', 5]))->toBe(['SKU is missing.']);
});

describe('collecting SKUs', function () {
    it('lists each upper-case SKU once, skipping blanks', function () {
        $rows = [
            ['2026-03-05', 'hw-001', 1],
            ['2026-03-05', ' HW-001 ', 1],
            ['2026-03-05', 'hw-002', 1],
            ['2026-03-05', '', 1],
            ['2026-03-05', null, 1],
        ];

        expect(SalesRowValidator::skusIn($rows, ['sku' => 1]))->toBe(['HW-001', 'HW-002']);
    });

    it('returns nothing when no SKU column is chosen', function () {
        expect(SalesRowValidator::skusIn([['a', 'b']], ['sku' => null]))->toBe([]);
    });
});
