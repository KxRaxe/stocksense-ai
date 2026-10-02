<?php

use App\Enums\ForecastGranularity;
use App\Enums\ForecastStatus;
use App\Jobs\RunForecastJob;
use App\Models\Forecast;
use App\Models\ForecastRun;
use App\Models\Location;
use App\Models\User;
use App\Services\Forecasting\ForecastRunner;
use App\Services\Inventory\LocationContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Contracts;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->owner = User::factory()->owner()->create();

    config(['forecasting.ml.url' => 'http://ml.test:8000', 'forecasting.ml.token' => 'secret']);
});

describe('starting a run', function () {
    beforeEach(fn () => Queue::fake());

    it('queues a run on the ml queue', function () {
        $run = app(ForecastRunner::class)->start(ForecastGranularity::Week, $this->owner);

        expect($run->status)->toBe(ForecastStatus::Queued)
            ->and($run->wasRecentlyCreated)->toBeTrue()
            ->and($run->granularity)->toBe(ForecastGranularity::Week)
            ->and($run->triggered_by)->toBe($this->owner->id);

        Queue::assertPushedOn('ml', RunForecastJob::class, fn ($job) => $job->runId === $run->id);
    });

    it('looks 8 weeks or 3 months ahead unless told otherwise', function () {
        $weekly = app(ForecastRunner::class)->start(ForecastGranularity::Week);
        $monthly = app(ForecastRunner::class)->start(ForecastGranularity::Month);

        expect($weekly->horizon)->toBe(8)
            ->and($monthly->horizon)->toBe(3);

        $weekly->forceFill(['status' => ForecastStatus::Completed])->save();

        expect(app(ForecastRunner::class)->start(ForecastGranularity::Week, horizon: 12)->horizon)->toBe(12);
    });

    it('is for the default location', function () {
        $run = app(ForecastRunner::class)->start(ForecastGranularity::Week);

        expect($run->location_id)->toBe(Location::defaultLocation()->id);
    });

    it('records who started it, or that the schedule did', function () {
        app(ForecastRunner::class)->start(ForecastGranularity::Week, $this->owner);
        app(ForecastRunner::class)->start(ForecastGranularity::Month);

        $entries = Activity::where('event', 'forecast_started')->orderBy('id')->get();

        expect($entries)->toHaveCount(2)
            ->and($entries[0]->causer_id)->toBe($this->owner->id)
            ->and($entries[0]->properties['scheduled'])->toBeFalse()
            ->and($entries[1]->causer_id)->toBeNull()
            ->and($entries[1]->properties['scheduled'])->toBeTrue()
            ->and($entries[0]->log_name)->toBe('forecasts');
    });

    it('refuses a horizon the ML service would refuse', function (ForecastGranularity $granularity, int $horizon) {
        expect(fn () => app(ForecastRunner::class)->start($granularity, null, $horizon))->toThrow(InvalidArgumentException::class);

        expect(ForecastRun::count())->toBe(0);
    })->with([
        'no weeks' => [ForecastGranularity::Week, 0],
        'a long way off, in weeks' => [ForecastGranularity::Week, 27],
        'a long way off, in months' => [ForecastGranularity::Month, 13],
    ]);

    it('accepts the limits', function (ForecastGranularity $granularity, int $horizon) {
        expect(app(ForecastRunner::class)->start($granularity, null, $horizon)->horizon)->toBe($horizon);
    })->with([
        'one week' => [ForecastGranularity::Week, 1],
        'half a year of weeks' => [ForecastGranularity::Week, 26],
        'a year of months' => [ForecastGranularity::Month, 12],
    ]);

    it('can leave the run waiting for the caller to run', function () {
        $run = app(ForecastRunner::class)->start(ForecastGranularity::Week, queue: false);

        expect($run->status)->toBe(ForecastStatus::Queued);
        Queue::assertNothingPushed();
    });
});

