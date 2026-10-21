<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // FRONTEND_URL (storefront) and ADMIN_URL (admin panel) each take one origin or a
    // comma-separated list, e.g. FRONTEND_URL=https://gharelubake.in,https://www.gharelubake.in
    // and ADMIN_URL=https://admin.gharelubake.in. No wildcard in production.
    'allowed_origins' => array_values(array_unique(array_filter(array_map('trim', array_merge(
        explode(',', (string) env('FRONTEND_URL', 'http://localhost:3000')),
        explode(',', (string) env('ADMIN_URL', '')),
    ))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept', 'Authorization', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Bearer-token auth (Sanctum personal access tokens), not cookies —
    // no credentials/cookies need to cross the CORS boundary.
    'supports_credentials' => false,
];
