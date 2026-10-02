<?php

namespace App\Services\Sales;

use App\Enums\SaleSource;
use App\Enums\StockMovementType;
use App\Models\ImportBatch;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\LocationContext;
use App\Services\Inventory\StockService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Records and removes sales. A sale and the stock it takes off the shelf are
 * written together, so the two can never disagree.
 */
class SalesService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly LocationContext $locations,
    ) {}

    /**
     * @param  numeric-string  $unitPrice  Decimal text such as "12.50"
     * @param  bool  $adjustStock  False for historical data whose stock effect is already in the current stock count
     */
    public function record(
        Product $product,
        CarbonInterface $soldOn,
        int $quantity,
        string $unitPrice,
        SaleSource $source,
        ?User $user = null,
        ?ImportBatch $batch = null,
        bool $adjustStock = true,
    ): Sale {
        return DB::transaction(function () use ($product, $soldOn, $quantity, $unitPrice, $source, $user, $batch, $adjustStock) {
            $sale = Sale::create([
                'product_id' => $product->getKey(),
                'location_id' => $this->locations->id(),
                'sold_on' => $soldOn->toDateString(),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total' => bcmul((string) $quantity, $unitPrice, 2),
                'source' => $source,
                'import_batch_id' => $batch?->getKey(),
                'user_id' => $user?->getKey(),
            ]);

            if ($adjustStock) {
                $this->stock->record(
                    $product,
                    StockMovementType::Sale,
                    -$quantity,
                    $this->occurredAt($soldOn),
                    user: $user,
                    reference: $sale,
                );
            }

            return $sale;
        });
    }

    /**
     * Records many sales at once (one slice of an imported file) in a handful of
     * queries instead of several per sale. The stock they take off is written as
     * one movement per sale, tied to the import rather than to each sale, since
     * the sales do not have their numbers until they are saved.
     *
     * @param  list<ParsedSalesRow>  $rows
     */
    public function recordMany(array $rows, ImportBatch $batch, ?User $user, bool $adjustStock): void
    {
        if ($rows === []) {
            return;
        }

        $now = now();
        $locationId = $this->locations->id();
        $sales = [];
        $movements = [];

        foreach ($rows as $row) {
            $sales[] = [
                'product_id' => $row->product->getKey(),
                'location_id' => $locationId,
                'sold_on' => $row->soldOn->toDateString(),
                'quantity' => $row->quantity,
                'unit_price' => $row->unitPrice,
                'total' => bcmul((string) $row->quantity, $row->unitPrice, 2),
                'source' => SaleSource::Import->value,
                'import_batch_id' => $batch->getKey(),
                'user_id' => $user?->getKey(),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($adjustStock) {
                $movements[] = [
                    'product_id' => $row->product->getKey(),
                    'type' => StockMovementType::Sale,
                    'quantity' => -$row->quantity,
                    'occurred_at' => $this->occurredAt($row->soldOn),
                    'user_id' => $user?->getKey(),
                    'reference_type' => $batch->getMorphClass(),
                    'reference_id' => $batch->getKey(),
                ];
            }
        }

        foreach (array_chunk($sales, 1000) as $slice) {
            Sale::query()->insert($slice);
        }

        $this->stock->recordMany($movements);
    }

    /**
     * Removes a sale that was entered by hand and puts its stock back. The
     * original stock movement stays in the ledger, followed by a correcting one.
     */
    public function delete(Sale $sale, ?User $user = null): void
    {
        if ($sale->source !== SaleSource::Manual) {
            throw new InvalidArgumentException('Only sales entered by hand can be deleted one at a time. Undo the import instead.');
        }

        DB::transaction(function () use ($sale, $user) {
            // What the sale took off the shelf (a negative number), if it took anything.
            $taken = (int) StockMovement::query()
                ->where('reference_type', $sale->getMorphClass())
                ->where('reference_id', $sale->getKey())
                ->sum('quantity');

            if ($taken !== 0) {
                $this->stock->record(
                    $sale->product,
                    StockMovementType::Adjustment,
                    -$taken,
                    note: "Sale #{$sale->id} removed",
                    user: $user,
                );
            }

            $sale->delete();

            // The sale row is gone, so keep a record of what was removed and by whom.
            activity('sales')
                ->causedBy($user)
                ->event('sale_deleted')
                ->withProperties([
                    'sale_id' => $sale->id,
                    'sku' => $sale->product->sku,
                    'sold_on' => $sale->sold_on->toDateString(),
                    'quantity' => $sale->quantity,
                    'unit_price' => $sale->unit_price,
                ])
                ->log('Sale deleted');
        });
    }

    /**
     * Sales on today's date are stamped with the current time; on any other day,
     * midday, so the day is unambiguous in every time zone.
     */
    private function occurredAt(CarbonInterface $soldOn): CarbonInterface
    {
        return $soldOn->isToday() ? now() : CarbonImmutable::instance($soldOn)->setTime(12, 0);
    }
}
