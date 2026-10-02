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
     * Stock a product started with. Does nothing for zero.
     */
    public function openingStock(Product $product, int $quantity, ?User $user = null): ?StockMovement
    {
        if ($quantity === 0) {
            return null;
        }

        return $this->record($product, StockMovementType::Initial, $quantity, user: $user);
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