describe('only one run at a time', function () {
    beforeEach(fn () => Queue::fake());

    it('hands back the run that is already going instead of starting another', function (ForecastStatus $status) {
        $first = app(ForecastRunner::class)->start(ForecastGranularity::Week);
        $first->forceFill(['status' => $status])->save();

        $second = app(ForecastRunner::class)->start(ForecastGranularity::Week, $this->owner);

        expect($second->id)->toBe($first->id)
            ->and($second->wasRecentlyCreated)->toBeFalse()
            ->and(ForecastRun::count())->toBe(1);

        Queue::assertPushed(RunForecastJob::class, 1);
    })->with([ForecastStatus::Queued, ForecastStatus::Running]);

    it('lets a weekly and a monthly run go at the same time', function () {
        app(ForecastRunner::class)->start(ForecastGranularity::Week);
        app(ForecastRunner::class)->start(ForecastGranularity::Month);

        expect(ForecastRun::count())->toBe(2);
        Queue::assertPushed(RunForecastJob::class, 2);
    });

    it('starts a new run once the last one has finished or failed', function (ForecastStatus $status) {
        $old = app(ForecastRunner::class)->start(ForecastGranularity::Week);
        $old->forceFill(['status' => $status])->save();

        $new = app(ForecastRunner::class)->start(ForecastGranularity::Week);

        expect($new->id)->not->toBe($old->id)
            ->and($new->wasRecentlyCreated)->toBeTrue();
    })->with([ForecastStatus::Completed, ForecastStatus::Failed]);

    it('holds even when two requests arrive at the same instant', function () {
        // The other request's run is already in; this request looked a moment before it landed, so
        // its first look finds nothing and it goes on to insert its own.
        $theirs = ForecastRun::create([
            'granularity' => 'week', 'horizon' => 8, 'status' => 'queued', 'location_id' => Location::defaultLocation()->id,
        ]);

        $runner = new class(app(LocationContext::class)) extends ForecastRunner
        {
            private bool $looked = false;

            protected function running(ForecastGranularity $granularity): ?ForecastRun
            {
                if (! $this->looked) {
                    $this->looked = true;

                    return null;
                }

                return parent::running($granularity);
            }
        };

        $run = $runner->start(ForecastGranularity::Week, $this->owner);

        expect(ForecastRun::count())->toBe(1)
            ->and($run->id)->toBe($theirs->id)
            ->and($run->wasRecentlyCreated)->toBeFalse();

        Queue::assertNothingPushed();
    });

    it('is also enforced by the database', function () {
        $attributes = ['granularity' => 'week', 'horizon' => 8, 'location_id' => Location::defaultLocation()->id];

        ForecastRun::create([...$attributes, 'status' => 'running']);

        // In its own transaction (a savepoint here), as a refused insert would otherwise spoil the test's.
        expect(fn () => DB::transaction(fn () => ForecastRun::create([...$attributes, 'status' => 'queued'])))
            ->toThrow(UniqueConstraintViolationException::class);

        // Finished runs do not count, and nor does another granularity.
        ForecastRun::create([...$attributes, 'status' => 'completed']);
        ForecastRun::create([...$attributes, 'status' => 'failed']);
        ForecastRun::create([...$attributes, 'granularity' => 'month', 'status' => 'queued']);

        expect(ForecastRun::count())->toBe(4);
    });

    it('gives up on a run that has been going for too long, so it cannot block everything', function () {
        $stuck = app(ForecastRunner::class)->start(ForecastGranularity::Week);
        $stuck->forceFill(['status' => ForecastStatus::Running])->save();
        ForecastRun::whereKey($stuck->id)->update(['updated_at' => now()->subHours(2)]);

        $next = app(ForecastRunner::class)->start(ForecastGranularity::Week);

        expect($next->id)->not->toBe($stuck->id)
            ->and($stuck->fresh()->status)->toBe(ForecastStatus::Failed)
            ->and($stuck->fresh()->error_message)->toBe('This run did not finish, so it was stopped.')
            ->and($stuck->fresh()->finished_at)->not->toBeNull();
    });

    it('leaves a run that is only a few minutes old alone', function () {
        $busy = app(ForecastRunner::class)->start(ForecastGranularity::Week);
        $busy->forceFill(['status' => ForecastStatus::Running])->save();
        ForecastRun::whereKey($busy->id)->update(['updated_at' => now()->subMinutes(10)]);

        expect(app(ForecastRunner::class)->start(ForecastGranularity::Week)->id)->toBe($busy->id);
    });
});

