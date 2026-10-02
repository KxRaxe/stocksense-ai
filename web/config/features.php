<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Multiple locations (branches)
    |--------------------------------------------------------------------------
    |
    | The data model is ready for several locations (stock is kept per product
    | per location), but the application manages exactly one. This flag is the
    | switch for the location picker and location management screens once they
    | are built; turning it on today changes nothing. See
    | docs/future-multi-branch.md for what remains.
    |
    */

    'multi_location' => (bool) env('FEATURE_MULTI_LOCATION', false),

];
