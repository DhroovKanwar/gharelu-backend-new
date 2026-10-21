<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Real photos for the Classic, Signature and Luxe hampers (files live in the
 * storefront's public/images/hampers). Replaces only those products' images.
 */
return new class extends Migration
{
    private const PHOTOS = [
        'classic-hamper-pro' => ['classic', 6],
        'signature-hamper-pro' => ['signature', 7],
        'luxe-hamper-pro' => ['luxe', 4],
    ];

    public function up(): void
    {
        DB::transaction(function () {
            foreach (self::PHOTOS as $slug => [$prefix, $count]) {
                $product = DB::table('products')->where('slug', $slug)->first();
                if (! $product) {
                    continue;
                }

                DB::table('product_images')->where('product_id', $product->id)->delete();

                foreach (range(1, $count) as $n) {
                    DB::table('product_images')->insert([
                        'product_id' => $product->id,
                        'path' => "/images/hampers/{$prefix}-{$n}.jpg",
                        'alt' => $product->name,
                        'sort_order' => $n - 1,
                        'is_primary' => $n === 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Intentionally empty: the previous placeholder URLs are not restored.
    }
};
