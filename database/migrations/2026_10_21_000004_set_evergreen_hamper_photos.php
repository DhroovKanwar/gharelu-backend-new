<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Real photos for the Evergreen Hamper (slug `luxe-hamper`; files live in the
 * storefront's public/images/hampers). Replaces only that product's images.
 */
return new class extends Migration
{
    public function up(): void
    {
        $product = DB::table('products')->where('slug', 'luxe-hamper')->first();
        if (! $product) {
            return;
        }

        DB::transaction(function () use ($product) {
            DB::table('product_images')->where('product_id', $product->id)->delete();

            foreach (range(1, 6) as $n) {
                DB::table('product_images')->insert([
                    'product_id' => $product->id,
                    'path' => "/images/hampers/evergreen-{$n}.jpg",
                    'alt' => $product->name,
                    'sort_order' => $n - 1,
                    'is_primary' => $n === 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Intentionally empty: the previous placeholder URLs are not restored.
    }
};
