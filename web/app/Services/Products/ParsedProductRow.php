<?php

namespace App\Services\Products;

use App\Models\Product;

/**
 * A file row that passed every check, with what the import will do with it.
 */
final class ParsedProductRow
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    /** A product that already exists, left alone because the person chose to skip those. */
    public const SKIP = 'skip';

    /**
     * @param  self::CREATE|self::UPDATE|self::SKIP  $action
     * @param  array<string, mixed>  $values  Product columns to write: all of them for a new product (blanks filled with defaults), only those the row gave for an update
     * @param  string|null  $newCategory  Name of a category that has to be created first, when the row names one that does not exist yet
     */
    public function __construct(
        public readonly int $row,
        public readonly string $sku,
        public readonly string $action,
        public readonly ?Product $existing = null,
        public readonly array $values = [],
        public readonly ?string $newCategory = null,
        public readonly int $openingStock = 0,
    ) {}
}
