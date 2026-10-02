<?php

use App\Services\Sales\ImportFields;

it('matches columns to fields by their headers', function (array $headers, array $expected) {
    expect(ImportFields::guess($headers))->toBe($expected);
})->with([
    'the template' => [
        ['date', 'sku', 'quantity', 'unit_price'],
        ['date' => 0, 'sku' => 1, 'quantity' => 2, 'unit_price' => 3],
    ],
    'capitals, spaces and other wording' => [
        ['Sale Date', 'Product Code', 'Qty Sold', 'Selling Price'],
        ['date' => 0, 'sku' => 1, 'quantity' => 2, 'unit_price' => 3],
    ],
    'any order, with extra columns' => [
        ['Receipt No', 'Qty', 'Item Code', 'Cashier', 'Transaction Date'],
        ['date' => 4, 'sku' => 2, 'quantity' => 1, 'unit_price' => null],
    ],
    'no price column' => [
        ['date', 'sku', 'quantity'],
        ['date' => 0, 'sku' => 1, 'quantity' => 2, 'unit_price' => null],
    ],
    'nothing recognisable' => [
        ['A', 'B', 'C'],
        ['date' => null, 'sku' => null, 'quantity' => null, 'unit_price' => null],
    ],
]);

it('never gives one column to two fields', function () {
    // "Price" could also read as a quantity-ish column; each column is used once.
    $columns = ImportFields::guess(['code', 'code', 'qty']);

    expect($columns['sku'])->toBe(0)
        ->and(array_filter($columns, fn ($index) => $index !== null))->toHaveCount(count(array_unique(array_filter($columns, fn ($i) => $i !== null))));
});

it('prefers the more specific header', function () {
    // "unit price" beats the vaguer "price" when both are present.
    expect(ImportFields::guess(['date', 'sku', 'qty', 'price', 'unit price'])['unit_price'])->toBe(4);
});

it('knows which fields are required', function () {
    expect(ImportFields::required())->toBe(['date', 'sku', 'quantity']);
});
