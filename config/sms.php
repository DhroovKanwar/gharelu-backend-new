<?php

return [
    // "log" writes the message to the Laravel log (safe default for local/testing),
    // "fast2sms" or "msg91" send real SMS, "none" turns SMS off.
    'driver' => env('SMS_DRIVER', 'log'),

    // Shop phone(s) that get a "new order" SMS. Comma-separated 10-digit numbers.
    'admin_phones' => array_values(array_filter(array_map('trim', explode(',', (string) env('SMS_ADMIN_PHONES', ''))))),

    'fast2sms' => [
        'api_key' => env('FAST2SMS_API_KEY'),
        // The 6-character sender id approved on DLT.
        'sender_id' => env('FAST2SMS_SENDER_ID'),
        // "Message ID" of each approved template, from Fast2SMS > DLT SMS > DLT Manager.
        'customer_message_id' => env('FAST2SMS_CUSTOMER_MESSAGE_ID'),
        'admin_message_id' => env('FAST2SMS_ADMIN_MESSAGE_ID'),
    ],

    'msg91' => [
        'auth_key' => env('MSG91_AUTH_KEY'),
        // Flow template ids. The templates must be approved on DLT first (see .env.example).
        'customer_template_id' => env('MSG91_CUSTOMER_TEMPLATE_ID'),
        'admin_template_id' => env('MSG91_ADMIN_TEMPLATE_ID'),
    ],
];
