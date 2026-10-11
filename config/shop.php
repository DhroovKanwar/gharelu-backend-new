<?php

/*
|--------------------------------------------------------------------------
| Catalogue limits
|--------------------------------------------------------------------------
|
| Business limits enforced by the admin validation rules. The price columns
| are DECIMAL(10,2) (max 99,999,999.99), so the business maximum sits far
| below the database limit and an oversized value is rejected with a 422
| instead of causing a database overflow (HTTP 500).
|
*/

return [
    // Prices are in INR with at most 2 decimals. Applies to base_price,
    // old_price and size prices. Highest current price is below Rs 4,000.
    'min_price' => 0.01,
    'max_price' => (float) env('SHOP_MAX_PRICE', 100000),

    // Net product weight in grams (used for the courier rate). 1 g to 50 kg.
    'min_weight_grams' => 1,
    'max_weight_grams' => (int) env('SHOP_MAX_WEIGHT_GRAMS', 50000),
];