describe('the job', function () {
    beforeEach(function () {
        $this->products = productsFromTheExample();
        $this->run = app(ForecastRunner::class)->start(ForecastGranularity::Week, $this->owner, 4, queue: false);
    });

    it('trains, forecasts and stores the result', function () {
        Http::fake(['ml.test:8000/*' => Http::response(exampleAnswer())]);

        RunForecastJob::dispatchSync($this->run->id);
        $run = $this->run->fresh();

        expect($run->status)->toBe(ForecastStatus::Completed)
            ->and($run->model_version)->toBe(exampleAnswer()['model_version'])
            ->and($run->as_of->toDateString())->toBe('2026-09-21')
            ->and($run->started_at)->not->toBeNull()
            ->and($run->finished_at)->not->toBeNull()
            ->and($run->error_message)->toBeNull();
    });

    it('stores the accuracy the ML service measured', function () {
        Http::fake(['ml.test:8000/*' => Http::response(exampleAnswer())]);

        RunForecastJob::dispatchSync($this->run->id);
        $run = $this->run->fresh();
        $answer = exampleAnswer();

        expect($run->metrics)->toEqual($answer['metrics'])
            ->and($run->baseline_metrics)->toEqual($answer['baseline_metrics'])
            ->and($run->per_category_metrics)->toEqual($answer['per_category_metrics'])
            ->and($run->feature_importance)->toEqual($answer['feature_importance'])
            ->and($run->training)->toEqual($answer['training']);
    });

    it('stores each product\'s forecast for each period', function () {
        Http::fake(['ml.test:8000/*' => Http::response(exampleAnswer())]);

        RunForecastJob::dispatchSync($this->run->id);

        $answer = exampleAnswer()['forecasts'];
        $byProduct = collect($answer)->keyBy('product_id');

        // Three products, four weeks each.
        expect(Forecast::where('forecast_run_id', $this->run->id)->count())->toBe(12);

        foreach ([20, 39, 49] as $productId) {
            $rows = Forecast::where('forecast_run_id', $this->run->id)->where('product_id', $productId)->orderBy('period_start')->get();
            $expected = $byProduct[$productId];

            expect($rows)->toHaveCount(4)
                ->and($rows->first()->period_start->toDateString())->toBe($expected['periods'][0]['start'])
                ->and((float) $rows->first()->yhat)->toBe(round($expected['periods'][0]['yhat'], 2))
                ->and((float) $rows->first()->yhat_lower)->toBe(round($expected['periods'][0]['yhat_lower'], 2))
                ->and((float) $rows->first()->yhat_upper)->toBe(round($expected['periods'][0]['yhat_upper'], 2))
                ->and($rows->first()->method->value)->toBe($expected['method'])
                ->and($rows->first()->low_confidence)->toBe($expected['low_confidence']);
        }
    });

    it('flags the products that have under a year of history', function () {
        Http::fake(['ml.test:8000/*' => Http::response(exampleAnswer())]);

        RunForecastJob::dispatchSync($this->run->id);

        expect(Forecast::where('product_id', 20)->first()->low_confidence)->toBeTrue()
            ->and(Forecast::where('product_id', 20)->first()->method->value)->toBe('moving_average')
            ->and(Forecast::where('product_id', 39)->first()->low_confidence)->toBeFalse();
    });

    it('keeps the typical error per product, keyed by product id', function () {
        Http::fake(['ml.test:8000/*' => Http::response(exampleAnswer())]);

        RunForecastJob::dispatchSync($this->run->id);

        $stored = $this->run->fresh()->residual_std;

        expect(array_keys($stored))->toBe([20, 39, 49])
            ->and($stored[39])->toBe(exampleAnswer()['residual_std']['39:1']);
    });

    it('ignores forecasts for products it did not ask about', function () {
        $answer = exampleAnswer();
        $answer['forecasts'][] = [...$answer['forecasts'][0], 'product_id' => 9999, 'series_key' => '9999:1'];
        $answer['residual_std']['9999:1'] = 5.0;
        Http::fake(['ml.test:8000/*' => Http::response($answer)]);

        RunForecastJob::dispatchSync($this->run->id);

        expect(Forecast::where('product_id', 9999)->exists())->toBeFalse()
            ->and(array_keys($this->run->fresh()->residual_std))->toBe([20, 39, 49]);
    });

    it('asks the ML service about the products that have sales, for this run\'s horizon', function () {
        Http::fake(['ml.test:8000/*' => Http::response(exampleAnswer())]);

        RunForecastJob::dispatchSync($this->run->id);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['granularity'] === 'week'
                && $body['horizon'] === 4
                && array_column($body['series'], 'product_id') === [20, 39, 49]
                && $body['options']['backtest_folds'] === config('forecasting.backtest_folds')
                && Contracts::errors('forecast-request', $body) === [];
        });
    });

    it('is marked running while it works', function () {
        Http::fake(function () {
            expect($this->run->fresh()->status)->toBe(ForecastStatus::Running)
                ->and($this->run->fresh()->started_at)->not->toBeNull();

            return Http::response(exampleAnswer());
        });

        RunForecastJob::dispatchSync($this->run->id);
    });

    it('does nothing for a run that is not waiting', function (ForecastStatus $status) {
        Http::fake();
        $this->run->forceFill(['status' => $status])->save();

        RunForecastJob::dispatchSync($this->run->id);

        Http::assertNothingSent();
        expect($this->run->fresh()->status)->toBe($status);
    })->with([ForecastStatus::Running, ForecastStatus::Completed, ForecastStatus::Failed]);

    it('does nothing for a run that no longer exists', function () {
        Http::fake();

        RunForecastJob::dispatchSync(999999);

        Http::assertNothingSent();
    });

    it('does not run twice if the same job is delivered twice', function () {
        Http::fake(['ml.test:8000/*' => Http::response(exampleAnswer())]);

        RunForecastJob::dispatchSync($this->run->id);
        RunForecastJob::dispatchSync($this->run->id);

        Http::assertSentCount(1);
        expect(Forecast::count())->toBe(12);
    });

    it('keeps the forecasts of the latest runs only, and the accuracy of all of them', function () {
        config(['forecasting.keep_runs' => 2]);
        Http::fake(['ml.test:8000/*' => Http::response(exampleAnswer())]);

        $runs = [$this->run];
        RunForecastJob::dispatchSync($this->run->id);

        foreach (range(1, 2) as $_) {
            $next = app(ForecastRunner::class)->start(ForecastGranularity::Week, null, 4, queue: false);
            RunForecastJob::dispatchSync($next->id);
            $runs[] = $next;
        }

        // Three completed runs; only the two newest keep their forecast rows.
        expect(Forecast::where('forecast_run_id', $runs[0]->id)->count())->toBe(0)
            ->and(Forecast::where('forecast_run_id', $runs[1]->id)->count())->toBe(12)
            ->and(Forecast::where('forecast_run_id', $runs[2]->id)->count())->toBe(12)
            ->and($runs[0]->fresh()->status)->toBe(ForecastStatus::Completed)
            ->and($runs[0]->fresh()->metrics)->not->toBeNull();
    });

    it('prunes one granularity without touching the other', function () {
        config(['forecasting.keep_runs' => 1]);
        $this->run->forceFill(['status' => ForecastStatus::Failed])->save();
        $olderWeekly = completedRun();
        $monthly = completedRun(['granularity' => 'month', 'horizon' => 3]);
        forecastFor($olderWeekly, $this->products[0], [['2026-09-28', 5, 3, 8]]);
        forecastFor($monthly, $this->products[0], [['2026-10-01', 5, 3, 8]]);

        $newer = app(ForecastRunner::class)->start(ForecastGranularity::Week, null, 4, queue: false);
        Http::fake(['ml.test:8000/*' => Http::response(exampleAnswer())]);
        RunForecastJob::dispatchSync($newer->id);

        // The older weekly run lost its rows to the new one; the monthly run is another matter.
        expect(Forecast::where('forecast_run_id', $olderWeekly->id)->count())->toBe(0)
            ->and(Forecast::where('forecast_run_id', $monthly->id)->count())->toBe(1)
            ->and(Forecast::where('forecast_run_id', $newer->id)->count())->toBe(12);
    });
});

