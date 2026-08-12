<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminUsers;
use Tests\TestCase;

class AdminProductImageTest extends TestCase
{
    use RefreshDatabase, CreatesAdminUsers;

    private function makeProduct(): Product
    {
        return Product::factory()->create(['category_id' => Category::factory()->create()->id]);
    }

    /**
     * A real, minimal, valid PNG — not UploadedFile::fake()->image(), which
     * needs the GD extension to synthesize image bytes and isn't available
     * in every environment. This is genuine image content, so it exercises
     * the same MIME/content sniffing a real upload would.
     */
    private function fakeImageFile(string $name = 'cake.png'): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $path = tempnam(sys_get_temp_dir(), 'admin_test_img').'.png';
        file_put_contents($path, $png);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    public function test_admin_can_upload_product_image(): void
    {
        Storage::fake('public');
        [, $token] = $this->actingAsAdmin('manager');
        $product = $this->makeProduct();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->post("/api/v1/admin/products/{$product->id}/images", [
                'image' => $this->fakeImageFile(),
                'is_primary' => true,
            ]);

        $response->assertStatus(201)->assertJsonPath('success', true);
        $this->assertDatabaseHas('product_images', ['product_id' => $product->id, 'is_primary' => true]);

        $path = $response->json('data.path');
        Storage::disk('public')->assertExists($path);
    }

    public function test_uploading_a_non_image_file_is_rejected(): void
    {
        Storage::fake('public');
        [, $token] = $this->actingAsAdmin('manager');
        $product = $this->makeProduct();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Accept', 'application/json')
            ->post("/api/v1/admin/products/{$product->id}/images", [
                'image' => UploadedFile::fake()->create('malicious.php', 10, 'application/x-php'),
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['image']);
    }

    public function test_uploading_a_new_primary_image_unsets_the_previous_primary(): void
    {
        Storage::fake('public');
        [, $token] = $this->actingAsAdmin('manager');
        $product = $this->makeProduct();
        $existing = ProductImage::factory()->create(['product_id' => $product->id, 'is_primary' => true]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->post("/api/v1/admin/products/{$product->id}/images", [
                'image' => $this->fakeImageFile('new.png'),
                'is_primary' => true,
            ])->assertStatus(201);

        $this->assertDatabaseHas('product_images', ['id' => $existing->id, 'is_primary' => false]);
    }

    public function test_admin_can_delete_product_image(): void
    {
        Storage::fake('public');
        [, $token] = $this->actingAsAdmin('manager');
        $product = $this->makeProduct();
        $image = ProductImage::factory()->create(['product_id' => $product->id, 'path' => 'products/existing.jpg']);
        Storage::disk('public')->put('products/existing.jpg', 'fake-content');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/admin/products/{$product->id}/images/{$image->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('product_images', ['id' => $image->id]);
        Storage::disk('public')->assertMissing('products/existing.jpg');
    }

    public function test_cannot_delete_image_belonging_to_a_different_product(): void
    {
        Storage::fake('public');
        [, $token] = $this->actingAsAdmin('manager');
        $product = $this->makeProduct();
        $otherProduct = $this->makeProduct();
        $image = ProductImage::factory()->create(['product_id' => $otherProduct->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/admin/products/{$product->id}/images/{$image->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('product_images', ['id' => $image->id]);
    }

    public function test_admin_can_reorder_product_images(): void
    {
        Storage::fake('public');
        [, $token] = $this->actingAsAdmin('manager');
        $product = $this->makeProduct();
        $first = ProductImage::factory()->create(['product_id' => $product->id, 'sort_order' => 0]);
        $second = ProductImage::factory()->create(['product_id' => $product->id, 'sort_order' => 1]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/admin/products/{$product->id}/images/reorder", [
                'order' => [$second->id, $first->id],
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('product_images', ['id' => $second->id, 'sort_order' => 0]);
        $this->assertDatabaseHas('product_images', ['id' => $first->id, 'sort_order' => 1]);
    }

    public function test_staff_cannot_upload_product_images(): void
    {
        Storage::fake('public');
        [, $token] = $this->actingAsAdmin('staff');
        $product = $this->makeProduct();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->post("/api/v1/admin/products/{$product->id}/images", [
                'image' => $this->fakeImageFile(),
            ]);

        $response->assertStatus(403);
    }
}
