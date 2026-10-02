<?php

namespace App\Services\Sales;

use App\Services\Imports\HeaderGuesser;

/**
 * The columns a sales file can carry, and how to guess which column of an
 * uploaded file is which from its header names.
 */
final class SalesImportFields
{
    public const DATE = 'date';

    public const SKU = 'sku';

    public const QUANTITY = 'quantity';

    public const UNIT_PRICE = 'unit_price';

    /**
     * Date layouts the person can choose between.
     */
    public const DATE_FORMATS = [
        'iso' => 'YYYY-MM-DD (2026-03-05)',
        'mdy' => 'MM/DD/YYYY (03/05/2026)',
        'dmy' => 'DD/MM/YYYY (05/03/2026)',
    ];

    /**
     * Field => label, whether it is required, and header names it is known by
     * (lower case, letters and digits only), best match first.
     *
     * @return array<string, array{label: string, required: bool, aliases: list<string>}>
     */
    public static function all(): array
    {
        return [
            self::DATE => [
                'label' => 'Date',
                'required' => true,
                'aliases' => ['date', 'saledate', 'solddate', 'transactiondate', 'dateofsale', 'orderdate', 'day'],
            ],
            self::SKU => [
                'label' => 'SKU (product code)',
                'required' => true,
                'aliases' => ['sku', 'productsku', 'productcode', 'itemcode', 'itemsku', 'code', 'barcode'],
            ],
            self::QUANTITY => [
                'label' => 'Quantity sold',
                'required' => true,
                'aliases' => ['quantity', 'qty', 'quantitysold', 'qtysold', 'unitssold', 'units', 'count'],
            ],
            self::UNIT_PRICE => [
                'label' => 'Unit price',
                'required' => false,
                'aliases' => ['unitprice', 'price', 'sellingprice', 'priceperunit', 'srp', 'saleprice'],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function required(): array
    {
        return array_keys(array_filter(self::all(), fn (array $field) => $field['required']));
    }

    /**
     * Matches each field to the column whose header looks like it, or null.
     *
     * @param  list<string>  $headers
     * @return array<string, int|null>
     */
    public static function guess(array $headers): array
    {
        return HeaderGuesser::guess(self::all(), $headers);
    }
}
