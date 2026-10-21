<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration: replace the stock-photo placeholders of the products that
 * now have real photos (files live in the storefront's public/images/products).
 * Only the product_images rows of these slugs change; idempotent.
 */
return new class extends Migration
{
    private const PHOTOS = [
        'cake-black-forest' => ['cake-black-forest-1', 'cake-black-forest-2', 'cake-black-forest-3'],
        'cake-red-velvet' => ['cake-red-velvet-1', 'cake-red-velvet-2', 'cake-red-velvet-3'],
        'dry-cakes-fudgy-brownie' => ['dry-cakes-fudgy-brownie-1'],
        'savoury-avocado-toast' => ['savoury-avocado-toast-1', 'savoury-avocado-toast-2', 'savoury-avocado-toast-3'],
    ];

    public function up(): void
    {
        DB::transaction(function () {
            foreach (self::PHOTOS as $slug => $files) {
                $product = DB::table('products')->where('slug', $slug)->first();
                if (! $product) {
                    continue;
                }

                DB::table('product_images')->where('product_id', $product->id)->delete();

                foreach ($files as $order => $file) {
                    DB::table('product_images')->insert([
                        'product_id' => $product->id,
                        'path' => "/images/products/{$file}.jpg",
                        'alt' => $product->name,
                        'sort_order' => $order,
                        'is_primary' => $order === 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Original placeholder URLs are not restored; re-seed data/products.json history if needed.
    }
};
