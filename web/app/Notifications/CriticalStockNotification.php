<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Support\Str;

/**
 * Products expected to run out before a new order could arrive. One message
 * per round of recommendations, listing the products that were not already
 * reported today (see NotificationDispatcher).
 */
class CriticalStockNotification extends StockSenseNotification
{
    private const LISTED = 10;

    /**
     * @param  list<array{name: string, sku: string, available: int, expected: float|int}>  $items  The products, most urgent first
     */
    public function __construct(private readonly array $items) {}

    public static function type(): NotificationType
    {
        return NotificationType::CriticalStock;
    }

    protected function title(): string
    {
        $count = count($this->items);

        return "Critical stock: {$count} ".Str::plural('product', $count).' may run out';
    }

    protected function summary(): string
    {
        $names = array_map(fn (array $item) => $item['name'], array_slice($this->items, 0, 3));
        $more = count($this->items) - count($names);

        return implode(', ', $names).($more > 0 ? " and {$more} more" : '').' may run out before a new order could arrive.';
    }

    protected function path(): string
    {
        return '/recommendations?risk=critical';
    }

    protected function actionText(): string
    {
        return 'See what to order';
    }

    protected function lines(): array
    {
        $count = count($this->items);
        $lines = ["{$count} ".Str::plural('product', $count).' '.($count === 1 ? 'is' : 'are').' expected to run out before a new order could arrive.'];

        foreach (array_slice($this->items, 0, self::LISTED) as $item) {
            $expected = number_format((float) $item['expected'], 0);
            $lines[] = "{$item['name']} ({$item['sku']}): {$item['available']} available, {$expected} expected to sell over the lead time.";
        }

        if ($count > self::LISTED) {
            $lines[] = 'And '.($count - self::LISTED).' more.';
        }

        return $lines;
    }
}
