<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_list_returns_expected_shape(): void
    {
        $category = Category::factory()->create();
        Product::factory()->count(3)->create(['category_id' => $category->id]);

        $response = $this->getJson('/api/v1/categories');

        $response->assertOk()->assertJsonPath('success', true);

        $item = collect($response->json('data'))->firstWhere('id', $category->slug);
        $this->assertNotNull($item);
        foreach (['id', 'name', 'count', 'image'] as $key) {
            $this->assertArrayHasKey($key, $item);
        }
        $this->assertSame(3, $item['count']);
    }

    public function test_category_list_excludes_inactive_categories(): void
    {
        Category::factory()->create(['is_active' => false]);

        $response = $this->getJson('/api/v1/categories');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }
}
