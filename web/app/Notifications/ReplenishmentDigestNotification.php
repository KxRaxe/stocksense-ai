<?php

namespace App\Notifications;

use App\Enums\NotificationType;

/**
 * A summary of what needs ordering: how many products are at each level of
 * urgency, and the most urgent few.
 */
class ReplenishmentDigestNotification extends StockSenseNotification
{
    /**
     * @param  array{critical: int, low: int, watch: int, overstock: int}  $counts  Products at each risk level
     * @param  list<array{name: string, sku: string, risk: string, quantity: int, when: string}>  $top  The most urgent few
     * @param  int|null  $forecastAgeDays  How old the forecast behind the figures is
     */
    public function __construct(
        private readonly array $counts,
        private readonly array $top,
        private readonly ?int $forecastAgeDays = null,
    ) {}

    public static function type(): NotificationType
    {
        return NotificationType::Digest;
    }

    private function toOrder(): int
    {
        return $this->counts['critical'] + $this->counts['low'] + $this->counts['watch'];
    }

    protected function title(): string
    {
        $count = $this->toOrder();

        return "Replenishment digest: {$count} ".$this->plural($count, 'product').' to order';
    }

    protected function summary(): string
    {
        return "{$this->counts['critical']} critical, {$this->counts['low']} low, {$this->counts['watch']} to watch.";
    }

    protected function path(): string
    {
        return '/recommendations';
    }

    protected function actionText(): string
    {
        return 'See the recommendations';
    }

    protected function lines(): array
    {
        $lines = [
            "{$this->counts['critical']} critical (may run out before an order could arrive), {$this->counts['low']} low (time to order), {$this->counts['watch']} to watch (will need ordering soon).",
        ];

        if ($this->top !== []) {
            $lines[] = 'The most urgent:';
        }

        foreach ($this->top as $item) {
            $lines[] = "{$item['name']} ({$item['sku']}), {$item['risk']}: order {$item['quantity']} {$item['when']}.";
        }

        if ($this->counts['overstock'] > 0) {
            $lines[] = "{$this->counts['overstock']} ".$this->plural($this->counts['overstock'], 'product').' '.($this->counts['overstock'] === 1 ? 'has' : 'have').' more stock than needed.';
        }

        if ($this->forecastAgeDays !== null && $this->forecastAgeDays > (int) config('replenishment.stale_forecast_days')) {
            $lines[] = "The forecast behind these figures is {$this->forecastAgeDays} days old. A fresh one would make them more reliable.";
        }

        return $lines;
    }
}
