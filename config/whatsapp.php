<?php

return [

    // Order confirmations to the customer on WhatsApp (Meta WhatsApp Cloud API, no DLT).
    // Off until both the token and the phone number id are set.
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),

    // Template approved in WhatsApp Manager. Body variables, in order:
    // {{1}} first name, {{2}} order number, {{3}} total in rupees, {{4}} date.
    'customer_template' => env('WHATSAPP_CUSTOMER_TEMPLATE', 'order_confirmation'),
    'template_language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'en'),

];
