<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSize;
use App\Services\ShiprocketOrderSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreatesAdminUsers;
use Tests\Concerns\FakesShiprocket;
use Tests\Concerns\MakesHampers;
use Tests\TestCase;

/**
 * Product weight and price bounds (admin API), and how the weight reaches the
 * Shiprocket quote and order payload. Synthetic data only; Shiprocket is faked.
 */
class AdminProductBoundsTest extends TestCase
{
    use CreatesAdminUsers;
    use FakesShiprocket;
    use MakesHampers;
    use RefreshDatabase;

    private function createProduct(array $overrides = [])
    {
        [, $token] = $this->actingAsAdmin('manager');

        return $this->withToken($token)->postJson('/api/v1/admin/products', array_merge([
            'category_id' => Category::factory()->create()->id,
            'name' => 'Bounds Test Cake',
            'base_price' => 599,
            'weight_grams' => 750,
        ], $overrides));
    }

    private function existingProduct(array $attrs = []): Product
    {
        return Product::factory()->create(array_merge([
            'category_id' => Category::factory()->create()->id,
            'base_price' => 100,
            'weight_grams' => 500,
        ], $attrs));
    }

    // ── weight ────────────────────────────────────────────────────────

    public function test_valid_weight_is_saved_and_returned_by_the_api(): void
    {
        $response = $this->createProduct(['weight_grams' => 950])->assertCreated();

        $response->assertJsonPath('data.weightGrams', 950);
        $this->assertDatabaseHas('products', ['name' => 'Bounds Test Cake', 'weight_grams' => 950]);
    }

    public function test_weight_is_required_for_new_products(): void
    {
        $response = $this->createProduct(['weight_grams' => null]);
        $response->assertStatus(422)->assertJsonValidationErrors('weight_grams');

        [, $token] = $this->actingAsAdmin('manager');
        $payload = ['category_id' => Category::factory()->create()->id, 'name' => 'No Weight', 'base_price' => 100];
        $this->withToken($token)->postJson('/api/v1/admin/products', $payload)
            ->assertStatus(422)->assertJsonValidationErrors('weight_grams');
        $this->assertDatabaseCount('products', 0);
    }

    /** @dataProvider invalidWeights */
    public function test_invalid_weights_are_rejected(mixed $weight): void
    {
        $this->createProduct(['weight_grams' => $weight])
            ->assertStatus(422)
            ->assertJsonValidationErrors('weight_grams');

        $this->assertDatabaseCount('products', 0);
    }

    public static function invalidWeights(): array
    {
        return [
            'zero' => [0],
            'negative' => [-5],
            'above maximum' => [50001],
            'huge (would overflow the column)' => [99999999999],
            'fractional' => [250.5],
            'text' => ['heavy'],
        ];
    }

    public function test_maximum_weight_is_accepted(): void
    {
        $this->createProduct(['weight_grams' => 50000])->assertCreated()->assertJsonPath('data.weightGrams', 50000);
    }

    public function test_weight_can_be_updated_but_not_cleared_and_other_edits_still_work(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $product = $this->existingProduct(['weight_grams' => null]); // legacy product without a weight

        // An edit that does not touch the weight keeps working (legacy workflow).
        $this->withToken($token)->patchJson("/api/v1/admin/products/{$product->id}", ['name' => 'Renamed'])->assertOk();
        $this->assertNull($product->fresh()->weight_grams);

        $this->withToken($token)->patchJson("/api/v1/admin/products/{$product->id}", ['weight_grams' => 1200])
            ->assertOk()->assertJsonPath('data.weightGrams', 1200);

        foreach ([null, 0, -1, 50001] as $bad) {
            $this->withToken($token)->patchJson("/api/v1/admin/products/{$product->id}", ['weight_grams' => $bad])
                ->assertStatus(422)->assertJsonValidationErrors('weight_grams');
        }
        $this->assertSame(1200, $product->fresh()->weight_grams);
    }

    // ── price bounds ──────────────────────────────────────────────────

    public function test_normal_and_boundary_prices_are_accepted(): void
    {
        foreach ([40, 599.5, 3799, 0.01, 100000] as $price) {
            $this->createProduct(['name' => "Cake {$price}", 'base_price' => $price])->assertCreated();
        }
    }

    /** @dataProvider invalidPrices */
    public function test_invalid_prices_are_rejected_without_a_server_error(mixed $price): void
    {
        $response = $this->createProduct(['base_price' => $price]);

        $response->assertStatus(422)->assertJsonValidationErrors('base_price');
        $this->assertDatabaseCount('products', 0);
    }

