<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The storefront shows fixed hamper prices, but the database held different ones, and
 * checkout/Razorpay charge the database price. Sets the database to the prices customers see.
 */
return new class extends Migration
{
    private const PRICES = [
        'classic-hamper-pro' => 1290,
        'signature-hamper-pro' => 1690,
        'luxe-hamper' => 1390,      // Evergreen
        'luxe-hamper-pro' => 2290,  // Luxe
    ];

    public function up(): void
    {
        foreach (self::PRICES as $slug => $price) {
            DB::table('products')->where('slug', $slug)->update([
                'base_price' => $price,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally empty: the previous prices are not restored.
    }
};
