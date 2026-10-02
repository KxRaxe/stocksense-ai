<?php

namespace App\Services\Sales;

use App\Models\Sale;

/**
 * Spots rows that were already imported earlier.
 *
 * A row counts as a duplicate when an earlier import already holds a sale for
 * the same product, day, quantity and price. Rows repeated inside one file are
 * not duplicates (two customers can buy the same thing on the same day), and
 * sales entered by hand are never matched.
 */
class DuplicateFinder
{
    /**
     * @param  iterable<ParsedSalesRow>  $rows
     * @param  int  $exceptBatchId  The import being processed, whose own rows do not count
     * @return array<string, true> The duplicate keys that already exist
     */
    public function existing(iterable $rows, int $exceptBatchId): array
    {
        $productIds = [];
        $dates = [];

        foreach ($rows as $row) {
            $productIds[$row->product->id] = true;
            $dates[$row->soldOn->toDateString()] = true;
        }

        if ($productIds === []) {
            return [];
        }

        $found = [];

        $matches = Sale::query()
            ->whereNotNull('import_batch_id')
            ->where('import_batch_id', '!=', $exceptBatchId)
            ->whereIn('product_id', array_keys($productIds))
            ->whereIn('sold_on', array_keys($dates))
            ->toBase()
            ->get(['product_id', 'sold_on', 'quantity', 'unit_price']);

        foreach ($matches as $sale) {
            $found["{$sale->product_id}|{$sale->sold_on}|{$sale->quantity}|{$sale->unit_price}"] = true;
        }

        return $found;
    }
}
