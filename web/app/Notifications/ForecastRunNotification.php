<?php

namespace App\Notifications;

use App\Enums\ForecastGranularity;
use App\Enums\NotificationType;

/**
 * A forecast run has finished, with how accurate it measured, or has failed,
 * with why.
 */
class ForecastRunNotification extends StockSenseNotification
{
    /**
     * @param  string|null  $failure  Why it failed (a message written for people); null if it succeeded
     * @param  array{model: float|null, seasonal_naive: float|null}|null  $accuracy  Error as a percentage of units sold, for the model and for last year's number
     */
    public function __construct(
        private readonly ForecastGranularity $granularity,
        private readonly int $horizon,
        private readonly ?string $failure = null,
        private readonly ?array $accuracy = null,
    ) {}

    public static function type(): NotificationType
    {
        return NotificationType::ForecastRun;
    }

    protected function title(): string
    {
        return $this->failure === null
            ? "{$this->granularity->label()} forecast is ready"
            : "{$this->granularity->label()} forecast did not finish";
    }

    protected function summary(): string
    {
        return $this->failure ?? "Looks {$this->horizon} {$this->granularity->noun()}s ahead.";
    }

    protected function path(): string
    {
        return $this->failure === null
            ? "/forecasts/accuracy?granularity={$this->granularity->value}"
            : "/forecasts?granularity={$this->granularity->value}";
    }

    protected function actionText(): string
    {
        return $this->failure === null ? 'See how accurate it is' : 'Open forecasts';
    }

    protected function lines(): array
    {
        if ($this->failure !== null) {
            return ["The {$this->granularity->noun()}ly forecast did not finish.", $this->failure];
        }

        $lines = ["The {$this->granularity->noun()}ly forecast finished. It looks {$this->horizon} {$this->granularity->noun()}s ahead."];
        $model = $this->accuracy['model'] ?? null;

        if ($model !== null) {
            $line = 'On recent '.$this->granularity->noun().'s it was typically off by '.number_format($model, 1).'% of units sold';
            $naive = $this->accuracy['seasonal_naive'] ?? null;
            $lines[] = $line.($naive !== null ? ', against '.number_format($naive, 1)."% for the same {$this->granularity->noun()} last year." : '.');
        }

        return $lines;
    }
}
