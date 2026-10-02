<?php

namespace App\Models;

use App\Enums\ForecastMethod;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one run predicted for one product in one period: the median and the
 * 10th and 90th percentiles, in units.
 *
 * @property int $id
 * @property int $forecast_run_id
 * @property int $product_id
 * @property int $location_id
 * @property CarbonInterface $period_start
 * @property numeric-string $yhat
 * @property numeric-string $yhat_lower
 * @property numeric-string $yhat_upper
 * @property ForecastMethod $method
 * @property bool $low_confidence
 */
#[Fillable([
    'forecast_run_id', 'product_id', 'location_id', 'period_start',
    'yhat', 'yhat_lower', 'yhat_upper', 'method', 'low_confidence',
])]
class Forecast extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'yhat' => 'decimal:2',
            'yhat_lower' => 'decimal:2',
            'yhat_upper' => 'decimal:2',
            'method' => ForecastMethod::class,
            'low_confidence' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ForecastRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ForecastRun::class, 'forecast_run_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
