<?php

namespace App\Services\Replenishment;

use App\Enums\ForecastGranularity;
use App\Enums\RecommendationStatus;
use App\Enums\RiskLevel;
use App\Models\Forecast;
use App\Models\ForecastRun;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Product;
use App\Models\Recommendation;
use App\Services\Inventory\LocationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns the latest forecast and the current stock into reorder advice.
 *
 * Every active product with a forecast is assessed by the calculator. Each
 * product has at most one open recommendation, which is refreshed in place on
 * every round so a person never sees duplicates:
 *
 * - Needs an order (critical, low, watch): open or refresh a pending
 *   recommendation, unless the last one for this product was dismissed
 *   recently, in which case the product is left alone for a while.
 * - Overstocked: an informational row, refreshed in place.
 * - Fine: any open recommendation is closed as no longer needed (the stock
 *   arrived, or demand fell).
 *
 * Stock changes daily even when the forecast does not, so this runs every
 * morning and after each forecast run. Decided recommendations are never touched.
 */
class RecommendationGenerator
{
    public function __construct(
        private readonly ReplenishmentCalculator $calculator,
        private readonly LocationContext $locations,
    ) {}

    public function generate(?CarbonImmutable $today = null): GenerationResult
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $run = ForecastRun::latestCompleted(ForecastGranularity::Week) ?? ForecastRun::latestCompleted(ForecastGranularity::Month);

        if ($run === null) {
            return new GenerationResult(null);
        }

        $location = $this->locations->current();

        /** @var Collection<int, Collection<int, Forecast>> $forecasts */
        $forecasts = Forecast::query()
            ->where('forecast_run_id', $run->id)
            ->where('location_id', $location->id)
            ->orderBy('period_start')
            ->get()
            ->groupBy('product_id');

        $productIds = $forecasts->keys()->all();

        $products = Product::query()->with('category')->where('is_active', true)->whereIn('id', $productIds)->get();
        $levels = InventoryLevel::query()->where('location_id', $location->id)->whereIn('product_id', $productIds)->get()->keyBy('product_id');
        $open = Recommendation::query()->open()->where('location_id', $location->id)->get()->keyBy('product_id');
        $snoozed = Recommendation::query()
            ->where('location_id', $location->id)
            ->where('status', RecommendationStatus::Dismissed->value)
            ->whereDate('snoozed_until', '>=', $today->toDateString())
            ->pluck('product_id')
            ->flip();

        $created = $updated = $expired = $skipped = 0;
        $byRisk = [];
        $critical = new Collection;
        $assessed = [];

        DB::transaction(function () use ($products, $forecasts, $levels, $open, $snoozed, $run, $location, $today, &$created, &$updated, &$expired, &$skipped, &$byRisk, &$critical, &$assessed) {
            foreach ($products as $product) {
                $level = $levels->get($product->id);
                $rows = $forecasts->get($product->id);

                $result = $this->calculator->calculate($this->input($product, $rows, $level, $run, $today));
                $byRisk[$result->risk->value] = ($byRisk[$result->risk->value] ?? 0) + 1;
                $assessed[$product->id] = true;

                $existing = $open->get($product->id);

                if ($result->risk->isActionable() && $snoozed->has($product->id)) {
                    $skipped++;

                    continue;
                }

                $saved = $this->apply($product, $result, $existing, $run, $location, $created, $updated, $expired);

                if ($saved !== null && $saved->risk_level === RiskLevel::Critical && $saved->status === RecommendationStatus::Pending) {
                    $critical->push($saved->setRelation('product', $product));
                }
            }
        });

        // Products that dropped out of the forecast or were archived no longer have anything to recommend.
        foreach ($open as $productId => $recommendation) {
            if (! isset($assessed[$productId])) {
                $expired += $this->expire($recommendation);
            }
        }