describe('when a run cannot finish', function () {
    beforeEach(function () {
        $this->run = app(ForecastRunner::class)->start(ForecastGranularity::Week, $this->owner, 4, queue: false);
    });

    it('fails, saying so, when there is no sales history yet', function () {
        Http::fake();

        RunForecastJob::dispatchSync($this->run->id);
        $run = $this->run->fresh();

        expect($run->status)->toBe(ForecastStatus::Failed)
            ->and($run->error_message)->toBe('There is no sales history to forecast from yet. Record or import some sales first.')
            ->and($run->finished_at)->not->toBeNull();

        Http::assertNothingSent();
    });

    it('fails, saying so, when the ML service cannot be reached', function () {
        productsFromTheExample();
        Http::fake(fn () => throw new ConnectionException('cURL error 7'));

        RunForecastJob::dispatchSync($this->run->id);

        expect($this->run->fresh()->status)->toBe(ForecastStatus::Failed)
            ->and($this->run->fresh()->error_message)->toContain('could not be reached')
            ->and(Forecast::count())->toBe(0);
    });

    it('fails, saying so, when the ML service refuses the credentials', function () {
        productsFromTheExample();
        Http::fake(['ml.test:8000/*' => Http::response(['detail' => 'Missing or invalid internal token.'], 401)]);

        RunForecastJob::dispatchSync($this->run->id);

        expect($this->run->fresh()->error_message)->toContain('ML_INTERNAL_TOKEN');
    });

    it('stores nothing from a run that failed', function () {
        productsFromTheExample();
        Http::fake(['ml.test:8000/*' => Http::response('boom', 500)]);

        RunForecastJob::dispatchSync($this->run->id);
        $run = $this->run->fresh();

        expect($run->status)->toBe(ForecastStatus::Failed)
            ->and($run->metrics)->toBeNull()
            ->and($run->model_version)->toBeNull()
            ->and(Forecast::count())->toBe(0);
    });

    it('does not let a failed run replace the forecast people are using', function () {
        productsFromTheExample();
        $good = completedRun();
        Http::fake(['ml.test:8000/*' => Http::response('boom', 500)]);

        RunForecastJob::dispatchSync($this->run->id);

        expect(ForecastRun::latestCompleted(ForecastGranularity::Week)->id)->toBe($good->id);
    });

    it('marks a run failed, with a general message, if something unexpected goes wrong', function () {
        (new RunForecastJob($this->run->id))->failed(new RuntimeException('SQLSTATE[HY000]: something internal'));
        $run = $this->run->fresh();

        expect($run->status)->toBe(ForecastStatus::Failed)
            ->and($run->error_message)->toBe('The forecast stopped because of an unexpected error. Try again; if it keeps happening, check the logs.')
            ->and($run->error_message)->not->toContain('SQLSTATE');
    });

    it('can be run again after a failure', function () {
        productsFromTheExample();
        Http::fake(['ml.test:8000/*' => Http::sequence()->push('boom', 500)->push(exampleAnswer())]);

        RunForecastJob::dispatchSync($this->run->id);
        $again = app(ForecastRunner::class)->start(ForecastGranularity::Week, $this->owner, 4, queue: false);
        RunForecastJob::dispatchSync($again->id);

        expect($this->run->fresh()->status)->toBe(ForecastStatus::Failed)
            ->and($again->fresh()->status)->toBe(ForecastStatus::Completed);
    });
});

