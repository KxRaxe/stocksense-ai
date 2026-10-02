<?php

use App\Services\Products\ProductImportFields;

it('matches the columns of the example file', function () {
    $headers = ['sku', 'name', 'category', 'unit', 'unit_cost', 'unit_price', 'lead_time_days', 'moq', 'pack_size', 'reorder_point', 'safety_stock', 'opening_stock'];

    expect(ProductImportFields::guess($headers))->toBe(array_combine($headers, range(0, 11)));
});

it('matches the headers people usually give', function () {
    $columns = ProductImportFields::guess(['Item Code', 'Product Name', 'Department', 'UOM', 'Cost Price', 'SRP', 'Lead Time', 'Min Order Qty', 'Case Size', 'Reorder Level', 'Buffer Stock', 'On Hand']);

    expect($columns)->toBe([
        'sku' => 0, 'name' => 1, 'category' => 2, 'unit' => 3, 'unit_cost' => 4, 'unit_price' => 5,
        'lead_time_days' => 6, 'moq' => 7, 'pack_size' => 8, 'reorder_point' => 9, 'safety_stock' => 10, 'opening_stock' => 11,
    ]);
});

it('leaves a field unmatched when no header looks like it', function () {
    $columns = ProductImportFields::guess(['sku', 'name', 'category']);

    expect($columns['sku'])->toBe(0)
        ->and($columns['unit_price'])->toBeNull()
        ->and($columns['opening_stock'])->toBeNull();
});

it('never gives one column to two fields', function () {
    $columns = ProductImportFields::guess(['code', 'item']);

    expect($columns['sku'])->toBe(0)
        ->and($columns['name'])->toBe(1)
        ->and(array_filter($columns, fn ($column) => $column === 0))->toHaveCount(1);
});

it('asks only for the SKU, name and category', function () {
    $required = array_keys(array_filter(ProductImportFields::all(), fn (array $field) => $field['required']));

    expect($required)->toBe(['sku', 'name', 'category']);
});

it('has a default for every optional field a new product needs', function () {
    // A default for each optional column the file may leave out.
    $optional = array_keys(array_filter(ProductImportFields::all(), fn (array $field) => ! $field['required']));

    expect(array_keys(ProductImportFields::DEFAULTS))->toBe($optional);
});