        return new GenerationResult($run, $created, $updated, $expired, $skipped, $byRisk, $critical);
    }

    /**
     * Opens, refreshes or closes the recommendation for one product, as its assessment says.
     */
    private function apply(
        Product $product,
        ReplenishmentResult $result,
        ?Recommendation $existing,
        ForecastRun $run,
        Location $location,
        int &$created,
        int &$updated,
        int &$expired,
    ): ?Recommendation {
        if ($result->risk === RiskLevel::Ok) {
            if ($existing !== null) {
                $expired += $this->expire($existing);
            }

            return null;
        }

        $status = $result->risk->isActionable() ? RecommendationStatus::Pending : RecommendationStatus::Info;
        $values = $this->values($product, $result, $status, $run);

        if ($existing !== null) {
            // Only while it is still open: if someone decided it a moment ago, leave their decision alone.
            $changed = Recommendation::query()->whereKey($existing->id)->open()->update($values);

            if ($changed === 0) {
                return null;
            }

            $updated++;

            return $existing->fresh();
        }

        try {
            $recommendation = DB::transaction(fn () => Recommendation::query()->create([
                ...$values,
                'product_id' => $product->id,
                'location_id' => $location->id,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Another round opened one in the meantime.
            return null;
        }

        $created++;

        return $recommendation;
    }

    private function expire(Recommendation $recommendation): int
    {
        return Recommendation::query()->whereKey($recommendation->id)->open()->update(['status' => RecommendationStatus::Expired->value]);
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Product $product, ReplenishmentResult $result, RecommendationStatus $status, ForecastRun $run): array
    {
        return [
            'forecast_run_id' => $run->id,
            'risk_level' => $result->risk->value,
            'status' => $status->value,
            'on_hand' => $result->onHand,
            'on_order' => $result->onOrder,
            'lead_time_days' => $product->lead_time_days,
            'lead_time_demand' => $result->leadTimeDemand,
            'safety_stock' => $result->safetyStock,
            'reorder_point' => $result->reorderPoint,
            'order_up_to' => $result->orderUpTo,
            'days_of_cover' => $result->daysOfCover,
            'recommended_qty' => $result->quantity,
            'order_by_date' => $result->orderBy?->toDateString(),
            'explanation' => $result->explanation,
        ];
    }

    /**
     * @param  Collection<int, Forecast>  $rows  The product's forecast periods, oldest first
     */
    private function input(Product $product, Collection $rows, ?InventoryLevel $level, ForecastRun $run, CarbonImmutable $today): ReplenishmentInput
    {
        $granularity = $run->granularity;

        $periods = [];

        foreach ($rows as $row) {
            $periods[] = [
                'start' => CarbonImmutable::instance($row->period_start)->startOfDay(),
                'end' => $granularity->endOf(CarbonImmutable::instance($row->period_start))->startOfDay(),
                'units' => (float) $row->yhat,
            ];
        }

        return new ReplenishmentInput(
            onHand: $level->on_hand ?? 0,
            onOrder: $level->on_order ?? 0,
            leadTimeDays: $product->lead_time_days,
            reviewDays: (int) config('replenishment.review_days'),
            moq: $product->moq,
            packSize: $product->pack_size,
            reorderPointOverride: $product->reorder_point_override,
            safetyStockOverride: $product->safety_stock_override,
            serviceLevel: (float) $product->category->service_level,
            errorPerPeriod: $run->residual_std[$product->id] ?? $this->errorFromRange($rows),
            periodDays: $granularity->averageDays(),
            demand: new DemandForecast($periods),
            today: $today,
            overstockDays: (int) config('replenishment.overstock_days'),
            projectionDays: (int) config('replenishment.projection_days'),
        );
    }

    /**
     * When the run gave no typical error for a product, take it from how wide
     * the forecast's own range is. A P10-P90 range spans about 2.56 standard deviations.
     *
     * @param  Collection<int, Forecast>  $rows
     */
    private function errorFromRange(Collection $rows): float
    {
        $width = $rows->avg(fn (Forecast $row) => (float) $row->yhat_upper - (float) $row->yhat_lower);

        return $width === null ? 0.0 : (float) $width / 2.563;
    }
}
