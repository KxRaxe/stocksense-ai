<?php

namespace App\Enums;

/**
 * How a product's forecast was made. The model is used for products with a
 * year or more of history; the rest get a plain moving average and are
 * flagged as less reliable.
 */
enum ForecastMethod: string
{
    case Model = 'xgboost';
    case MovingAverage = 'moving_average';

    public function label(): string
    {
        return match ($this) {
            self::Model => 'Forecasting model',
            self::MovingAverage => 'Recent average',
        };
    }
}
