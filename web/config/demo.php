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

];
