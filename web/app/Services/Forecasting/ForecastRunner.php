<?php

namespace App\Services\Forecasting;

use App\Enums\ForecastGranularity;
use App\Enums\ForecastStatus;
use App\Jobs\RunForecastJob;
use App\Models\ForecastRun;
use App\Models\User;
use App\Services\Inventory\LocationContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Starts forecast runs. Only one run per granularity is ever going at a time:
 * asking again while one is queued or running returns that run instead of
 * starting another, so a double click, or a scheduled run landing on top of a
 * manual one, does no harm.
 */
class ForecastRunner
{
    public function __construct(private readonly LocationContext $locations) {}

    /**
     * Queues a run, or returns the one already going.
     *
     * @param  int|null  $horizon  Periods ahead; the configured default if left out
     * @param  bool  $queue  False to leave the run waiting, for a caller that will run it itself
     * @return ForecastRun Check `wasRecentlyCreated` to tell a new run from one that was already going
     */
    public function start(ForecastGranularity $granularity, ?User $user = null, ?int $horizon = null, bool $queue = true): ForecastRun
    {
        $horizon ??= $granularity->defaultHorizon();

        if ($horizon < 1 || $horizon > $granularity->maxHorizon()) {
            throw new InvalidArgumentException("A {$granularity->noun()}ly forecast can look 1 to {$granularity->maxHorizon()} {$granularity->noun()}s ahead.");
        }

        $this->stopStaleRuns();

        $running = $this->running($granularity);

        if ($running !== null) {
            return $running;
        }

        try {
            // Its own transaction, so a refusal does not poison one the caller is in.
            $run = DB::transaction(fn () => ForecastRun::query()->create([
                'granularity' => $granularity,
                'horizon' => $horizon,
                'status' => ForecastStatus::Queued,
                'location_id' => $this->locations->id(),
                'triggered_by' => $user?->getKey(),
            ]));
        } catch (UniqueConstraintViolationException) {
            // Someone else started one in the instant since we looked: theirs is the one going.
            return $this->running($granularity)
                ?? throw new LogicException('A forecast run was refused as a duplicate, but none is going.');
        }

        activity('forecasts')
            ->performedOn($run)
            ->causedBy($user)
            ->event('forecast_started')
            ->withProperties(['granularity' => $granularity->value, 'horizon' => $horizon, 'scheduled' => $user === null])
            ->log(ucfirst($granularity->noun()).'ly forecast started');

        if ($queue) {
            RunForecastJob::dispatch($run->id);
        }

        return $run;
    }

    protected function running(ForecastGranularity $granularity): ?ForecastRun
    {
        return ForecastRun::query()
            ->active()
            ->where('granularity', $granularity->value)
            ->latest('id')
            ->first();
    }

    /**
     * A run that has not finished in a long time died with its worker. Marking
     * it failed stops it blocking every later run.
     */
    private function stopStaleRuns(): void
    {
        ForecastRun::query()
            ->active()
            ->where('updated_at', '<', now()->subMinutes((int) config('forecasting.stale_after_minutes')))
            ->update([
                'status' => ForecastStatus::Failed->value,
                'error_message' => 'This run did not finish, so it was stopped.',
                'finished_at' => now(),
            ]);
    }
}
