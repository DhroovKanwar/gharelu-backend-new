<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(array $attrs = [], array $sizes = [['label' => '0.5 kg', 'price' => 499]]): Product
    {
        $category = Category::factory()->create();

        $product = Product::factory()->create(array_merge([
            'category_id' => $category->id,
            'base_price' => 399,
        ], $attrs));

        foreach ($sizes as $i => $size) {
            ProductSize::factory()->create([
                'product_id' => $product->id,
                'label' => $size['label'],
                'price' => $size['price'],
                'sort_order' => $i,
            ]);
        }

        ProductImage::factory()->create([
            'product_id' => $product->id,
            'is_primary' => true,
        ]);

        return $product;
    }

    public function test_cart_validate_returns_server_calculated_totals(): void
    {
        $product = $this->makeProduct(sizes: [['label' => '0.5 kg', 'price' => 250]]);

        $response = $this->postJson('/api/v1/cart/validate', [
            'items' => [
                ['product_slug' => $product->slug, 'size_label' => '0.5 kg', 'quantity' => 3],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.subtotal', 750)
            ->assertJsonPath('data.deliveryFee', 0)
            ->assertJsonPath('data.total', 750);

        $item = $response->json('data.items.0');
        $this->assertSame($product->slug, $item['productSlug']);
        $this->assertSame('0.5 kg', $item['sizeLabel']);
        $this->assertEquals(250, $item['unitPrice']);
        $this->assertEquals(750, $item['lineTotal']);
    }

    public function test_cart_validate_applies_delivery_fee_when_requested(): void
    {
        $product = $this->makeProduct(sizes: [['label' => '0.5 kg', 'price' => 250]]);

        $response = $this->postJson('/api/v1/cart/validate', [
            'delivery_mode' => 'delivery',
            'items' => [
                ['product_slug' => $product->slug, 'size_label' => '0.5 kg', 'quantity' => 1],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.subtotal', 250)
            ->assertJsonPath('data.deliveryFee', 49)
            ->assertJsonPath('data.total', 299);
    }

    public function test_cart_validate_ignores_client_supplied_price(): void
    {
        $product = $this->makeProduct(sizes: [['label' => '0.5 kg', 'price' => 500]]);

        $response = $this->postJson('/api/v1/cart/validate', [
            'items' => [
                [
                    'product_slug' => $product->slug,
                    'size_label' => '0.5 kg',
                    'quantity' => 1,
                    'price' => 1,
                    'unit_price' => 1,
                ],
            ],
            'total' => 1,
        ]);

        $response->assertOk()->assertJsonPath('data.subtotal', 500);
    }

    public function test_cart_validate_rejects_invalid_product(): void
    {
        $response = $this->postJson('/api/v1/cart/validate', [
            'items' => [
                ['product_slug' => 'does-not-exist', 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_cart_validate_rejects_inactive_product(): void
    {
        $product = $this->makeProduct(['is_active' => false]);

        $response = $this->postJson('/api/v1/cart/validate', [
            'items' => [
                ['product_slug' => $product->slug, 'size_label' => '0.5 kg', 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_cart_validate_rejects_invalid_size(): void
    {
        $product = $this->makeProduct();

        $response = $this->postJson('/api/v1/cart/validate', [
            'items' => [
                ['product_slug' => $product->slug, 'size_label' => 'not-a-real-size', 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_cart_validate_rejects_quantity_below_minimum(): void
    {
        $product = $this->makeProduct();

        $response = $this->postJson('/api/v1/cart/validate', [
            'items' => [
                ['product_slug' => $product->slug, 'size_label' => '0.5 kg', 'quantity' => 0],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['items.0.quantity']);
    }

    public function test_cart_validate_rejects_quantity_above_maximum(): void
    {
        $product = $this->makeProduct();

        $response = $this->postJson('/api/v1/cart/validate', [
            'items' => [
                ['product_slug' => $product->slug, 'size_label' => '0.5 kg', 'quantity' => 21],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['items.0.quantity']);
    }
}
