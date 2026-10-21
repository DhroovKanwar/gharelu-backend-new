<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration: real menu photos (files live in the storefront's
 * public/images/products). Replaces only the product_images rows of the
 * listed slugs; products that do not exist are skipped. Idempotent.
 */
return new class extends Migration
{
    private const PHOTOS = [
        'cake-pineapple' => ['cake-pineapple-1', 'cake-pineapple-2', 'cake-pineapple-3'],
        'cake-fresh-fruit' => ['cake-fresh-fruit-1', 'cake-fresh-fruit-2', 'cake-fresh-fruit-3'],
        'cake-tiramisu' => ['cake-tiramisu-1', 'cake-tiramisu-2', 'cake-tiramisu-3'],
        'cake-2-in-1' => ['cake-2-in-1-1', 'cake-2-in-1-2', 'cake-2-in-1-3'],
        'dry-cakes-banana-walnut' => ['dry-cakes-banana-walnut-1', 'dry-cakes-banana-walnut-2', 'dry-cakes-banana-walnut-3'],
        'dry-cakes-honey-almond' => ['dry-cakes-honey-almond-1', 'dry-cakes-honey-almond-2', 'dry-cakes-honey-almond-3'],
        'dry-cakes-fudgy-brownie' => ['dry-cakes-fudgy-brownie-1', 'dry-cakes-fudgy-brownie-2', 'dry-cakes-fudgy-brownie-3'],
        'dry-cakes-blueberry-muffin' => ['dry-cakes-blueberry-muffin-1', 'dry-cakes-blueberry-muffin-2', 'dry-cakes-blueberry-muffin-3'],
        'pastries-pineapple' => ['pastries-pineapple-1', 'pastries-pineapple-2', 'pastries-pineapple-3'],
        'pastries-tiramisu' => ['pastries-tiramisu-1', 'pastries-tiramisu-2', 'pastries-tiramisu-3'],
        'pastries-ny-cheesecake' => ['pastries-ny-cheesecake-1', 'pastries-ny-cheesecake-2', 'pastries-ny-cheesecake-3'],
        'pastries-lotus-biscoff' => ['pastries-lotus-biscoff-1', 'pastries-lotus-biscoff-2', 'pastries-lotus-biscoff-3'],
        'pastries-blueberry-cheesecake' => ['pastries-blueberry-cheesecake-1', 'pastries-blueberry-cheesecake-2', 'pastries-blueberry-cheesecake-3'],
        'jars-tiramisu' => ['jars-tiramisu-1', 'jars-tiramisu-2'],
        'jars-red-velvet' => ['jars-red-velvet-1', 'jars-red-velvet-2', 'jars-red-velvet-3'],
        'jars-fruit-custard' => ['jars-fruit-custard-1', 'jars-fruit-custard-2'],
        'cookies-coffee-walnut-cookies' => ['cookies-coffee-walnut-cookies-1', 'cookies-coffee-walnut-cookies-2', 'cookies-coffee-walnut-cookies-3'],
        'cookies-oatmeal-cookies' => ['cookies-oatmeal-cookies-1', 'cookies-oatmeal-cookies-2', 'cookies-oatmeal-cookies-3'],
        'cookies-red-velvet-cookies' => ['cookies-red-velvet-cookies-1', 'cookies-red-velvet-cookies-2'],
        'cookies-ajwain-cookies-sf' => ['cookies-ajwain-cookies-sf-1', 'cookies-ajwain-cookies-sf-2', 'cookies-ajwain-cookies-sf-3'],
        'savoury-gharelu-special-sandwich' => ['savoury-gharelu-special-sandwich-1', 'savoury-gharelu-special-sandwich-2', 'savoury-gharelu-special-sandwich-3'],
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
        // Intentionally empty: the previous placeholder URLs are not restored.
    }
};
