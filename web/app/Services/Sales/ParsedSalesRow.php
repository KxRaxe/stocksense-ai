<?php

namespace App\Services\Sales;

use App\Models\Product;
use Carbon\CarbonImmutable;

/**
 * A file row that passed every check, ready to become a sale.
 */
final class ParsedSalesRow
{
    /**
     * @param  numeric-string  $unitPrice  Decimal text such as "12.50"
     */
    public function __construct(
        public readonly int $row,
        public readonly Product $product,
        public readonly CarbonImmutable $soldOn,
        public readonly int $quantity,
        public readonly string $unitPrice,
    ) {}

    /**
     * Two rows with the same key describe the same sale; used to spot a file
     * (or part of one) that was imported before.
     */
    public function duplicateKey(): string
    {
        return "{$this->product->id}|{$this->soldOn->toDateString()}|{$this->quantity}|{$this->unitPrice}";
    }
}
