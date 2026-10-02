<?php

use App\Enums\ForecastGranularity;
use App\Models\User;
use App\Services\Settings\Settings;
use App\Services\Settings\SettingsSchema;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Cache::forget(Settings::CACHE_KEY);

    $this->owner = User::factory()->owner()->create();
    $this->settings = app(Settings::class);
});

describe('the schema', function () {
    it('names a config value that exists for every setting', function () {
        $missing = collect(SettingsSchema::definitions())
            ->reject(fn ($definition) => config()->has($definition->config))
            ->keys()
            ->all();

        expect($missing)->toBe([]);
    });

    it('has defaults that its own rules accept', function () {
        $failing = [];

        foreach (SettingsSchema::definitions() as $key => $definition) {
            $validator = Validator::make(['value' => $this->settings->defaults()[$key]], ['value' => $definition->rules()]);

            if ($validator->fails()) {
                $failing[$key] = $validator->errors()->all();
            }
        }

        expect($failing)->toBe([]);
    });

    it('uses no dots in keys, which would read as nesting in validation', function () {
        expect(array_filter(array_keys(SettingsSchema::definitions()), fn ($key) => str_contains($key, '.')))->toBe([]);
    });

    it('explains every setting', function () {
        foreach (SettingsSchema::definitions() as $definition) {
            expect($definition->label)->not->toBe('')->and($definition->help)->not->toBe('');
        }
    });
});

describe('defaults', function () {
    it('are what the config files say', function () {
        expect($this->settings->all())->toBe($this->settings->defaults())
            ->and($this->settings->all()['review_days'])->toBe(7)
            ->and($this->settings->all()['overstock_days'])->toBe(90)
            ->and($this->settings->all()['default_service_level'])->toBe(95.0)
            ->and($this->settings->all()['horizon_week'])->toBe(8)
            ->and($this->settings->all()['horizon_month'])->toBe(3)
            ->and($this->settings->all()['advice_time'])->toBe('06:00')
            ->and($this->settings->all()['digest_time'])->toBe('07:00')
            ->and($this->settings->hasChanges())->toBeFalse();
    });
});

describe('changing a setting', function () {
    it('stores it, reports it, and puts it on the config at once', function () {
        $changes = $this->settings->update(['review_days' => 14, 'overstock_days' => 60], $this->owner);

        expect($changes)->toBe([
            'review_days' => ['old' => 7, 'new' => 14],
            'overstock_days' => ['old' => 90, 'new' => 60],
        ])
            ->and($this->settings->all()['review_days'])->toBe(14)
            ->and(config('replenishment.review_days'))->toBe(14)
            ->and(config('replenishment.overstock_days'))->toBe(60)
            ->and($this->settings->hasChanges())->toBeTrue();
    });

    it('stores only what differs from the default', function () {
        $this->settings->update(['review_days' => 14, 'overstock_days' => 90, 'advice_time' => '06:00'], $this->owner);

        expect(DB::table('settings')->pluck('key')->all())->toBe(['review_days']);
    });

    it('stores who changed it', function () {
        $this->settings->update(['review_days' => 14], $this->owner);

        expect(DB::table('settings')->where('key', 'review_days')->value('updated_by'))->toBe($this->owner->id);
    });

    it('treats setting a value back to the default as removing the change', function () {
        $this->settings->update(['review_days' => 14], $this->owner);
        $this->settings->update(['review_days' => 7], $this->owner);

        expect(DB::table('settings')->count())->toBe(0)
            ->and(config('replenishment.review_days'))->toBe(7)
            ->and($this->settings->hasChanges())->toBeFalse();
    });

    it('changes every kind of value', function () {
        $this->settings->update([
            'default_service_level' => 97.5,
            'forecast_schedule' => false,
            'forecast_weekly_day' => 0,
            'forecast_weekly_time' => '04:30',
            'horizon_week' => 12,
        ], $this->owner);

        expect(config('replenishment.default_service_level'))->toBe(97.5)
            ->and(config('forecasting.schedule.enabled'))->toBeFalse()
            ->and(config('forecasting.schedule.weekly.day'))->toBe(0)
            ->and(config('forecasting.schedule.weekly.time'))->toBe('04:30')
            ->and(config('forecasting.horizons.week'))->toBe(12)
            ->and(ForecastGranularity::Week->defaultHorizon())->toBe(12);
    });

    it('leaves settings that were not mentioned alone', function () {
        $this->settings->update(['review_days' => 14], $this->owner);
        $this->settings->update(['overstock_days' => 60], $this->owner);

        expect($this->settings->all()['review_days'])->toBe(14)
            ->and($this->settings->all()['overstock_days'])->toBe(60);
    });

    it('says nothing changed when nothing did', function () {
        expect($this->settings->update(['review_days' => 7], $this->owner))->toBe([]);

        expect(Activity::where('log_name', 'settings')->count())->toBe(0);
    });

    it('goes in the audit log, with the old and new values', function () {
        $this->settings->update(['review_days' => 14], $this->owner);

        $entry = Activity::where('log_name', 'settings')->sole();

        expect($entry->description)->toBe('Changed the system settings')
            ->and($entry->causer_id)->toBe($this->owner->id)
            ->and($entry->properties['changes'])->toBe(['review_days' => ['old' => 7, 'new' => 14]]);
    });
});

