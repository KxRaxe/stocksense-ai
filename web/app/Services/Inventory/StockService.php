<?php

namespace App\Services\Inventory;

use App\Enums\StockMovementType;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only code that changes stock.
 *
 * Every change is one row in the stock_movements ledger plus the matching
 * update of inventory_levels, written together in a transaction while the
 * level row is locked. That keeps one rule true everywhere: a product's
 * on_hand equals the sum of its movements at that location.
 */
class StockService
{
    public function __construct(private readonly LocationContext $locations) {}

    /**
     * Stock a product started with. Does nothing for zero. `$occurredAt` dates
     * it to the day the product was first stocked, for products set up with
     * history.
     */
    public function openingStock(
        Product $product,
        int $quantity,
        ?User $user = null,
        ?CarbonInterface $occurredAt = null,
    ): ?StockMovement {
        if ($quantity === 0) {
            return null;
        }

        return $this->record($product, StockMovementType::Initial, $quantity, $occurredAt, user: $user);
    }

    /**
     * Goods received. Also reduces the quantity marked as "on order" (never
     * below zero), so an accepted recommendation stops counting once it arrives.
     */
    public function restock(
        Product $product,
        int $quantity,
        ?CarbonInterface $receivedAt = null,
        ?string $note = null,
        ?User $user = null,
    ): StockMovement {
        return $this->record($product, StockMovementType::Restock, $quantity, $receivedAt, $note, $user);
    }

    /**
     * Stock-take: set the quantity on hand to what was counted. Records the
     * difference as an adjustment, or returns null when nothing changed.
     */
    public function adjustTo(Product $product, int $counted, ?string $note = null, ?User $user = null): ?StockMovement
    {
        if ($counted < 0) {
            throw new InvalidArgumentException('A counted quantity cannot be negative.');
        }

        return DB::transaction(function () use ($product, $counted, $note, $user) {
            $location = $this->locations->current();
            $delta = $counted - $this->lockLevel($product, $location)->on_hand;

            if ($delta === 0) {
                return null;
            }

            return $this->record($product, StockMovementType::Adjustment, $delta, null, $note, $user, location: $location);
        });
    }

