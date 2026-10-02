<?php

namespace App\Console\Commands;

use App\Enums\RiskLevel;
use App\Jobs\GenerateRecommendationsJob;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Replenishment\RecommendationGenerator;
use Illuminate\Console\Command;

class GenerateRecommendations extends Command
{
    protected $signature = 'recommendations:generate
        {--sync : Do it now in this process instead of queueing it}';

    protected $description = 'Work out what to reorder from the latest forecast and current stock (the scheduler does this every morning)';

    public function handle(RecommendationGenerator $generator, NotificationDispatcher $notifications): int
    {
        if (! $this->option('sync')) {
            GenerateRecommendationsJob::dispatch();
            $this->components->info('Recommendations queued.');

            return self::SUCCESS;
        }

        $result = $generator->generate();

        if ($result->run === null) {
            $this->components->warn('There is no completed forecast to base recommendations on yet. Run one first (forecast:run).');

            return self::SUCCESS;
        }

        $notified = $notifications->criticalStock($result->critical);

        $this->components->info(sprintf(
            'Based on forecast run #%d: %d products assessed (%d critical, %d low, %d to watch, %d overstocked). %d opened, %d refreshed, %d closed, %d left alone after a dismissal. %d critical products reported.',
            $result->run->id,
            $result->assessed(),
            $result->count(RiskLevel::Critical),
            $result->count(RiskLevel::Low),
            $result->count(RiskLevel::Watch),
            $result->count(RiskLevel::Overstock),
            $result->created,
            $result->updated,
            $result->expired,
            $result->snoozed,
            $notified,
        ));

        return self::SUCCESS;
    }
}