describe('restoring the defaults', function () {
    it('removes every change and puts the config back', function () {
        $this->settings->update(['review_days' => 14, 'forecast_schedule' => false, 'digest_time' => '09:00'], $this->owner);

        $this->settings->reset($this->owner);

        expect(DB::table('settings')->count())->toBe(0)
            ->and(config('replenishment.review_days'))->toBe(7)
            ->and(config('forecasting.schedule.enabled'))->toBeTrue()
            ->and(config('replenishment.schedule.digest'))->toBe('07:00')
            ->and($this->settings->hasChanges())->toBeFalse();
    });

    it('goes in the audit log', function () {
        $this->settings->update(['review_days' => 14], $this->owner);
        $this->settings->reset($this->owner);

        $entry = Activity::where('log_name', 'settings')->latest('id')->first();

        expect($entry->description)->toBe('Restored the default system settings')
            ->and($entry->properties['changes'])->toBe(['review_days' => ['old' => 14, 'new' => 7]]);
    });

    it('leaves no trace when there was nothing to restore', function () {
        $this->settings->reset($this->owner);

        expect(Activity::where('log_name', 'settings')->count())->toBe(0);
    });

    it('does not undo what a test or deployment set on the config by other means', function () {
        config(['replenishment.review_days' => 21]);

        $this->settings->apply();
        $this->settings->update(['overstock_days' => 60], $this->owner);
        $this->settings->reset($this->owner);

        expect(config('replenishment.review_days'))->toBe(21);
    });
});

describe('being picked up', function () {
    it('applies what is stored when the application starts', function () {
        DB::table('settings')->insert(['key' => 'review_days', 'value' => json_encode(21), 'created_at' => now(), 'updated_at' => now()]);
        Cache::forget(Settings::CACHE_KEY);

        $fresh = new Settings;
        $fresh->apply();

        expect(config('replenishment.review_days'))->toBe(21)
            ->and($fresh->all()['review_days'])->toBe(21)
            ->and($fresh->defaults()['review_days'])->toBe(7);
    });

    it('applies a change made elsewhere before the next queued job, without a restart', function () {
        $this->settings->apply();
        expect(config('replenishment.review_days'))->toBe(7);

        // Another process (the web app) saves a change and clears the shared cache.
        DB::table('settings')->insert(['key' => 'review_days', 'value' => json_encode(30), 'created_at' => now(), 'updated_at' => now()]);
        Cache::forget(Settings::CACHE_KEY);

        Event::dispatch(new JobProcessing('redis', Mockery::mock(Job::class, ['payload' => []])));

        expect(config('replenishment.review_days'))->toBe(30);
    });

    it('goes back to the default in a worker when the change is removed elsewhere', function () {
        DB::table('settings')->insert(['key' => 'review_days', 'value' => json_encode(30), 'created_at' => now(), 'updated_at' => now()]);
        Cache::forget(Settings::CACHE_KEY);
        $this->settings->apply();
        expect(config('replenishment.review_days'))->toBe(30);

        DB::table('settings')->delete();
        Cache::forget(Settings::CACHE_KEY);
        $this->settings->apply();

        expect(config('replenishment.review_days'))->toBe(7);
    });

    it('reads the database once, then the cache', function () {
        $this->settings->all();

        DB::enableQueryLog();
        $this->settings->all();
        $this->settings->hasChanges();

        expect(DB::getQueryLog())->toBe([]);
    });

    it('starts quietly when the settings table is not there yet', function () {
        Cache::forget(Settings::CACHE_KEY);
        Schema::drop('settings');

        $fresh = new Settings;
        $fresh->apply();

        expect(config('replenishment.review_days'))->toBe(7);
    });
});
