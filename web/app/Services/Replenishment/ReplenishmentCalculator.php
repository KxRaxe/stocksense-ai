<?php

namespace App\Services\Replenishment;

use App\Enums\RiskLevel;
use Carbon\CarbonImmutable;

/**
 * Works out when to reorder a product and how much, from the forecast and the
 * stock position. Deterministic and explainable: every figure is on the result
 * and the explanation spells out how it was reached. No database, no clock.
 *
 *   LT   lead time in days                R   review period in days
 *   D(n) expected demand over the next n days (the forecast, day by day)
 *   z    standard deviations for the target service level
 *   σ    typical forecast error over LT + R days
 *            = error per period × √((LT + R) ÷ days per period)
 *
 *   safety stock   SS  = override, or z × σ
 *   reorder point  ROP = override, or D(LT) + SS
 *   order up to    S   = D(LT + R) + SS
 *   position       IP  = on hand + on order
 *
 * If IP is at or below ROP an order is due now, for S − IP (at least the
 * minimum order, rounded up to a whole pack). Otherwise the order falls due on
 * the day IP is expected to reach ROP, for S − ROP.
 *
 *   Critical   IP < D(LT)               it will run out before a new order arrives
 *   Low        IP ≤ ROP                 order now
 *   Watch      reaches ROP before the next review (fewer than R days away)     order soon
 *   Overstock  more than N days of cover
 *   Ok         otherwise
 */
class ReplenishmentCalculator
{
    public function calculate(ReplenishmentInput $in): ReplenishmentResult
    {
        $lead = max(0, $in->leadTimeDays);
        $review = max(0, $in->reviewDays);
        $horizon = $lead + $review;
        $rates = $in->demand->dailyRates($in->today, max($horizon, $in->projectionDays));

        $leadDemand = $this->sum($rates, 0, $lead);
        $horizonDemand = $this->sum($rates, 0, $horizon);
        $position = max($in->onHand, 0) + max($in->onOrder, 0);
        $hasDemand = $horizonDemand > 0;

        $z = Normal::quantile(min(max($in->serviceLevel / 100, 0.5), 0.999));
        $sigma = $in->errorPerPeriod * sqrt($horizon / max($in->periodDays, 1.0));

        // A hand-set reorder point implies the safety stock behind it (what it holds above the
        // lead-time demand), so an order always brings stock up above that point. With no
        // demand expected there is nothing to protect against.
        $safety = $in->safetyStockOverride ?? match (true) {
            $in->reorderPointOverride !== null => max(0, $in->reorderPointOverride - $this->up($leadDemand)),
            $hasDemand => $this->up($z * $sigma),
            default => 0,
        };
        $reorderPoint = $in->reorderPointOverride ?? $this->up($leadDemand + $safety);
        $orderUpTo = $this->up($horizonDemand + $safety);

        $average = $in->demand->averageDaily();
        $cover = $average > 0 ? $position / $average : null;

        // It will run out before a new order could arrive. Always due, whatever reorder point was set.
        $critical = $hasDemand && $leadDemand > 0 && $position < $leadDemand;
        $dueNow = $critical || (($hasDemand || $in->reorderPointOverride !== null) && $position <= $reorderPoint);
        $reachesOn = $dueNow ? null : $this->reachesOn($rates, $position, $reorderPoint, $in->today, $hasDemand);

        $risk = match (true) {
            $critical => RiskLevel::Critical,
            $dueNow => RiskLevel::Low,
            $reachesOn !== null && $in->today->diffInDays($reachesOn) < $review => RiskLevel::Watch,
            $cover !== null && $cover > $in->overstockDays => RiskLevel::Overstock,
            default => RiskLevel::Ok,
        };

        $orderBy = $dueNow ? $in->today : $reachesOn;
        // An order placed today fills the gap from the position; one placed later, from the reorder point.
        $gap = $dueNow ? $orderUpTo - $position : $orderUpTo - $reorderPoint;
        $quantity = $risk === RiskLevel::Overstock || $orderBy === null ? 0 : $this->order($gap, $in);

        return new ReplenishmentResult(
            risk: $risk,
            onHand: max($in->onHand, 0),
            onOrder: max($in->onOrder, 0),
            position: $position,
            leadTimeDemand: round($leadDemand, 2),
            horizonDemand: round($horizonDemand, 2),
            safetyStock: $safety,
            reorderPoint: $reorderPoint,
            orderUpTo: $orderUpTo,
            quantity: $quantity,
            orderBy: $risk === RiskLevel::Overstock ? null : $orderBy,
            daysOfCover: $cover === null ? null : round($cover, 1),
            explanation: $this->explain($risk, $in, $position, $leadDemand, $safety, $reorderPoint, $orderUpTo, $quantity, $gap, $orderBy, $cover, $hasDemand),
        );
    }

