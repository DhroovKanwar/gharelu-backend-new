<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUsers;
use Tests\TestCase;

class AdminCategoryTest extends TestCase
{
    use RefreshDatabase, CreatesAdminUsers;

    public function test_admin_can_create_category(): void
    {
        [, $token] = $this->actingAsAdmin('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/categories', ['name' => 'Cupcakes']);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Cupcakes')
            ->assertJsonPath('data.slug', 'cupcakes');
    }

    public function test_admin_can_list_categories(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        Category::factory()->count(3)->create();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/categories');

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_admin_can_update_category(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $category = Category::factory()->create(['is_active' => true]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/admin/categories/{$category->id}", ['is_active' => false]);

        $response->assertOk()->assertJsonPath('data.isActive', false);
    }

    public function test_duplicate_category_slug_is_rejected(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        Category::factory()->create(['slug' => 'existing-slug']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/categories', ['name' => 'Something', 'slug' => 'existing-slug']);

        $response->assertStatus(422)->assertJsonValidationErrors(['slug']);
    }

    public function test_category_with_products_cannot_be_deleted(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $category = Category::factory()->create();
        Product::factory()->create(['category_id' => $category->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/admin/categories/{$category->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_empty_category_can_be_deleted(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $category = Category::factory()->create();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/admin/categories/{$category->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_staff_cannot_manage_categories(): void
    {
        [, $token] = $this->actingAsAdmin('staff');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/categories')
            ->assertStatus(403);
    }
}
