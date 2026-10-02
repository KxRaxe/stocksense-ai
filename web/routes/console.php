<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fresh forecasts: weekly ones early on Monday (the new week's numbers are in the
// shop's hands before opening), monthly ones on the 1st. Times are in the app's
// time zone (Asia/Manila). A run that is already going is never started twice.
if (config('forecasting.schedule.enabled')) {
    Schedule::command('forecast:run week')
        ->weeklyOn((int) config('forecasting.schedule.weekly.day'), (string) config('forecasting.schedule.weekly.time'))
        ->onOneServer();

    Schedule::command('forecast:run month')
        ->monthlyOn(max(1, min(31, (int) config('forecasting.schedule.monthly.day'))), (string) config('forecasting.schedule.monthly.time'))
        ->onOneServer();
}
