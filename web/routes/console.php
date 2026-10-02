<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reorder advice is refreshed every morning, because stock changes daily even when the
// forecast does not (and again after every forecast run), then the digest goes out.
// Times are in the app's time zone (Asia/Manila).
if (config('replenishment.schedule.enabled')) {
    Schedule::command('recommendations:generate')
        ->dailyAt((string) config('replenishment.schedule.generate'))
        ->onOneServer();

    Schedule::command('notifications:digest')
        ->dailyAt((string) config('replenishment.schedule.digest'))
        ->onOneServer();
}

// Fresh forecasts: weekly ones early on Monday (the new week's numbers are in the
// shop's hands before opening), monthly ones on the 1st. A run that is already going
// is never started twice.
if (config('forecasting.schedule.enabled')) {
    Schedule::command('forecast:run week')
        ->weeklyOn((int) config('forecasting.schedule.weekly.day'), (string) config('forecasting.schedule.weekly.time'))
        ->onOneServer();

    Schedule::command('forecast:run month')
        ->monthlyOn(max(1, min(31, (int) config('forecasting.schedule.monthly.day'))), (string) config('forecasting.schedule.monthly.time'))
        ->onOneServer();
}
