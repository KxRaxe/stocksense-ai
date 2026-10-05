<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Browser protections
    |--------------------------------------------------------------------------
    |
    | The Content-Security-Policy and Strict-Transport-Security headers (see
    | App\Http\Middleware\SecurityHeaders). Both default to on in production and off
    | elsewhere: the policy would block the Vite dev server, and HSTS on localhost
    | would pin a developer's browser to HTTPS.
    |
    */

    'csp' => (bool) env('SECURITY_CSP', env('APP_ENV') === 'production'),

    'hsts' => (bool) env('SECURITY_HSTS', env('APP_ENV') === 'production'),

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    |
    | Requests a signed-in person may make per minute to the actions that cost
    | something: downloading a report, starting a forecast, recalculating advice,
    | uploading an import file, sending a set-up email. Signing in is limited
    | separately by Fortify (five tries a minute for each email address and IP).
    |
    */

    'limits' => [
        'exports' => (int) env('LIMIT_EXPORTS', 10),
        'heavy' => (int) env('LIMIT_HEAVY', 6),
    ],

];
