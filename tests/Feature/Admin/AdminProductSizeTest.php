<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUsers;
use Tests\TestCase;

class AdminProductSizeTest extends TestCase
{
    use RefreshDatabase, CreatesAdminUsers;

    private function makeProduct(): Product
    {
        return Product::factory()->create(['category_id' => Category::factory()->create()->id]);
    }

    public function test_admin_can_add_size_to_product(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $product = $this->makeProduct();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/admin/products/{$product->id}/sizes", [
                'label' => '1 kg',
                'price' => 899,
            ]);

        $response->assertStatus(201)->assertJsonPath('data.label', '1 kg');
        $this->assertDatabaseHas('product_sizes', ['product_id' => $product->id, 'label' => '1 kg']);
    }

    public function test_admin_can_update_size_price(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $product = $this->makeProduct();
        $size = ProductSize::factory()->create(['product_id' => $product->id, 'price' => 500]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/admin/products/{$product->id}/sizes/{$size->id}", ['price' => 650]);

        $response->assertOk()->assertJsonPath('data.price', 650);
    }

    public function test_admin_can_delete_size(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $product = $this->makeProduct();
        $size = ProductSize::factory()->create(['product_id' => $product->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/admin/products/{$product->id}/sizes/{$size->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('product_sizes', ['id' => $size->id]);
    }

    public function test_invalid_price_is_rejected(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $product = $this->makeProduct();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/admin/products/{$product->id}/sizes", [
                'label' => '1 kg',
                'price' => -50,
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['price']);
    }

    public function test_duplicate_label_for_same_product_is_rejected(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $product = $this->makeProduct();
        ProductSize::factory()->create(['product_id' => $product->id, 'label' => '0.5 kg']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/admin/products/{$product->id}/sizes", [
                'label' => '0.5 kg',
                'price' => 400,
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['label']);
    }

    public function test_cannot_update_size_belonging_to_a_different_product(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $product = $this->makeProduct();
        $otherProduct = $this->makeProduct();
        $size = ProductSize::factory()->create(['product_id' => $otherProduct->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/admin/products/{$product->id}/sizes/{$size->id}", ['price' => 100]);

        $response->assertStatus(404);
    }

    public function test_admin_can_reorder_sizes(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $product = $this->makeProduct();
        $first = ProductSize::factory()->create(['product_id' => $product->id, 'label' => '0.5 kg', 'sort_order' => 0]);
        $second = ProductSize::factory()->create(['product_id' => $product->id, 'label' => '1 kg', 'sort_order' => 1]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/admin/products/{$product->id}/sizes/reorder", [
                'order' => [$second->id, $first->id],
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('product_sizes', ['id' => $second->id, 'sort_order' => 0]);
        $this->assertDatabaseHas('product_sizes', ['id' => $first->id, 'sort_order' => 1]);
    }
}
