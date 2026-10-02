<?php

return [

    /*
    |--------------------------------------------------------------------------
    | How recommendations are worked out
    |--------------------------------------------------------------------------
    |
    | Stock is reviewed every `review_days` (the gap between orders you would
    | normally place); safety stock covers the lead time plus that gap. A
    | product with more than `overstock_days` of demand on hand is flagged as
    | overstocked. Both will move to the settings page; until then they live here.
    |
    */

    'review_days' => (int) env('REPLENISHMENT_REVIEW_DAYS', 7),

    // The service level (%) given to a new category, such as one created by a product import.
    'default_service_level' => (float) env('DEFAULT_SERVICE_LEVEL', 95),

    'overstock_days' => (int) env('REPLENISHMENT_OVERSTOCK_DAYS', 90),

    // How far ahead to look for the day stock is expected to reach the reorder point.
    'projection_days' => 180,

    // Dismissing a recommendation silences that product for this many days.
    'snooze_days' => (int) env('REPLENISHMENT_SNOOZE_DAYS', 7),

    // A forecast older than this is flagged as stale on the recommendations page.
    'stale_forecast_days' => 14,

    /*
    |--------------------------------------------------------------------------
    | Schedule
    |--------------------------------------------------------------------------
    |
    | Recommendations are refreshed every morning, and after each forecast run,
    | because stock changes daily even when the forecast does not. The digest goes
    | out after that, in the app's time zone (Asia/Manila).
    |
    */

    'schedule' => [
        'enabled' => (bool) env('REPLENISHMENT_SCHEDULE', true),
        'generate' => '06:00',
        'digest' => '07:00',
    ],

];
