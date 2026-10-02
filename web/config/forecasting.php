<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The ML service
    |--------------------------------------------------------------------------
    |
    | The forecasting service is internal: only this app calls it, presenting a
    | shared secret. A forecast run trains a model, so the timeout is generous;
    | the queue worker's own limit (config/horizon.php, supervisor-ml) must be
    | longer still.
    |
    */

    'ml' => [
        'url' => env('ML_SERVICE_URL', 'http://ml:8000'),
        'token' => env('ML_INTERNAL_TOKEN', ''),
        'timeout' => (int) env('ML_TIMEOUT', 600),
        'connect_timeout' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | What gets forecast
    |--------------------------------------------------------------------------
    |
    | Weekly forecasts look 8 weeks ahead and monthly ones 3 months ahead, as in
    | the proposal. `history` is how many past periods the product chart shows.
    |
    */

    'horizons' => [
        'week' => 8,
        'month' => 3,
    ],

    'history' => [
        'week' => 52,
        'month' => 24,
    ],

    // Rolling test windows used to measure accuracy on every run.
    'backtest_folds' => (int) env('FORECAST_BACKTEST_FOLDS', 3),

    // A run still "running" after this long is taken to have died with its worker.
    'stale_after_minutes' => 60,

    // Forecast rows are kept for this many of the latest completed runs per
    // granularity; older runs keep their accuracy figures but not the forecasts.
    'keep_runs' => 5,

    /*
    |--------------------------------------------------------------------------
    | Scheduled runs
    |--------------------------------------------------------------------------
    |
    | Weekly forecasts are refreshed early on Monday, monthly ones on the 1st,
    | in the app's time zone (Asia/Manila).
    |
    */

    'schedule' => [
        'enabled' => (bool) env('FORECAST_SCHEDULE', true),
        'weekly' => ['day' => 1, 'time' => '02:00'],
        'monthly' => ['day' => 1, 'time' => '03:00'],
    ],

];
