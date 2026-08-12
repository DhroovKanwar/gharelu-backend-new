<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->words(3, true);
        $price = $this->faker->numberBetween(299, 3999);

        return [
            'category_id' => Category::factory(),
            'slug' => Str::slug($name),
            'name' => Str::title($name),
            'collection' => $this->faker->word(),
            'description' => $this->faker->sentence(),
            'long_description' => $this->faker->paragraph(),
            'flavour' => $this->faker->randomElement(['Chocolate', 'Vanilla', 'Red Velvet', 'Butterscotch']),
            'base_price' => $price,
            'old_price' => $this->faker->boolean(30) ? $price + 200 : null,
            'ingredients' => $this->faker->words(5),
            'allergens' => $this->faker->randomElements(['Gluten', 'Dairy', 'Soy', 'Nuts', 'Egg'], 2),
            'rating_cached' => $this->faker->randomFloat(2, 3.5, 5),
            'reviews_count_cached' => $this->faker->numberBetween(0, 300),
            'is_featured' => $this->faker->boolean(20),
            'is_bestseller' => $this->faker->boolean(20),
            'is_active' => true,
            'stock_status' => 'in_stock',
        ];
    }
}
