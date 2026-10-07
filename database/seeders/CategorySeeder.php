<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    /**
     * Source: frontend `src/data/categories.json`.
     *
     * NOTE: that file only has 5 categories (signature-cakes, cupcakes,
     * macarons, artisan-breads, cookies), but `products.json` references
     * two more collections that have no matching category there
     * ("Cheesecakes", "Pastries"). They're appended below so every seeded
     * product in ProductSeeder has a valid category_id.
     */
    public function run(): void
    {
        $path = database_path('seeders/data/categories.json');
        $categories = json_decode(file_get_contents($path), true);

        foreach ($categories as $index => $category) {
            Category::updateOrCreate(
                ['slug' => $category['id']],
                [
                    'name' => $category['name'],
                    'image_path' => $category['image'],
                    'sort_order' => $index,
                    'is_active' => true,
                ]
            );
        }

        // Menu replaced: deactivate any category no longer on the menu.
        Category::whereNotIn('slug', array_column($categories, 'id'))
            ->update(['is_active' => false]);
    }
}
