<?php

return [
    // Public — safe to send to the frontend (needed to open Razorpay Checkout).
    'key_id' => env('RAZORPAY_KEY_ID'),

    // Secret — used only server-side to create orders and verify payment
    // signatures. Never include in any API response.
    'key_secret' => env('RAZORPAY_KEY_SECRET'),

    // Secret — used only server-side to verify webhook signatures. Never
    // include in any API response.
    'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
];