    /**
     * Counts goods as ordered but not yet received. This is not stock: nothing
     * goes in the ledger and `on_hand` does not change. It is what keeps a
     * product that has been ordered from being recommended again; a restock
     * brings it back down.
     */
    public function addOnOrder(Product $product, int $quantity, ?Location $location = null): void
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('A quantity on order must be at least 1.');
        }

        DB::transaction(function () use ($product, $quantity, $location) {
            $level = $this->lockLevel($product, $location ?? $this->locations->current());
            $level->on_order += $quantity;
            $level->save();
        });
    }

    /**
     * Takes goods off "on order" without receiving them, for an order that
     * will not arrive after all. Never goes below zero.
     *
     * @return int How much was actually taken off
     */
    public function reduceOnOrder(Product $product, int $quantity, ?Location $location = null): int
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('A quantity to take off the order must be at least 1.');
        }

        return DB::transaction(function () use ($product, $quantity, $location) {
            $level = $this->lockLevel($product, $location ?? $this->locations->current());
            $removed = min($quantity, $level->on_order);
            $level->on_order -= $removed;
            $level->save();

            return $removed;
        });
    }

    /**
     * Writes one ledger row and applies it to the stock level.
     *
     * @param  Model|null  $reference  The record that caused this movement (e.g. a sale)
     */
    public function record(
        Product $product,
        StockMovementType $type,
        int $quantity,
        ?CarbonInterface $occurredAt = null,
        ?string $note = null,
        ?User $user = null,
        ?Model $reference = null,
        ?Location $location = null,
    ): StockMovement {
        if (! $type->allows($quantity)) {
            throw new InvalidArgumentException("A {$type->value} movement cannot have a quantity of {$quantity}.");
        }

        return DB::transaction(function () use ($product, $type, $quantity, $occurredAt, $note, $user, $reference, $location) {
            $location ??= $this->locations->current();
            $level = $this->lockLevel($product, $location);

            $movement = new StockMovement([
                'product_id' => $product->getKey(),
                'location_id' => $location->getKey(),
                'type' => $type,
                'quantity' => $quantity,
                'occurred_at' => $occurredAt ?? now(),
                'note' => $note,
                'user_id' => $user?->getKey(),
            ]);

            if ($reference !== null) {
                $movement->reference()->associate($reference);
            }

            $movement->save();

            $level->on_hand += $quantity;

            if ($type === StockMovementType::Restock) {
                $level->on_order = max(0, $level->on_order - $quantity);
            }

            $level->save();

            return $movement;
        });
    }

    /**
     * Writes many movements at once, for loading a lot of history (an imported
     * sales file, a delivery log). Does the same as calling record() for each,
     * in far fewer database round trips: one insert per thousand movements and
     * one update per product, instead of several queries for every movement.
     *
     * All or nothing: if any entry is invalid, none are written.
     *
     * @param  list<array{
     *     product_id: int,
     *     type: StockMovementType,
     *     quantity: int,
     *     occurred_at: CarbonInterface,
     *     note?: string|null,
     *     user_id?: int|null,
     *     reference_type?: string|null,
     *     reference_id?: int|null
     * }>  $entries
     */
    public function recordMany(array $entries, ?Location $location = null): void
    {
        if ($entries === []) {
            return;
        }

        foreach ($entries as $position => $entry) {
            if (! $entry['type']->allows($entry['quantity'])) {
                throw new InvalidArgumentException(
                    "Movement #{$position}: a {$entry['type']->value} movement cannot have a quantity of {$entry['quantity']}."
                );
            }
        }

        DB::transaction(function () use ($entries, $location) {
            $location ??= $this->locations->current();
            $now = now();

            // Make sure each product has a stock row, then lock them all so
            // nobody else changes these products while this runs.
            $productIds = array_values(array_unique(array_column($entries, 'product_id')));
            $this->lockLevels($productIds, $location);

            $rows = [];
            $change = [];
            $delivered = [];

            foreach ($entries as $entry) {
                $rows[] = [
                    'product_id' => $entry['product_id'],
                    'location_id' => $location->getKey(),
                    'type' => $entry['type']->value,
                    'quantity' => $entry['quantity'],
                    'occurred_at' => $entry['occurred_at']->format('Y-m-d H:i:s'),
                    'reference_type' => $entry['reference_type'] ?? null,
                    'reference_id' => $entry['reference_id'] ?? null,
                    'note' => $entry['note'] ?? null,
                    'user_id' => $entry['user_id'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $change[$entry['product_id']] = ($change[$entry['product_id']] ?? 0) + $entry['quantity'];

                if ($entry['type'] === StockMovementType::Restock) {
                    $delivered[$entry['product_id']] = ($delivered[$entry['product_id']] ?? 0) + $entry['quantity'];
                }
            }

            foreach (array_chunk($rows, 1000) as $slice) {
                StockMovement::query()->insert($slice);
            }

            foreach ($change as $productId => $quantity) {
                // Goods that arrive stop counting as "on order", but never below zero.
                DB::update(
                    'update inventory_levels set on_hand = on_hand + ?, on_order = greatest(0, on_order - ?), updated_at = ? where product_id = ? and location_id = ?',
                    [$quantity, $delivered[$productId] ?? 0, $now->toDateTimeString(), $productId, $location->getKey()],
                );
            }
        });
    }

    /**
     * Creates any missing stock rows for the given products at a location, then
     * locks all of them.
     *
     * @param  list<int>  $productIds
     */
    private function lockLevels(array $productIds, Location $location): void
    {
        $now = now();

        InventoryLevel::query()->insertOrIgnore(array_map(fn (int $productId) => [
            'product_id' => $productId,
            'location_id' => $location->getKey(),
            'on_hand' => 0,
            'on_order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $productIds));

        InventoryLevel::query()
            ->whereIn('product_id', $productIds)
            ->where('location_id', $location->getKey())
            ->lockForUpdate()
            ->pluck('id');
    }

    /**
     * Finds (creating if needed) and locks the stock level row, so concurrent
     * changes to the same product queue up instead of overwriting each other.
     */
    private function lockLevel(Product $product, Location $location): InventoryLevel
    {
        // insertOrIgnore makes creation safe when two requests race to create the row.
        InventoryLevel::query()->insertOrIgnore([
            'product_id' => $product->getKey(),
            'location_id' => $location->getKey(),
            'on_hand' => 0,
            'on_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return InventoryLevel::query()
            ->where('product_id', $product->getKey())
            ->where('location_id', $location->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
