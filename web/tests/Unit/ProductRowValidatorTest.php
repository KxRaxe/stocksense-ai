<?php

use App\Models\Product;
use App\Services\Products\ParsedProductRow;
use App\Services\Products\ProductRowValidator;

/**
 * A validator over one existing product (HW-001, in the Hardware category) and
 * two known categories, reading the columns sku, name, category, unit, unit
 * cost, selling price, lead time, MOQ, pack size, reorder point, safety stock
 * and opening stock in that order.
 *
 * @param  array{update?: bool, create_categories?: bool, existing?: array<string, mixed>}  $with
 */
function productValidator(array $with = []): ProductRowValidator
{
    $nails = (new Product)->forceFill([
        'id' => 1, 'sku' => 'HW-001', 'name' => 'Nails', 'is_active' => true, 'import_batch_id' => null,
        ...($with['existing'] ?? []),
    ]);

    return new ProductRowValidator(
        ['HW-001' => $nails],
        ['hardware' => 10, 'food & beverages' => 11],
        [
            'sku' => 0, 'name' => 1, 'category' => 2, 'unit' => 3, 'unit_cost' => 4, 'unit_price' => 5,
            'lead_time_days' => 6, 'moq' => 7, 'pack_size' => 8, 'reorder_point' => 9, 'safety_stock' => 10, 'opening_stock' => 11,
        ],
        $with['update'] ?? false,
        $with['create_categories'] ?? false,
        99,
    );
}

/**
 * A full row for a new product, with any cell replaced by position.
 *
 * @param  array<int, mixed>  $change
 * @return list<mixed>
 */
function productRow(array $change = []): array
{
    return array_replace(
        ['NEW-1', 'Roofing nails', 'Hardware', 'box', '40.00', '65.00', 5, 10, 10, 20, 10, 100],
        $change,
    );
}

it('accepts a good row for a new product', function () {
    $row = productValidator()->validate(2, productRow([0 => 'new-1']));

    expect($row)->toBeInstanceOf(ParsedProductRow::class)
        ->and($row->action)->toBe(ParsedProductRow::CREATE)
        ->and($row->sku)->toBe('NEW-1')
        ->and($row->existing)->toBeNull()
        ->and($row->newCategory)->toBeNull()
        ->and($row->openingStock)->toBe(100)
        ->and($row->values)->toBe([
            'sku' => 'NEW-1',
            'name' => 'Roofing nails',
            'category_id' => 10,
            'unit' => 'box',
            'unit_cost' => '40.00',
            'unit_price' => '65.00',
            'lead_time_days' => 5,
            'moq' => 10,
            'pack_size' => 10,
            'reorder_point_override' => 20,
            'safety_stock_override' => 10,
        ]);
});

it('fills what a new product leaves blank with the form defaults', function () {
    $row = productValidator()->validate(2, ['NEW-1', 'Roofing nails', 'hardware', '', '', '', '', '', '', '', '', '']);

    expect($row)->toBeInstanceOf(ParsedProductRow::class)
        ->and($row->values)->toMatchArray([
            'category_id' => 10,
            'unit' => 'pc',
            'unit_cost' => '0.00',
            'unit_price' => '0.00',
            'lead_time_days' => 7,
            'moq' => 1,
            'pack_size' => 1,
            'reorder_point_override' => null,
            'safety_stock_override' => null,
        ])
        ->and($row->openingStock)->toBe(0);
});

it('does the same when the optional columns are not in the file at all', function () {
    $validator = new ProductRowValidator([], ['hardware' => 10], ['sku' => 0, 'name' => 1, 'category' => 2], false, false, 99);

    $row = $validator->validate(2, ['NEW-1', 'Roofing nails', 'Hardware']);

    expect($row)->toBeInstanceOf(ParsedProductRow::class)
        ->and($row->values['unit'])->toBe('pc')
        ->and($row->values['lead_time_days'])->toBe(7)
        ->and($row->openingStock)->toBe(0);
});