describe('the forecast:run command', function () {
    it('queues a run', function () {
        Queue::fake();

        $this->artisan('forecast:run week')->assertSuccessful();

        Queue::assertPushedOn('ml', RunForecastJob::class);
        expect(ForecastRun::first())->granularity->toBe(ForecastGranularity::Week)
            ->and(ForecastRun::first()->triggered_by)->toBeNull();
    });

    it('queues both, if no granularity is given', function () {
        Queue::fake();

        $this->artisan('forecast:run')->assertSuccessful();

        Queue::assertPushed(RunForecastJob::class, 2);
        expect(ForecastRun::pluck('granularity')->map->value->sort()->values()->all())->toBe(['month', 'week']);
    });

    it('takes a horizon', function () {
        Queue::fake();

        $this->artisan('forecast:run week --horizon=5')->assertSuccessful();

        expect(ForecastRun::first()->horizon)->toBe(5);
    });

    it('turns down a granularity it does not know', function () {
        Queue::fake();

        $this->artisan('forecast:run day')->assertFailed();

        Queue::assertNothingPushed();
    });

    it('says so, and starts nothing, when a run is already going', function () {
        Queue::fake();
        app(ForecastRunner::class)->start(ForecastGranularity::Week);

        $this->artisan('forecast:run week')->expectsOutputToContain('already running')->assertSuccessful();

        Queue::assertPushed(RunForecastJob::class, 1);
    });

    it('can run it right now, in the same process', function () {
        productsFromTheExample();
        Http::fake(['ml.test:8000/*' => Http::response(exampleAnswer())]);

        $this->artisan('forecast:run week --sync --horizon=4')->expectsOutputToContain('completed')->assertSuccessful();

        expect(ForecastRun::first()->status)->toBe(ForecastStatus::Completed)
            ->and(Forecast::count())->toBe(12);
    });

    it('fails, saying why, when a run that was asked to run now cannot', function () {
        Http::fake();

        $this->artisan('forecast:run week --sync')->expectsOutputToContain('no sales history')->assertFailed();
    });
});

describe('the schedule', function () {
    it('refreshes weekly forecasts early on Monday and monthly ones on the 1st', function () {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command, 'forecast:run'))
            ->mapWithKeys(fn ($event) => [trim(explode('forecast:run', $event->command)[1]) => $event->expression]);

        expect($events->all())->toBe(['week' => '0 2 * * 1', 'month' => '0 3 1 * *']);
    });

    it('runs in the shop\'s time zone', function () {
        expect(config('app.timezone'))->toBe('Asia/Manila');
    });
});
