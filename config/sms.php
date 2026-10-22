<?php

return [
    // "log" writes the message to the Laravel log (safe default for local/testing),
    // "msg91" sends real SMS through MSG91's Flow API, "none" turns SMS off.
    'driver' => env('SMS_DRIVER', 'log'),

    // Shop phone(s) that get a "new order" SMS. Comma-separated 10-digit numbers.
    'admin_phones' => array_values(array_filter(array_map('trim', explode(',', (string) env('SMS_ADMIN_PHONES', ''))))),

    'msg91' => [
        'auth_key' => env('MSG91_AUTH_KEY'),
        // Flow template ids. The templates must be approved on DLT first (see .env.example).
        'customer_template_id' => env('MSG91_CUSTOMER_TEMPLATE_ID'),
        'admin_template_id' => env('MSG91_ADMIN_TEMPLATE_ID'),
    ],
];