describe('required cells', function () {
    it('needs a SKU, a name and a category for a new product', function (array $change, string $message) {
        expect(productValidator()->validate(2, productRow($change)))->toBe([$message]);
    })->with([
        'no SKU' => [[0 => ''], 'SKU is missing.'],
        'a blank-looking SKU' => [[0 => '   '], 'SKU is missing.'],
        'no name' => [[1 => ''], 'Product name is missing.'],
        'no category' => [[2 => ''], 'Category is missing.'],
        'a SKU that is too long' => [[0 => str_repeat('A', 65)], 'SKU is too long (64 characters at most).'],
        'a name that is too long' => [[1 => str_repeat('n', 256)], 'Product name is too long (255 characters at most).'],
        'a unit that is too long' => [[3 => str_repeat('u', 21)], 'Unit is too long (20 characters at most).'],
    ]);

    it('reports every problem in a row, not just the first', function () {
        expect(productValidator()->validate(2, productRow([1 => '', 4 => 'cheap', 6 => 'soon'])))->toBe([
            'Product name is missing.',
            "Unit cost 'cheap' is not a number.",
            "Lead time 'soon' is not a number.",
        ]);
    });
});

describe('categories', function () {
    it('matches a category whatever its capitals', function (string $typed) {
        $row = productValidator()->validate(2, productRow([2 => $typed]));

        expect($row)->toBeInstanceOf(ParsedProductRow::class)
            ->and($row->values['category_id'])->toBe(10);
    })->with(['Hardware', 'HARDWARE', ' hardware ']);

    it('reports a category that does not exist', function () {
        expect(productValidator()->validate(2, productRow([2 => 'Toys'])))->toBe(["Unknown category 'Toys'."]);
    });

    it('creates it when asked to', function () {
        $row = productValidator(['create_categories' => true])->validate(2, productRow([2 => 'Toys']));

        expect($row)->toBeInstanceOf(ParsedProductRow::class)
            ->and($row->newCategory)->toBe('Toys')
            ->and($row->values)->not->toHaveKey('category_id');
    });

    it('still uses the category that exists, when asked to create missing ones', function () {
        $row = productValidator(['create_categories' => true])->validate(2, productRow([2 => 'hardware']));

        expect($row)->toBeInstanceOf(ParsedProductRow::class)
            ->and($row->newCategory)->toBeNull()
            ->and($row->values['category_id'])->toBe(10);
    });

    it('does not accept a category name that is too long to store', function () {
        $row = productValidator(['create_categories' => true])->validate(2, productRow([2 => str_repeat('c', 101)]));

        expect($row)->toBe(['Category is too long (100 characters at most).']);
    });
});

describe('numbers', function () {
    it('reads prices with currency signs and thousands separators', function (mixed $typed, string $expected) {
        $row = productValidator()->validate(2, productRow([5 => $typed]));

        expect($row)->toBeInstanceOf(ParsedProductRow::class)
            ->and($row->values['unit_price'])->toBe($expected);
    })->with([
        'plain' => ['65', '65.00'],
        'with centavos' => ['65.5', '65.50'],
        'with a peso sign and comma' => ['₱1,250.50', '1250.50'],
        'a number cell' => [65.5, '65.50'],
        'zero' => ['0', '0.00'],
    ]);

    it('rejects numbers the product form would reject', function (int $cell, mixed $typed, string $message) {
        expect(productValidator()->validate(2, productRow([$cell => $typed])))->toBe([$message]);
    })->with([
        'a negative cost' => [4, '-1', 'Unit cost cannot be negative.'],
        'an enormous price' => [5, '99999999999', 'Selling price is too large.'],
        'a price in words' => [5, 'free', "Selling price 'free' is not a number."],
        'a negative lead time' => [6, -1, 'Lead time must be at least 0.'],
        'a year-long lead time' => [6, 366, 'Lead time is too large.'],
        'a fractional lead time' => [6, '2.5', "Lead time '2.5' is not a whole number."],
        'a zero minimum order' => [7, 0, 'Minimum order quantity must be at least 1.'],
        'a zero pack size' => [8, 0, 'Pack size must be at least 1.'],
        'a negative reorder point' => [9, -5, 'Reorder point must be at least 0.'],
        'a negative safety stock' => [10, -5, 'Safety stock must be at least 0.'],
        'negative opening stock' => [11, -5, 'Opening stock must be at least 0.'],
        'too much opening stock' => [11, 10_000_001, 'Opening stock is too large.'],
    ]);

    it('accepts whole numbers written the way spreadsheets write them', function (mixed $typed) {
        $row = productValidator()->validate(2, productRow([7 => $typed]));

        expect($row)->toBeInstanceOf(ParsedProductRow::class)
            ->and($row->values['moq'])->toBe(24);
    })->with([24, 24.0, '24', '24.0', ' 24 ']);

    it('allows a reorder point and safety stock of zero', function () {
        $row = productValidator()->validate(2, productRow([9 => 0, 10 => '0']));

        expect($row)->toBeInstanceOf(ParsedProductRow::class)
            ->and($row->values)->toMatchArray(['reorder_point_override' => 0, 'safety_stock_override' => 0]);
    });
});

