<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration: real photos for Coffee Walnut Cookies and Gharelu Special
 * Sandwich, and the new "2 in 1 Cake" (half vanilla, half chocolate, 500 gm).
 * Idempotent: an existing 2-in-1 product is left as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            foreach (['cookies-coffee-walnut-cookies', 'savoury-gharelu-special-sandwich'] as $slug) {
                if ($product = DB::table('products')->where('slug', $slug)->first()) {
                    $this->setPhotos($product, [$slug.'-1']);
                }
            }

            if (DB::table('products')->where('slug', 'cake-2-in-1')->exists()) {
                return;
            }

            $category = DB::table('categories')->where('slug', 'cakes')->first();
            if (! $category) {
                return;
            }

            $id = DB::table('products')->insertGetId([
                'category_id' => $category->id,
                'slug' => 'cake-2-in-1',
                'name' => '2 in 1 Cake',
                'collection' => 'Cakes',
                'description' => 'Eggless 500 gm cake, half vanilla and half chocolate.',
                'long_description' => "Can't pick one flavour? Our 2 in 1 Cake is half vanilla and half chocolate in a single 500 gm cake, 100% eggless and made fresh in small batches.",
                'flavour' => 'Vanilla & Chocolate',
                'base_price' => 1200,
                'ingredients' => json_encode([]),
                'allergens' => json_encode([]),
                'is_active' => true,
                'stock_status' => 'in_stock',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('product_sizes')->insert([
                'product_id' => $id, 'label' => '500 gm', 'price' => 1200, 'sort_order' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $this->setPhotos(DB::table('products')->find($id), ['cake-2-in-1-1']);
        });
    }

    public function down(): void
    {
        // Intentionally empty: the product may already have orders.
    }

    private function setPhotos(object $product, array $files): void
    {
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
};
