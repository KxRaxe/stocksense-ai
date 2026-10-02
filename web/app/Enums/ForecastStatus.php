<?php

namespace App\Enums;

/**
 * Where a forecast run is: waiting for a worker, training and forecasting,
 * done, or failed.
 */
enum ForecastStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Waiting to start',
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    /**
     * Whether the run is still going (the screen keeps refreshing while it is).
     */
    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
