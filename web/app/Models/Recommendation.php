<?php

namespace App\Models;

use App\Enums\RecommendationStatus;
use App\Enums\RiskLevel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product's reorder advice and what was decided about it. See the
 * migration for the life of a row.
 *
 * @property int $id
 * @property int $product_id
 * @property int $location_id
 * @property int|null $forecast_run_id
 * @property RiskLevel $risk_level
 * @property RecommendationStatus $status
 * @property int $on_hand
 * @property int $on_order
 * @property int $lead_time_days
 * @property numeric-string $lead_time_demand
 * @property int $safety_stock
 * @property int $reorder_point
 * @property int $order_up_to
 * @property numeric-string|null $days_of_cover
 * @property int $recommended_qty
 * @property CarbonInterface|null $order_by_date
 * @property string $explanation
 * @property int|null $final_qty
 * @property int|null $decided_by
 * @property CarbonInterface|null $decided_at
 * @property string|null $note
 * @property CarbonInterface|null $snoozed_until
 * @property int|null $cancelled_by
 * @property CarbonInterface|null $cancelled_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'product_id', 'location_id', 'forecast_run_id', 'risk_level', 'status',
    'on_hand', 'on_order', 'lead_time_days', 'lead_time_demand', 'safety_stock',
    'reorder_point', 'order_up_to', 'days_of_cover', 'recommended_qty', 'order_by_date',
    'explanation', 'final_qty', 'decided_by', 'decided_at', 'note', 'snoozed_until',
    'cancelled_by', 'cancelled_at',
])]
class Recommendation extends Model
{
    protected $table = 'replenishment_recommendations';

    protected function casts(): array
    {
        return [
            'risk_level' => RiskLevel::class,
            'status' => RecommendationStatus::class,
            'lead_time_demand' => 'decimal:2',
            'days_of_cover' => 'decimal:1',
            'order_by_date' => 'date',
            'decided_at' => 'datetime',
            'snoozed_until' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Recommendations that currently apply: waiting for a decision, or for information.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', RecommendationStatus::openValues());
    }

    /**
     * Most urgent first, then by when the order is due, then by product.
     *
     * @param  Builder<static>  $query
     */
    public function scopeMostUrgentFirst(Builder $query): void
    {
        $query->orderByRaw("case replenishment_recommendations.risk_level when 'critical' then 1 when 'low' then 2 when 'watch' then 3 when 'overstock' then 4 else 5 end")
            ->orderBy('replenishment_recommendations.order_by_date')
            ->orderBy('replenishment_recommendations.id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ForecastRun, $this>
     */
    public function forecastRun(): BelongsTo
    {
        return $this->belongsTo(ForecastRun::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
