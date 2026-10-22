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
        // Sent to Shiprocket when the customer left the email blank.
        'fallback_email' => env('SHIPROCKET_FALLBACK_EMAIL', 'orders@gharelubake.in'),
        'pickup_pincode' => env('SHIPROCKET_PICKUP_PINCODE'),
        // No per-product weight in the catalog yet, so each unit is assumed
        // to ship at this weight (kg). Packaging weight is added once.
        'default_item_weight_kg' => (float) env('SHIPROCKET_ITEM_WEIGHT_KG', 0.5),
        // Name of the pickup address in Shiprocket (Settings > Pickup Addresses).
        'pickup_location' => env('SHIPROCKET_PICKUP_LOCATION', 'Primary'),
        // Parcel size (cm) sent with each order. Adjust to the real hamper box.
        'parcel_length_cm' => (int) env('SHIPROCKET_PARCEL_LENGTH_CM', 25),
        'parcel_breadth_cm' => (int) env('SHIPROCKET_PARCEL_BREADTH_CM', 20),
        'parcel_height_cm' => (int) env('SHIPROCKET_PARCEL_HEIGHT_CM', 12),
        'packaging_weight_kg' => (float) env('SHIPROCKET_PACKAGING_WEIGHT_KG', 0.3),
    ],

    // Own-rider delivery for nearby pincodes: a flat fee instead of a courier
    // quote. Comma-separated 6-digit pincodes, or prefixes ending in * (e.g. 1406*).
    'local_delivery' => [
        'pincodes' => array_filter(array_map('trim', explode(',', (string) env('LOCAL_DELIVERY_PINCODES', '')))),
        'fee' => (float) env('LOCAL_DELIVERY_FEE', 49),
        'days' => (int) env('LOCAL_DELIVERY_DAYS', 1),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
