<?php

namespace App\Services\Replenishment;

use App\Enums\RecommendationStatus;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\Inventory\StockService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What people can do with a recommendation: accept it, accept it with a
 * different quantity, dismiss it, or (once accepted) cancel the order.
 *
 * The system only ever advises. Accepting means "I will order this": the
 * quantity is counted as on order, so the product is not recommended again,
 * until the goods are received (a restock brings it back down) or the order is
 * cancelled. Every decision is recorded with who made it, when, and a note, in
 * the recommendation itself and in the audit log.
 */
class RecommendationDecisions
{
    public const MAX_QUANTITY = 10_000_000;

    public function __construct(private readonly StockService $stock) {}

    /**
     * "I will order what is recommended."
     */
    public function accept(Recommendation $recommendation, User $user, ?string $note = null): Recommendation
    {
        return $this->order($recommendation, $user, null, $note);
    }

    /**
     * "I will order, but a different amount."
     *
     * @throws ValidationException When the quantity is not a sensible whole number
     */
    public function adjust(Recommendation $recommendation, User $user, int $quantity, ?string $note = null): Recommendation
    {
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw ValidationException::withMessages(['quantity' => 'Enter a whole number of units, at least 1.']);
        }

        return $this->order($recommendation, $user, $quantity, $note);
    }

    /**
     * "Not ordering this now." The product is left alone for a while (see
     * `replenishment.snooze_days`) instead of being recommended again tomorrow.
     */
    public function dismiss(Recommendation $recommendation, User $user, ?string $note = null): Recommendation
    {
        return DB::transaction(function () use ($recommendation, $user, $note) {
            $locked = $this->lockPending($recommendation);

            $locked->forceFill([
                'status' => RecommendationStatus::Dismissed,
                'decided_by' => $user->getKey(),
                'decided_at' => now(),
                'note' => $this->clean($note),
                'snoozed_until' => CarbonImmutable::today()->addDays((int) config('replenishment.snooze_days')),
            ])->save();

            $this->log($locked, $user, 'recommendation_dismissed', 'Recommendation dismissed');

            return $locked;
        });
    }

    /**
     * An accepted order that will not be placed after all: takes it off "on order"
     * so it counts toward the stock position no more.
     */
    public function cancel(Recommendation $recommendation, User $user, ?string $note = null): Recommendation
    {
        return DB::transaction(function () use ($recommendation, $user, $note) {
            $locked = Recommendation::query()->whereKey($recommendation->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isOrdered()) {
                throw ValidationException::withMessages(['recommendation' => 'Only an order that was accepted can be cancelled.']);
            }

            $this->stock->reduceOnOrder($locked->product, (int) $locked->final_qty);

            $locked->forceFill([
                'status' => RecommendationStatus::Cancelled,
                'cancelled_by' => $user->getKey(),
                'cancelled_at' => now(),
                'note' => $this->clean($note) ?? $locked->note,
            ])->save();

            $this->log($locked, $user, 'recommendation_cancelled', 'Order cancelled');

            return $locked;
        });
    }

    private function order(Recommendation $recommendation, User $user, ?int $quantity, ?string $note): Recommendation
    {
        return DB::transaction(function () use ($recommendation, $user, $quantity, $note) {
            $locked = $this->lockPending($recommendation);

            if ($locked->recommended_qty < 1) {
                throw ValidationException::withMessages(['recommendation' => 'There is nothing to order for this product.']);
            }

            $final = $quantity ?? $locked->recommended_qty;
            $adjusted = $final !== $locked->recommended_qty;

            $this->stock->addOnOrder($locked->product, $final);

            $locked->forceFill([
                'status' => $adjusted ? RecommendationStatus::Adjusted : RecommendationStatus::Accepted,
                'final_qty' => $final,
                'decided_by' => $user->getKey(),
                'decided_at' => now(),
                'note' => $this->clean($note),
            ])->save();

            $this->log(
                $locked,
                $user,
                $adjusted ? 'recommendation_adjusted' : 'recommendation_accepted',
                $adjusted ? "Recommendation accepted with {$final} instead of {$locked->recommended_qty}" : "Recommendation accepted for {$final}",
            );

            return $locked;
        });
    }

    /**
     * Loads the recommendation under a lock and checks it still waits for a decision:
     * two people pressing Accept at once must not order twice.
     *
     * @throws ValidationException
     */
    private function lockPending(Recommendation $recommendation): Recommendation
    {
        $locked = Recommendation::query()->whereKey($recommendation->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== RecommendationStatus::Pending) {
            throw ValidationException::withMessages(['recommendation' => 'This recommendation has already been dealt with or is no longer needed.']);
        }

        return $locked;
    }

    private function clean(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : $note;
    }

    private function log(Recommendation $recommendation, User $user, string $event, string $description): void
    {
        activity('replenishment')
            ->performedOn($recommendation)
            ->causedBy($user)
            ->event($event)
            ->withProperties([
                'product_id' => $recommendation->product_id,
                'recommended' => $recommendation->recommended_qty,
                'quantity' => $recommendation->final_qty,
                'risk' => $recommendation->risk_level->value,
            ])
            ->log($description);
    }
}
