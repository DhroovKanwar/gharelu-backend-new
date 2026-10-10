<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'shiprocket' => [
        'email' => env('SHIPROCKET_EMAIL'),
        'password' => env('SHIPROCKET_PASSWORD'),
        'pickup_pincode' => env('SHIPROCKET_PICKUP_PINCODE'),
        // No per-product weight in the catalog yet, so each unit is assumed
        // to ship at this weight (kg). Packaging weight is added once.
        'default_item_weight_kg' => (float) env('SHIPROCKET_ITEM_WEIGHT_KG', 0.5),
        'packaging_weight_kg' => (float) env('SHIPROCKET_PACKAGING_WEIGHT_KG', 0.3),
        // Used when Shiprocket is unreachable or has no courier quote.
        'fallback_fee' => (float) env('SHIPROCKET_FALLBACK_FEE', 99),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
