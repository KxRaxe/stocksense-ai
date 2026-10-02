<?php

namespace App\Jobs;

use App\Services\Notifications\NotificationDispatcher;
use App\Services\Replenishment\RecommendationGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Works out what to reorder from the latest forecast and the stock as it is
 * now, then tells the people who decide about any product that is critical.
 * Runs every morning, and after each forecast run.
 */
class GenerateRecommendationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(RecommendationGenerator $generator, NotificationDispatcher $notifications): void
    {
        $result = $generator->generate();

        $notifications->criticalStock($result->critical);
    }
}