    public static function invalidPrices(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'just above maximum' => [100000.01],
            'above maximum' => [100001],
            'overflows decimal(10,2)' => [1000000000000],
            'three decimals' => [10.005],
            'text' => ['abc'],
        ];
    }

    public function test_old_price_and_size_prices_share_the_same_bounds(): void
    {
        [, $token] = $this->actingAsAdmin('manager');

        $this->createProduct(['old_price' => 100001])->assertStatus(422)->assertJsonValidationErrors('old_price');

        $product = $this->existingProduct();
        $size = ProductSize::factory()->create(['product_id' => $product->id, 'label' => '1 kg', 'price' => 300]);

        foreach ([0, -1, 100001, 1000000000000, 5.555] as $bad) {
            $this->withToken($token)->postJson("/api/v1/admin/products/{$product->id}/sizes", ['label' => 'x'.random_int(1, 99999), 'price' => $bad])
                ->assertStatus(422)->assertJsonValidationErrors('price');
            $this->withToken($token)->patchJson("/api/v1/admin/products/{$product->id}/sizes/{$size->id}", ['price' => $bad])
                ->assertStatus(422)->assertJsonValidationErrors('price');
        }

        $this->withToken($token)->patchJson("/api/v1/admin/products/{$product->id}", ['base_price' => 1000000000000])
            ->assertStatus(422)->assertJsonValidationErrors('base_price');
    }

    public function test_existing_prices_are_not_changed_by_validation_or_failed_updates(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $product = $this->existingProduct(['base_price' => 1499]);

        $this->withToken($token)->patchJson("/api/v1/admin/products/{$product->id}", ['base_price' => 0])->assertStatus(422);

        $this->assertEquals(1499, $product->fresh()->base_price);
    }

    // ── weight reaches the Shiprocket quote and order payload ─────────

    private function deliveryOrderFor(Product $product, int $quantity): array
    {
        return [
            'first_name' => 'Priya', 'last_name' => 'Sharma', 'email' => 'priya@example.com',
            'phone' => '9876543210', 'delivery_mode' => 'delivery', 'pickup_type' => null,
            'address_line_1' => '221B Baker Street', 'city' => 'Mohali', 'state' => 'Punjab', 'pincode' => '140603',
            'scheduled_date' => now()->addDay()->format('Y-m-d'), 'scheduled_time' => '14:30',
            'payment_method' => 'online',
            'items' => [['product_slug' => $product->slug, 'quantity' => $quantity]],
        ];
    }

    public function test_product_weight_is_converted_to_kilograms_in_the_courier_quote_and_the_shiprocket_order(): void
    {
        $this->fakeShiprocket(rate: 150);
        config(['services.shiprocket.packaging_weight_kg' => 0.3, 'services.shiprocket.default_item_weight_kg' => 0.5]);
        $product = $this->asHamper($this->existingProduct(['weight_grams' => 500]));
        ProductImage::factory()->create(['product_id' => $product->id, 'is_primary' => true]);

        $this->postJson('/api/v1/orders', $this->deliveryOrderFor($product, 2))->assertCreated();

        // 2 x 500 g = 1.0 kg + 0.3 kg packaging
        $quotes = Http::recorded(fn ($request) => str_contains($request->url(), 'courier/serviceability'));
        $this->assertNotEmpty($quotes);
        $this->assertEqualsWithDelta(1.3, (float) $quotes->first()[0]->data()['weight'], 0.001);

        $order = Order::firstOrFail();
        $order->update(['payment_status' => 'paid']);
        $this->assertTrue(app(ShiprocketOrderSync::class)->push($order->fresh()));

        $created = Http::recorded(fn ($request) => str_contains($request->url(), 'orders/create/adhoc'));
        $payload = $created->first()[0]->data();
        $this->assertEqualsWithDelta(1.3, (float) $payload['weight'], 0.001);
        $this->assertSame(2, $payload['order_items'][0]['units']);
    }

    public function test_a_missing_product_weight_uses_the_default_and_is_logged(): void
    {
        $this->fakeShiprocket(rate: 150);
        config(['services.shiprocket.packaging_weight_kg' => 0.3, 'services.shiprocket.default_item_weight_kg' => 0.5]);
        $product = $this->asHamper($this->existingProduct(['weight_grams' => null]));

        Log::spy();

        $this->postJson('/api/v1/orders', $this->deliveryOrderFor($product, 1))->assertCreated();

        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'weight guessed'))->atLeast()->once();

        $quotes = Http::recorded(fn ($request) => str_contains($request->url(), 'courier/serviceability'));
        $this->assertEqualsWithDelta(0.8, (float) $quotes->first()[0]->data()['weight'], 0.001);
    }
}
