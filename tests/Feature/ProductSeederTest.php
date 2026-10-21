<?php

namespace Tests\Feature;

use App\Models\Product;
use Database\Seeders\CategorySeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_database_gets_the_full_menu_with_sellable_hampers(): void
    {
        $this->seed(CategorySeeder::class);
        $this->seed(ProductSeeder::class);

        // The storefront sells these four hampers under exactly these slugs.
        $expected = [
            'luxe-hamper' => [1390, 500],
            'classic-hamper-pro' => [1290, 750],
            'signature-hamper-pro' => [1690, 500],
            'luxe-hamper-pro' => [2290, 950],
        ];

        foreach ($expected as $slug => [$price, $grams]) {
            $product = Product::where('slug', $slug)->first();
            $this->assertNotNull($product, "{$slug} is missing from the seed");
            $this->assertTrue($product->is_active);
            $this->assertSame('hampers', $product->category->slug);
            $this->assertEquals($price, $product->base_price);
            $this->assertSame($grams, $product->weight_grams);
            $this->assertGreaterThan(0, $product->images()->count());
        }

        $this->assertGreaterThan(60, Product::where('is_active', true)->count());
    }

    public function test_seeding_twice_does_not_duplicate_products(): void
    {
        $this->seed(CategorySeeder::class);
        $this->seed(ProductSeeder::class);
        $count = Product::count();

        $this->seed(ProductSeeder::class);

        $this->assertSame($count, Product::count());
    }
}
