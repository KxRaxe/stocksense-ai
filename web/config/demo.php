<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo data
    |--------------------------------------------------------------------------
    |
    | Folder holding the CSV files the demo seeder loads (categories, products,
    | restocks and sales). They are written by ml/scripts/generate_synthetic.py;
    | tests point this at a small set of their own.
    |
    */

    'data_path' => env('DEMO_DATA_PATH', database_path('data')),

    /*
    |--------------------------------------------------------------------------
    | Demo data in production
    |--------------------------------------------------------------------------
    |
    | The demo seeders create accounts with a password everyone knows, so they refuse
    | to run in production. A throwaway test stack (the end-to-end tests) can allow it
    | for one command with ALLOW_DEMO_DATA=true. `php artisan app:check` fails if this
    | is left on in a running production setup.
    |
    */

    'allowed' => (bool) env('ALLOW_DEMO_DATA', false),

];