describe('SKUs that already exist', function () {
    it('skips them unless asked to update', function () {
        $row = productValidator()->validate(2, productRow([0 => 'hw-001', 1 => '']));

        // Skipped rows are not checked any further, so the blank name is no problem.
        expect($row)->toBeInstanceOf(ParsedProductRow::class)
            ->and($row->action)->toBe(ParsedProductRow::SKIP)
            ->and($row->existing?->sku)->toBe('HW-001');
    });

    it('updates them when asked to, with only what the row gave', function () {
        $row = productValidator(['update' => true])->validate(2, ['hw-001', '', '', '', '', '70', '', '', '', '', '', '500']);

        expect($row)->toBeInstanceOf(ParsedProductRow::class)
            ->and($row->action)->toBe(ParsedProductRow::UPDATE)
            ->and($row->values)->toBe(['unit_price' => '70.00'])
            // Stock of an existing product changes through restocks and stock-takes.
            ->and($row->openingStock)->toBe(0);
    });

    it('still checks the cells an update does give', function () {
        $row = productValidator(['update' => true])->validate(2, ['HW-001', '', 'Toys', '', '-3', '', '', '', '', '', '', '']);

        expect($row)->toBe(["Unknown category 'Toys'.", 'Unit cost cannot be negative.']);
    });

    it('does not check opening stock for an update', function () {
        $row = productValidator(['update' => true])->validate(2, ['HW-001', '', '', '', '', '', '', '', '', '', '', 'lots']);

        expect($row)->toBeInstanceOf(ParsedProductRow::class);
    });

    it('brings an archived product back when updating it', function () {
        $row = productValidator(['update' => true, 'existing' => ['is_active' => false]])->validate(2, ['HW-001', '', '', '', '', '', '', '', '', '', '', '']);

        expect($row)->toBeInstanceOf(ParsedProductRow::class)
            ->and($row->values)->toBe(['is_active' => true]);
    });

    it('lets several rows update the same product', function () {
        $validator = productValidator(['update' => true]);

        expect($validator->validate(2, ['HW-001', '', '', '', '', '70', '', '', '', '', '', '']))->toBeInstanceOf(ParsedProductRow::class)
            ->and($validator->validate(3, ['HW-001', '', '', '', '', '75', '', '', '', '', '', '']))->toBeInstanceOf(ParsedProductRow::class);
    });
});

describe('a SKU repeated in the file', function () {
    it('is only valid the first time', function () {
        $validator = productValidator();

        expect($validator->validate(2, productRow()))->toBeInstanceOf(ParsedProductRow::class)
            ->and($validator->validate(3, productRow([0 => 'new-1'])))->toBe(["SKU 'NEW-1' appears more than once in the file."]);
    });

    it('does not count a row that failed', function () {
        $validator = productValidator();

        expect($validator->validate(2, productRow([1 => ''])))->toBe(['Product name is missing.'])
            ->and($validator->validate(3, productRow()))->toBeInstanceOf(ParsedProductRow::class);
    });

    it('is noticed when the first row was imported in an earlier slice', function () {
        // By then the product exists, and carries the number of this import.
        $validator = productValidator(['existing' => ['import_batch_id' => 99], 'update' => true]);

        expect($validator->validate(5, productRow([0 => 'HW-001'])))->toBe(["SKU 'HW-001' appears more than once in the file."]);
    });

    it('is not mistaken for an existing product from another import', function () {
        $validator = productValidator(['existing' => ['import_batch_id' => 7]]);

        expect($validator->validate(5, productRow([0 => 'HW-001'])))->toBeInstanceOf(ParsedProductRow::class);
    });
});

it('lists the SKUs of a set of rows, upper-cased and once each', function () {
    $columns = ['sku' => 1];
    $rows = [['x', 'ab-1'], ['x', ' AB-1 '], ['x', 'cd-2'], ['x', ''], ['x', null], ['x']];

    expect(ProductRowValidator::skusIn($rows, $columns))->toBe(['AB-1', 'CD-2'])
        ->and(ProductRowValidator::skusIn($rows, ['sku' => null]))->toBe([]);
});