    /**
     * The first day, within the projection, on which stock is expected to have
     * fallen to the reorder point: the day to place an order.
     *
     * @param  list<float>  $rates
     */
    private function reachesOn(array $rates, int $position, int $reorderPoint, CarbonImmutable $today, bool $hasDemand): ?CarbonImmutable
    {
        if (! $hasDemand) {
            return null;
        }

        $remaining = $position;

        foreach ($rates as $day => $rate) {
            $remaining -= $rate;

            if ($remaining <= $reorderPoint) {
                // `$day` days of demand had gone by at the start of the day after this one.
                return $today->addDays($day + 1);
            }
        }

        return null;
    }

    /**
     * The quantity to order to cover `$gap` units: at least the minimum order,
     * in whole packs.
     */
    private function order(int $gap, ReplenishmentInput $in): int
    {
        $pack = max($in->packSize, 1);

        return (int) (ceil(max($gap, $in->moq, 1) / $pack) * $pack);
    }

    /**
     * @param  list<float>  $rates
     */
    private function sum(array $rates, int $from, int $length): float
    {
        return array_sum(array_slice($rates, $from, $length));
    }

    /**
     * Rounds up to whole units, ignoring floating-point dust (32.0000001 is 32).
     */
    private function up(float $value): int
    {
        return max(0, (int) ceil(round($value, 6)));
    }

    private function explain(
        RiskLevel $risk,
        ReplenishmentInput $in,
        int $position,
        float $leadDemand,
        int $safety,
        int $reorderPoint,
        int $orderUpTo,
        int $quantity,
        int $gap,
        ?CarbonImmutable $orderBy,
        ?float $cover,
        bool $hasDemand,
    ): string {
        $onHand = max($in->onHand, 0);
        $onOrder = max($in->onOrder, 0);
        $have = $onOrder > 0
            ? "You have {$this->n($onHand)} on hand and {$this->n($onOrder)} on order, {$this->n($position)} in all."
            : "You have {$this->n($onHand)} on hand.";
        $days = $in->leadTimeDays === 1 ? '1-day' : "{$in->leadTimeDays}-day";
        $reorder = "Expected demand over the {$days} lead time is {$this->n($leadDemand)}; with {$this->n($safety)} safety stock, the reorder point is {$this->n($reorderPoint)}.";
        $buy = $this->quantityText($quantity, $in);
        $goal = ", to bring stock up to {$this->n($orderUpTo)}".($gap < $in->moq ? " (the minimum order is {$this->n($in->moq)})" : '').'.';

        if (! $hasDemand && $in->reorderPointOverride === null) {
            return "No demand is expected in the forecast, so there is nothing to reorder. {$have}";
        }

        return match ($risk) {
            RiskLevel::Critical => "Stock is expected to run out before a new order could arrive: {$this->n($position)} available against {$this->n($leadDemand)} of expected demand over the {$days} lead time. {$have} Order {$buy} now{$goal}",
            RiskLevel::Low => "{$reorder} {$have} That is at or below the reorder point. Order {$buy} now{$goal}",
            RiskLevel::Watch => "{$reorder} {$have} Stock is expected to reach the reorder point around {$this->date($orderBy)}. Plan to order {$buy} then{$goal}",
            RiskLevel::Overstock => "{$have} At the forecast rate that is about {$this->n((float) $cover)} days of demand, more than the {$in->overstockDays} days considered comfortable. No order is needed.",
            RiskLevel::Ok => $orderBy !== null && $quantity > 0
                ? "{$reorder} {$have} Stock is expected to reach the reorder point around {$this->date($orderBy)}, when {$buy} would be ordered{$goal}"
                : "{$reorder} {$have} Stock is comfortable for now.",
        };
    }

    /**
     * "60" or "60 (5 packs of 12)".
     */
    private function quantityText(int $quantity, ReplenishmentInput $in): string
    {
        $text = $this->n($quantity);

        if ($in->packSize > 1) {
            $packs = intdiv($quantity, $in->packSize);
            $text .= " ({$packs} ".($packs === 1 ? 'pack' : 'packs')." of {$in->packSize})";
        }

        return $text;
    }

    /**
     * 12 -> "12", 12.46 -> "12.5", 1234 -> "1,234".
     */
    private function n(float $value): string
    {
        return abs($value) >= 100 || $value == floor($value)
            ? number_format($value, 0)
            : rtrim(rtrim(number_format($value, 1), '0'), '.');
    }

    private function date(?CarbonImmutable $date): string
    {
        return $date?->format('M j') ?? 'soon';
    }
}
