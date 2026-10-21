<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // Set FRONTEND_URL (storefront) and ADMIN_URL (admin panel, when it is served
    // from its own origin) in .env, e.g. https://gharelubake.in and
    // https://admin.gharelubake.in. No wildcard in production.
    'allowed_origins' => array_values(array_filter([
        env('FRONTEND_URL', 'http://localhost:3000'),
        env('ADMIN_URL'),
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept', 'Authorization', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Bearer-token auth (Sanctum personal access tokens), not cookies —
    // no credentials/cookies need to cross the CORS boundary.
    'supports_credentials' => false,
];
