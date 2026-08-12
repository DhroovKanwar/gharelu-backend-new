<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderApiTest extends TestCase
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

    private function basePayload(Product $product, array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Priya',
            'last_name' => 'Sharma',
            'email' => 'priya@example.com',
            'phone' => '9876543210',
            'delivery_mode' => 'pickup',
            'pickup_type' => 'parcel',
            'scheduled_date' => now()->addDay()->format('Y-m-d'),
            'scheduled_time' => '14:30',
            'payment_method' => 'cod',
            'items' => [
                ['product_slug' => $product->slug, 'size_label' => '0.5 kg', 'quantity' => 2],
            ],
        ], $overrides);
    }

    public function test_guest_can_create_order(): void
    {
        $product = $this->makeProduct();

        $response = $this->postJson('/api/v1/orders', $this->basePayload($product));

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.paymentStatus', 'pending');

        $this->assertDatabaseHas('orders', [
            'guest_email' => 'priya@example.com',
            'user_id' => null,
        ]);
    }

    public function test_authenticated_user_can_create_order(): void
    {
        $product = $this->makeProduct();
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/orders', $this->basePayload($product));

        $response->assertStatus(201);

        $this->assertDatabaseHas('orders', [
            'order_number' => $response->json('data.orderNumber'),
            'user_id' => $user->id,
        ]);
    }

    public function test_invalid_product_is_rejected(): void
    {
        $product = $this->makeProduct();

        $payload = $this->basePayload($product, [
            'items' => [
                ['product_slug' => 'does-not-exist', 'size_label' => '0.5 kg', 'quantity' => 1],
            ],
        ]);

        $response = $this->postJson('/api/v1/orders', $payload);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_inactive_product_is_rejected(): void
    {
        // Bypass the 'exists' rule check being the only gate — this product
        // exists but is inactive, so the *service* must catch it too.
        $product = $this->makeProduct(['is_active' => false]);

        $payload = $this->basePayload($product);

        $response = $this->postJson('/api/v1/orders', $payload);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_invalid_size_is_rejected(): void
    {
        $product = $this->makeProduct();

        $payload = $this->basePayload($product, [
            'items' => [
                ['product_slug' => $product->slug, 'size_label' => 'not-a-real-size', 'quantity' => 1],
            ],
        ]);

        $response = $this->postJson('/api/v1/orders', $payload);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_server_ignores_client_supplied_price_and_total(): void
    {
        $product = $this->makeProduct(sizes: [['label' => '0.5 kg', 'price' => 499]]);

        $payload = $this->basePayload($product, [
            'items' => [
                [
                    'product_slug' => $product->slug,
                    'size_label' => '0.5 kg',
                    'quantity' => 1,
                    // Attempted price manipulation — none of these are
                    // validated fields, so they must be silently ignored.
                    'price' => 1,
                    'unit_price' => 1,
                ],
            ],
            'subtotal' => 1,
            'total' => 1,
            'delivery_fee' => 0,
        ]);

        $response = $this->postJson('/api/v1/orders', $payload);

        $response->assertStatus(201);
        // Real price (499) must win, not the client's "1".
        $this->assertDatabaseHas('orders', ['subtotal' => 499.00, 'total' => 499.00]);
    }

    public function test_server_calculates_correct_subtotal(): void
    {
        $product = $this->makeProduct(sizes: [['label' => '0.5 kg', 'price' => 250]]);

        $payload = $this->basePayload($product, [
            'items' => [
                ['product_slug' => $product->slug, 'size_label' => '0.5 kg', 'quantity' => 3],
            ],
        ]);

        $response = $this->postJson('/api/v1/orders', $payload);

        $response->assertStatus(201)->assertJsonPath('data.subtotal', 750);
    }

    public function test_server_calculates_correct_total_including_delivery_fee(): void
    {
        $product = $this->makeProduct(sizes: [['label' => '0.5 kg', 'price' => 250]]);

        $payload = $this->basePayload($product, [
            'delivery_mode' => 'delivery',
            'pickup_type' => null,
            'address_line_1' => '221B Baker Street',
            'city' => 'Mumbai',
            'state' => 'Maharashtra',
            'pincode' => '400001',
            'items' => [
                ['product_slug' => $product->slug, 'size_label' => '0.5 kg', 'quantity' => 1],
            ],
        ]);

        $response = $this->postJson('/api/v1/orders', $payload);

        // subtotal 250 < 999 free-delivery threshold -> flat fee 49
        $response->assertStatus(201)
            ->assertJsonPath('data.subtotal', 250)
            ->assertJsonPath('data.deliveryFee', 49)
            ->assertJsonPath('data.total', 299);
    }

    public function test_delivery_address_is_required_for_delivery_mode(): void
    {
        $product = $this->makeProduct();

        $payload = $this->basePayload($product, [
            'delivery_mode' => 'delivery',
            'pickup_type' => null,
            // address fields intentionally omitted
        ]);

        $response = $this->postJson('/api/v1/orders', $payload);

        $response->assertStatus(422)->assertJsonValidationErrors([
            'address_line_1', 'city', 'state', 'pincode',
        ]);
    }

    public function test_pickup_mode_does_not_require_address_fields(): void
    {
        $product = $this->makeProduct();

        $payload = $this->basePayload($product, [
            'delivery_mode' => 'pickup',
            'pickup_type' => 'dine_in',
        ]);

        $response = $this->postJson('/api/v1/orders', $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('orders', ['delivery_mode' => 'pickup', 'address_id' => null]);
    }

    public function test_duplicate_idempotency_key_does_not_create_duplicate_order(): void
    {
        $product = $this->makeProduct();
        $payload = $this->basePayload($product);
        $key = 'test-idempotency-key-123';

        $first = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/orders', $payload);
        $second = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/orders', $payload);

        $first->assertStatus(201);
        $second->assertStatus(201);
        $this->assertSame(
            $first->json('data.orderNumber'),
            $second->json('data.orderNumber')
        );
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_customer_can_view_own_order(): void
    {
        $product = $this->makeProduct();
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $orderNumber = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/orders', $this->basePayload($product))
            ->json('data.orderNumber');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/orders/{$orderNumber}");

        $response->assertOk()->assertJsonPath('data.orderNumber', $orderNumber);
    }

    public function test_customer_cannot_view_another_customers_order(): void
    {
        $owner = User::factory()->create();

        // Created directly via Eloquent, not through an authenticated HTTP
        // call. (Two different Bearer tokens used across two authenticated
        // HTTP calls in one test method can hit Laravel's test-client
        // guard-caching artifact — the same one behind the Phase 4 logout
        // test fix — so this test only makes one authenticated request:
        // the intruder's.)
        $order = Order::create([
            'order_number' => 'GB'.random_int(100000, 999999),
            'user_id' => $owner->id,
            'guest_name' => 'Order Owner',
            'guest_email' => 'owner@example.com',
            'guest_phone' => '9999999999',
            'delivery_mode' => 'pickup',
            'pickup_type' => 'parcel',
            'scheduled_date' => now()->addDay()->format('Y-m-d'),
            'scheduled_time' => '10:00:00',
            'subtotal' => 100,
            'delivery_fee' => 0,
            'total' => 100,
            'currency' => 'INR',
            'payment_method' => 'cod',
            'payment_status' => 'pending',
            'order_status' => 'new',
        ]);

        $intruder = User::factory()->create();
        $intruderToken = $intruder->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$intruderToken}")
            ->getJson("/api/v1/orders/{$order->order_number}");

        $response->assertStatus(403);
    }

    public function test_unauthenticated_user_cannot_access_order_history(): void
    {
        $response = $this->getJson('/api/v1/orders');

        $response->assertStatus(401);
    }

    public function test_client_cannot_set_order_or_payment_status(): void
    {
        $product = $this->makeProduct();

        $payload = $this->basePayload($product, [
            'order_status' => 'completed',
            'payment_status' => 'paid',
        ]);

        $response = $this->postJson('/api/v1/orders', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.paymentStatus', 'pending');

        $this->assertDatabaseHas('orders', [
            'order_status' => 'new',
            'payment_status' => 'pending',
        ]);
    }

    public function test_historical_item_snapshot_is_stored_correctly(): void
    {
        $product = $this->makeProduct(
            ['name' => 'Original Cake Name'],
            [['label' => '0.5 kg', 'price' => 500]]
        );

        $response = $this->postJson('/api/v1/orders', $this->basePayload($product, [
            'items' => [
                ['product_slug' => $product->slug, 'size_label' => '0.5 kg', 'quantity' => 1],
            ],
        ]));

        $response->assertStatus(201);
        $orderNumber = $response->json('data.orderNumber');

        // Now change the live product — snapshot must not follow it.
        $product->update(['name' => 'Renamed Cake', 'base_price' => 9999]);
        $product->sizes()->where('label', '0.5 kg')->update(['price' => 9999]);

        $order = Order::where('order_number', $orderNumber)->with('items')->first();
        $item = $order->items->first();

        $this->assertSame('Original Cake Name', $item->product_name);
        $this->assertEquals(500.00, (float) $item->unit_price);
        $this->assertEquals(500.00, (float) $item->line_total);
    }

    public function test_order_rejects_quantity_below_minimum(): void
    {
        $product = $this->makeProduct();

        $payload = $this->basePayload($product, [
            'items' => [
                ['product_slug' => $product->slug, 'size_label' => '0.5 kg', 'quantity' => 0],
            ],
        ]);

        $response = $this->postJson('/api/v1/orders', $payload);

        $response->assertStatus(422)->assertJsonValidationErrors(['items.0.quantity']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_rejects_quantity_above_maximum(): void
    {
        $product = $this->makeProduct();

        $payload = $this->basePayload($product, [
            'items' => [
                ['product_slug' => $product->slug, 'size_label' => '0.5 kg', 'quantity' => 21],
            ],
        ]);

        $response = $this->postJson('/api/v1/orders', $payload);

        $response->assertStatus(422)->assertJsonValidationErrors(['items.0.quantity']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_response_matches_expected_contract_shape(): void
    {
        $product = $this->makeProduct();

        $response = $this->postJson('/api/v1/orders', $this->basePayload($product));

        $response->assertStatus(201)->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'orderNumber',
                'status',
                'paymentStatus',
                'paymentMethod',
                'deliveryMode',
                'pickupType',
                'scheduledDate',
                'scheduledTime',
                'customer' => ['name', 'email', 'phone'],
                'items' => [
                    '*' => ['productName', 'productImage', 'sizeLabel', 'unitPrice', 'quantity', 'lineTotal'],
                ],
                'subtotal',
                'deliveryFee',
                'total',
                'currency',
                'createdAt',
            ],
        ]);

        // Internal PK must never be exposed.
        $response->assertJsonMissingPath('data.id');
    }
}
