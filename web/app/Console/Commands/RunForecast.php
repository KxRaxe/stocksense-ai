<?php

namespace App\Console\Commands;

use App\Enums\ForecastGranularity;
use App\Enums\ForecastStatus;
use App\Jobs\RunForecastJob;
use App\Services\Forecasting\ForecastRunner;
use Illuminate\Console\Command;

class RunForecast extends Command
{
    protected $signature = 'forecast:run
        {granularity? : week or month (both if left out)}
        {--horizon= : Periods ahead (the configured default if left out)}
        {--sync : Run it now in this process instead of queueing it}';

    protected $description = 'Run the sales forecast (the scheduler does this weekly and monthly)';

    public function handle(ForecastRunner $runner): int
    {
        $argument = $this->argument('granularity');
        $chosen = $argument === null ? null : ForecastGranularity::tryFrom((string) $argument);

        if ($argument !== null && $chosen === null) {
            $this->components->error('Choose "week" or "month".');

            return self::FAILURE;
        }

        $granularities = $chosen === null ? ForecastGranularity::cases() : [$chosen];

        $horizon = $this->option('horizon') === null ? null : (int) $this->option('horizon');
        $failed = false;

        foreach ($granularities as $granularity) {
            $run = $runner->start($granularity, null, $horizon, queue: false);

            if (! $run->wasRecentlyCreated) {
                $this->components->warn("A {$granularity->noun()}ly forecast is already running (run #{$run->id}).");

                continue;
            }

            if ($this->option('sync')) {
                RunForecastJob::dispatchSync($run->id);
                $run->refresh();

                if ($run->status === ForecastStatus::Completed) {
                    $this->components->info("Run #{$run->id} completed: model {$run->model_version}.");
                } else {
                    $this->components->error("Run #{$run->id} {$run->status->value}: {$run->error_message}");
                    $failed = true;
                }

                continue;
            }

            RunForecastJob::dispatch($run->id);
            $this->components->info(ucfirst($granularity->noun())."ly forecast queued (run #{$run->id}).");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
