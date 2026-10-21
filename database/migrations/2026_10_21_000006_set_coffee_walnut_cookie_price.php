<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Coffee Walnut Cookies are now priced at 60. Existing orders keep the price
 * they were placed at (order_items store their own unit price).
 */
return new class extends Migration
{
    public function up(): void
    {
        $id = DB::table('products')->where('slug', 'cookies-coffee-walnut-cookies')->value('id');
        if (! $id) {
            return;
        }

        DB::table('products')->where('id', $id)->update(['base_price' => 60]);
        DB::table('product_sizes')->where('product_id', $id)->update(['price' => 60]);
    }

    public function down(): void
    {
        $id = DB::table('products')->where('slug', 'cookies-coffee-walnut-cookies')->value('id');
        if ($id) {
            DB::table('products')->where('id', $id)->update(['base_price' => 50]);
            DB::table('product_sizes')->where('product_id', $id)->update(['price' => 50]);
        }
    }
};
