<?php

namespace App\Jobs;

use App\Enums\ForecastStatus;
use App\Models\ForecastRun;
use App\Services\Forecasting\ForecastException;
use App\Services\Forecasting\ForecastExecutor;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs one forecast: training the model takes a while, so it happens on the
 * `ml` queue rather than while someone waits. It runs once; if it fails the run
 * is marked failed with a reason, and the person can start another.
 */
class RunForecastJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Training and forecasting for a large catalogue can take minutes. The
    // worker's limit (config/horizon.php) and the queue's retry_after
    // (config/queue.php) are both longer than this.
    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue('ml');
    }

    public function handle(ForecastExecutor $executor): void
    {
        $run = ForecastRun::query()->find($this->runId);

        // Already picked up, finished, or removed.
        if ($run === null || $run->status !== ForecastStatus::Queued) {
            return;
        }

        $run->forceFill(['status' => ForecastStatus::Running, 'started_at' => now()])->save();

        try {
            $executor->execute($run);
        } catch (ForecastException $e) {
            // Something the person can act on: say what it was.
            $this->markFailed($e->getMessage());
            $this->tell();

            return;
        }

        $this->tell();

        // The forecast is new, so reorder advice built on it is too.
        GenerateRecommendationsJob::dispatch();
    }

    public function failed(Throwable $exception): void
    {
        $this->markFailed('The forecast stopped because of an unexpected error. Try again; if it keeps happening, check the logs.');
        $this->tell();

        report($exception);
    }

    /**
     * Tells the people who should know how the run went. A failure to send must
     * not undo a forecast that worked, so it is reported and not thrown.
     */
    private function tell(): void
    {
        $run = ForecastRun::query()->find($this->runId);

        if ($run !== null) {
            rescue(fn () => app(NotificationDispatcher::class)->forecastRun($run));
        }
    }

    private function markFailed(string $message): void
    {
        ForecastRun::query()->whereKey($this->runId)->update([
            'status' => ForecastStatus::Failed->value,
            'error_message' => $message,
            'finished_at' => now(),
        ]);
    }
}
