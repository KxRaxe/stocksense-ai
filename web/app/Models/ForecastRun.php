<?php

namespace App\Models;

use App\Enums\ForecastGranularity;
use App\Enums\ForecastStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One run of the forecasting pipeline: what it was asked, how it went, how
 * accurate the model measured, and (in `forecasts`) what it predicted.
 *
 * Accuracy figures are those the ML service reports: `metrics` for the model
 * and `baseline_metrics` for the two yardsticks it is judged against, each
 * `{mae, rmse, mape, wape, n, coverage}`.
 *
 * @property int $id
 * @property ForecastGranularity $granularity
 * @property int $horizon
 * @property ForecastStatus $status
 * @property int $location_id
 * @property string|null $model_version
 * @property CarbonInterface|null $as_of
 * @property array{mae: float, rmse: float, mape: float|null, wape: float|null, n: int, coverage: float|null}|null $metrics
 * @property array{seasonal_naive: array<string, mixed>, moving_average: array<string, mixed>}|null $baseline_metrics
 * @property array<string, array{model: array<string, mixed>, seasonal_naive: array<string, mixed>, moving_average: array<string, mixed>}>|null $per_category_metrics
 * @property list<array{feature: string, importance: float}>|null $feature_importance
 * @property array<int, float>|null $residual_std Keyed by product id
 * @property array{first_period: string, last_period: string, n_series: int, n_model_series: int, n_low_confidence: int, n_rows: int, backtest_folds: int, backtest_origins: list<string>}|null $training
 * @property string|null $error_message
 * @property int|null $triggered_by
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'granularity', 'horizon', 'status', 'location_id', 'model_version', 'as_of',
    'metrics', 'baseline_metrics', 'per_category_metrics', 'feature_importance',
    'residual_std', 'training', 'error_message', 'triggered_by', 'started_at', 'finished_at',
])]
class ForecastRun extends Model
{
    protected function casts(): array
    {
        return [
            'granularity' => ForecastGranularity::class,
            'status' => ForecastStatus::class,
            'as_of' => 'date',
            'metrics' => 'array',
            'baseline_metrics' => 'array',
            'per_category_metrics' => 'array',
            'feature_importance' => 'array',
            'residual_std' => 'array',
            'training' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Runs that finished successfully, newest first.
     *
     * @param  Builder<static>  $query
     */
    public function scopeCompleted(Builder $query, ForecastGranularity $granularity): void
    {
        $query->where('granularity', $granularity->value)
            ->where('status', ForecastStatus::Completed->value)
            ->orderByDesc('id');
    }

    /**
     * The newest completed run at a granularity: the forecast people should rely on now.
     */
    public static function latestCompleted(ForecastGranularity $granularity): ?self
    {
        return static::query()->completed($granularity)->first();
    }

    /**
     * Runs that are queued or running.
     *
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [ForecastStatus::Queued->value, ForecastStatus::Running->value]);
    }

    /**
     * @return HasMany<Forecast, $this>
     */
    public function forecasts(): HasMany
    {
        return $this->hasMany(Forecast::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function trigger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
