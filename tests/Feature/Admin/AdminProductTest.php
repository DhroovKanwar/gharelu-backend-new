<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUsers;
use Tests\TestCase;

class AdminProductTest extends TestCase
{
    use RefreshDatabase, CreatesAdminUsers;

    private function payload(Category $category, array $overrides = []): array
    {
        return array_merge([
            'category_id' => $category->id,
            'name' => 'Chocolate Truffle Cake',
            'base_price' => 599,
            'is_active' => true,
        ], $overrides);
    }

    public function test_admin_can_create_product(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $category = Category::factory()->create();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/products', $this->payload($category));

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Chocolate Truffle Cake')
            ->assertJsonPath('data.slug', 'chocolate-truffle-cake');

        $this->assertDatabaseHas('products', ['name' => 'Chocolate Truffle Cake']);
    }

    public function test_admin_can_list_products_including_inactive(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $category = Category::factory()->create();
        Product::factory()->create(['category_id' => $category->id, 'is_active' => false]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/products');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_admin_can_view_single_product(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/admin/products/{$product->id}");

        $response->assertOk()->assertJsonPath('data.id', $product->id);
    }

    public function test_admin_can_update_product(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'base_price' => 100]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/admin/products/{$product->id}", ['base_price' => 250]);

        $response->assertOk()->assertJsonPath('data.basePrice', 250);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'base_price' => 250]);
    }

    public function test_admin_can_soft_delete_product(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/admin/products/{$product->id}");

        $response->assertOk();
        $this->assertSoftDeleted('products', ['id' => $product->id]);

        // Publicly invisible immediately after soft delete.
        $publicList = $this->getJson('/api/v1/products');
        $publicList->assertOk();
        $this->assertFalse(collect($publicList->json('data'))->contains('id', $product->slug));
    }

    public function test_product_creation_requires_name_category_and_price(): void
    {
        [, $token] = $this->actingAsAdmin('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/products', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['category_id', 'name', 'base_price']);
    }

    public function test_duplicate_slug_is_rejected(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $category = Category::factory()->create();
        $existing = Product::factory()->create(['category_id' => $category->id, 'slug' => 'taken-slug']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/products', $this->payload($category, ['slug' => 'taken-slug']));

        $response->assertStatus(422)->assertJsonValidationErrors(['slug']);
    }

    public function test_invalid_category_id_is_rejected(): void
    {
        [, $token] = $this->actingAsAdmin('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/products', [
                'category_id' => 999999,
                'name' => 'Some Cake',
                'base_price' => 599,
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['category_id']);
    }

    public function test_staff_cannot_access_product_management(): void
    {
        [, $token] = $this->actingAsAdmin('staff');
        $category = Category::factory()->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/products', $this->payload($category))
            ->assertStatus(403);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/products')
            ->assertStatus(403);
    }

    public function test_customer_cannot_access_product_management(): void
    {
        $customer = User::factory()->create();
        $token = $this->tokenFor($customer);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/products')
            ->assertStatus(403);
    }
}
