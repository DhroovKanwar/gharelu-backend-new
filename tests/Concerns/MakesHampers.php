<?php

namespace Tests\Concerns;

use App\Models\Category;
use App\Models\Product;

trait MakesHampers
{
    /** Delivery is for hampers only, so delivery tests need a product in the Hampers category. */
    protected function asHamper(Product $product): Product
    {
        $category = Category::where('slug', 'hampers')->first()
            ?? Category::factory()->create(['slug' => 'hampers', 'name' => 'Hampers']);

        $product->update(['category_id' => $category->id]);

        return $product;
    }
}
