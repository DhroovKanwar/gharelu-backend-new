<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // Set FRONTEND_URL in .env, e.g. https://gharelubake.com or
    // http://localhost:3000 for local dev. No wildcard in production.
    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:3000')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept', 'Authorization', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Bearer-token auth (Sanctum personal access tokens), not cookies —
    // no credentials/cookies need to cross the CORS boundary.
    'supports_credentials' => false,
];
