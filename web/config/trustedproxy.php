<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | When a reverse proxy or load balancer sits in front of the application and ends HTTPS, it
    | tells the application the original request's address and scheme in headers. Those headers
    | are believed only when the request comes from one of these addresses, so a visitor cannot
    | claim to be someone else. Set TRUSTED_PROXIES to the proxy's address(es), comma separated,
    | or to "*" to trust any (only when the application can be reached through the proxy alone).
    | Leave it empty when nothing is in front.
    |
    */

    'proxies' => ($proxies = env('TRUSTED_PROXIES'))
        ? ($proxies === '*' ? '*' : array_map('trim', explode(',', (string) $proxies)))
        : null,

];
