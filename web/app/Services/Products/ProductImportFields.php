<?php

namespace App\Services\Products;

use App\Services\Imports\HeaderGuesser;

/**
 * The columns a product file can carry, and how to guess which column of an
 * uploaded file is which from its header names.
 */
final class ProductImportFields
{
    public const SKU = 'sku';

    public const NAME = 'name';

    public const CATEGORY = 'category';

    public const UNIT = 'unit';

    public const UNIT_COST = 'unit_cost';

    public const UNIT_PRICE = 'unit_price';

    public const LEAD_TIME_DAYS = 'lead_time_days';

    public const MOQ = 'moq';

    public const PACK_SIZE = 'pack_size';

    public const REORDER_POINT = 'reorder_point';

    public const SAFETY_STOCK = 'safety_stock';

    public const OPENING_STOCK = 'opening_stock';

    /**
     * What a new product gets for an optional column that is missing from the
     * file or empty in a row. Matches the product form's starting values.
     */
    public const DEFAULTS = [
        self::UNIT => 'pc',
        self::UNIT_COST => '0.00',
        self::UNIT_PRICE => '0.00',
        self::LEAD_TIME_DAYS => 7,
        self::MOQ => 1,
        self::PACK_SIZE => 1,
        self::REORDER_POINT => null,
        self::SAFETY_STOCK => null,
        self::OPENING_STOCK => 0,
    ];

    /**
     * The service level (%) a category created by an import starts with: the
     * default service level setting.
     */
    public static function newCategoryServiceLevel(): string
    {
        return number_format((float) config('replenishment.default_service_level'), 2, '.', '');
    }

    /**
     * Field => label, whether it is required, and header names it is known by
     * (lower case, letters and digits only), best match first.
     *
     * @return array<string, array{label: string, required: bool, aliases: list<string>}>
     */
    public static function all(): array
    {
        return [
            self::SKU => [
                'label' => 'SKU (product code)',
                'required' => true,
                'aliases' => ['sku', 'productsku', 'productcode', 'itemcode', 'itemsku', 'code', 'barcode'],
            ],
            self::NAME => [
                'label' => 'Product name',
                'required' => true,
                'aliases' => ['name', 'productname', 'itemname', 'description', 'itemdescription', 'item', 'product', 'title'],
            ],
            self::CATEGORY => [
                'label' => 'Category',
                'required' => true,
                'aliases' => ['category', 'productcategory', 'categoryname', 'department', 'group', 'productgroup'],
            ],
            self::UNIT => [
                'label' => 'Unit (pc, box, kg...)',
                'required' => false,
                'aliases' => ['unit', 'uom', 'unitofmeasure', 'unitname'],
            ],
            self::UNIT_COST => [
                'label' => 'Unit cost',
                'required' => false,
                'aliases' => ['unitcost', 'cost', 'costprice', 'purchaseprice', 'buyingprice', 'costperunit'],
            ],
            self::UNIT_PRICE => [
                'label' => 'Selling price',
                'required' => false,
                'aliases' => ['unitprice', 'price', 'sellingprice', 'retailprice', 'srp', 'saleprice', 'priceperunit'],
            ],
            self::LEAD_TIME_DAYS => [
                'label' => 'Lead time (days)',
                'required' => false,
                'aliases' => ['leadtimedays', 'leadtime', 'leaddays', 'leadtimeindays', 'deliverydays'],
            ],
            self::MOQ => [
                'label' => 'Minimum order quantity',
                'required' => false,
                'aliases' => ['moq', 'minimumorderquantity', 'minorderqty', 'minimumorder', 'minorder'],
            ],
            self::PACK_SIZE => [
                'label' => 'Pack size',
                'required' => false,
                'aliases' => ['packsize', 'pack', 'packquantity', 'packqty', 'casesize', 'caseqty', 'unitsperpack'],
            ],
            self::REORDER_POINT => [
                'label' => 'Reorder point',
                'required' => false,
                'aliases' => ['reorderpoint', 'reorderlevel', 'rop', 'minstock', 'minimumstock'],
            ],
            self::SAFETY_STOCK => [
                'label' => 'Safety stock',
                'required' => false,
                'aliases' => ['safetystock', 'bufferstock', 'buffer', 'safetylevel'],
            ],
            self::OPENING_STOCK => [
                'label' => 'Opening stock (new products only)',
                'required' => false,
                'aliases' => ['openingstock', 'stock', 'onhand', 'stockonhand', 'quantityonhand', 'qtyonhand', 'currentstock', 'initialstock', 'quantity', 'qty'],
            ],
        ];
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
