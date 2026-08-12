<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSize;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    /**
     * Source: frontend `src/data/products.json`.
     * Must run after CategorySeeder — products are matched to a category
     * by slugifying their `collection` field (e.g. "Signature Cakes" ->
     * "signature-cakes"), which lines up with the seeded category slugs.
     */
    public function run(): void
    {
        $path = database_path('seeders/data/products.json');
        $products = json_decode(file_get_contents($path), true);

        foreach ($products as $item) {
            $categorySlug = Str::slug($item['collection'] ?? $item['category']);
            $category = Category::where('slug', $categorySlug)->first();

            if (! $category) {
                $this->command?->warn(
                    "Skipping product '{$item['name']}': no category matches slug '{$categorySlug}'."
                );
                continue;
            }

            $product = Product::updateOrCreate(
                ['slug' => $item['id']],
                [
                    'category_id' => $category->id,
                    'name' => $item['name'],
                    'collection' => $item['collection'] ?? null,
                    'description' => $item['description'] ?? null,
                    'long_description' => $item['longDescription'] ?? null,
                    'flavour' => $item['flavour'] ?? null,
                    'base_price' => $item['price'],
                    'old_price' => $item['oldPrice'] ?? null,
                    'ingredients' => $item['ingredients'] ?? [],
                    'allergens' => $item['allergens'] ?? [],
                    'rating_cached' => $item['rating'] ?? 0,
                    'reviews_count_cached' => $item['reviews'] ?? 0,
                    'is_featured' => $item['featured'] ?? false,
                    'is_bestseller' => $item['bestseller'] ?? false,
                    'is_active' => true,
                    'stock_status' => 'in_stock',
                ]
            );

            // Sizes
            $product->sizes()->delete();
            foreach ($item['sizes'] ?? [] as $sortOrder => $size) {
                ProductSize::create([
                    'product_id' => $product->id,
                    'label' => $size['label'],
                    'price' => $size['price'],
                    'sort_order' => $sortOrder,
                ]);
            }

            // Images: primary `image` first, then `gallery[]`, de-duplicated.
            $product->images()->delete();
            $imagePaths = array_values(array_unique(array_filter([
                $item['image'] ?? null,
                ...($item['gallery'] ?? []),
            ])));

            foreach ($imagePaths as $sortOrder => $path) {
                ProductImage::create([
                    'product_id' => $product->id,
                    'path' => $path,
                    'alt' => $product->name,
                    'sort_order' => $sortOrder,
                    'is_primary' => $sortOrder === 0,
                ]);
            }
        }
    }
}
