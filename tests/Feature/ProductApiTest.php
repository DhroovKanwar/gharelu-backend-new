<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(array $attrs = []): Product
    {
        $category = Category::factory()->create();

        $product = Product::factory()->create(array_merge([
            'category_id' => $category->id,
        ], $attrs));

        ProductSize::factory()->create([
            'product_id' => $product->id,
            'label' => '0.5 kg',
            'price' => 499,
            'sort_order' => 0,
        ]);

        ProductImage::factory()->create([
            'product_id' => $product->id,
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        return $product;
    }

    public function test_product_list_returns_active_products_with_expected_shape(): void
    {
        $product = $this->makeProduct();

        $response = $this->getJson('/api/v1/products');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success', 'message', 'data', 'meta' => [
                    'current_page', 'last_page', 'per_page', 'total',
                ],
            ]);

        $response->assertJsonFragment(['id' => $product->slug]);

        $item = collect($response->json('data'))->firstWhere('id', $product->slug);
        foreach ([
            'id', 'name', 'category', 'collection', 'price', 'oldPrice',
            'image', 'gallery', 'tag', 'rating', 'reviews', 'featured',
            'bestseller', 'description', 'longDescription', 'flavour',
            'sizes', 'ingredients', 'allergens',
        ] as $key) {
            $this->assertArrayHasKey($key, $item);
        }
    }

    public function test_product_list_excludes_inactive_products(): void
    {
        $this->makeProduct(['is_active' => false]);

        $response = $this->getJson('/api/v1/products');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_product_detail_returns_by_slug(): void
    {
        $product = $this->makeProduct();

        $response = $this->getJson("/api/v1/products/{$product->slug}");

        $response->assertOk()
            ->assertJsonPath('data.id', $product->slug)
            ->assertJsonPath('data.name', $product->name);
    }

    public function test_product_detail_returns_404_for_missing_slug(): void
    {
        $response = $this->getJson('/api/v1/products/does-not-exist');

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_featured_endpoint_only_returns_featured_products(): void
    {
        $featured = $this->makeProduct(['is_featured' => true]);
        $this->makeProduct(['is_featured' => false]);

        $response = $this->getJson('/api/v1/products/featured');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($featured->slug));
        $this->assertCount(1, $ids);
    }

    public function test_category_filter_only_returns_matching_products(): void
    {
        $categoryA = Category::factory()->create();
        $categoryB = Category::factory()->create();

        $productA = Product::factory()->create(['category_id' => $categoryA->id]);
        Product::factory()->create(['category_id' => $categoryB->id]);

        $response = $this->getJson("/api/v1/products?category={$categoryA->slug}");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($productA->slug));
        $this->assertCount(1, $ids);
    }

    public function test_product_index_rejects_invalid_query_params(): void
    {
        $response = $this->getJson('/api/v1/products?per_page=999');

        $response->assertStatus(422);
    }

    public function test_product_detail_includes_related_products(): void
    {
        $category = Category::factory()->create();
        $target = $this->makeProduct(['category_id' => $category->id]);

        $related1 = $this->makeProduct(['category_id' => $category->id]);
        $related2 = $this->makeProduct(['category_id' => $category->id]);
        $related3 = $this->makeProduct(['category_id' => $category->id]);

        // Should NOT appear: different category/collection.
        $otherCategory = Category::factory()->create();
        $this->makeProduct(['category_id' => $otherCategory->id, 'collection' => 'Unrelated Collection']);

        // Should NOT appear: same category but inactive.
        $this->makeProduct(['category_id' => $category->id, 'is_active' => false]);

        $response = $this->getJson("/api/v1/products/{$target->slug}");

        $response->assertOk();
        $relatedIds = collect($response->json('relatedProducts'))->pluck('id');

        $this->assertCount(3, $relatedIds);
        $this->assertFalse($relatedIds->contains($target->slug));

        foreach ([$related1->slug, $related2->slug, $related3->slug] as $slug) {
            $this->assertTrue($relatedIds->contains($slug));
        }

        // Related items use the same ProductResource shape as the main listing.
        $relatedItem = collect($response->json('relatedProducts'))->first();
        foreach (['id', 'name', 'price', 'image', 'sizes'] as $key) {
            $this->assertArrayHasKey($key, $relatedItem);
        }
    }

    public function test_product_detail_related_products_capped_at_three(): void
    {
        $category = Category::factory()->create();
        $target = $this->makeProduct(['category_id' => $category->id]);

        for ($i = 0; $i < 5; $i++) {
            $this->makeProduct(['category_id' => $category->id]);
        }

        $response = $this->getJson("/api/v1/products/{$target->slug}");

        $response->assertOk();
        $this->assertCount(3, $response->json('relatedProducts'));
    }
}
