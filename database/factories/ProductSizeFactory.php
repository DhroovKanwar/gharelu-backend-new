<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductSize;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductSizeFactory extends Factory
{
    protected $model = ProductSize::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'label' => $this->faker->randomElement(['0.5 kg', '1 kg', '1.5 kg', '2 kg']),
            'price' => $this->faker->numberBetween(299, 3999),
            'sort_order' => 0,
        ];
    }
}
