<?php

namespace App\Enums;

/**
 * Why stock changed. Each type has a fixed direction, enforced by
 * StockService: initial and restock add stock, sale removes it, and an
 * adjustment (stock-take correction) can go either way.
 */
enum StockMovementType: string
{
    case Initial = 'initial';
    case Restock = 'restock';
    case Sale = 'sale';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Opening stock',
            self::Restock => 'Restock',
            self::Sale => 'Sale',
            self::Adjustment => 'Adjustment',
        };
    }

    /**
     * Whether a signed quantity is allowed for this type.
     */
    public function allows(int $quantity): bool
    {
        return match ($this) {
            self::Initial, self::Restock => $quantity > 0,
            self::Sale => $quantity < 0,
            self::Adjustment => $quantity !== 0,
        };
    }
}
