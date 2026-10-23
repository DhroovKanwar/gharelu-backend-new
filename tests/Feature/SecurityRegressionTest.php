<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAdminUsers;
use Tests\Concerns\FakesShiprocket;
use Tests\Concerns\MakesHampers;
use Tests\TestCase;

/**
 * Regression tests for the security audit findings. Synthetic data only;
 * every external service (Razorpay, Shiprocket) is faked.
 */
class SecurityRegressionTest extends TestCase
{
    use CreatesAdminUsers;
    use FakesShiprocket;
    use MakesHampers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'razorpay.key_id' => 'rzp_test_public_key',
            'razorpay.key_secret' => 'test_key_secret',
            'razorpay.webhook_secret' => 'test_webhook_secret',
        ]);
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function makeProduct(int $price = 250): Product
    {
        $product = Product::factory()->create([
            'category_id' => Category::factory()->create()->id,
            'base_price' => $price,
        ]);
        ProductSize::factory()->create(['product_id' => $product->id, 'label' => '0.5 kg', 'price' => $price, 'sort_order' => 0]);
        ProductImage::factory()->create(['product_id' => $product->id, 'is_primary' => true]);

        return $product;
    }

    private function orderPayload(Product $product, array $overrides = []): array
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
            'payment_method' => 'online',
            'items' => [['product_slug' => $product->slug, 'size_label' => '0.5 kg', 'quantity' => 1]],
        ], $overrides);
    }

    private function deliveryPayload(Product $product, array $overrides = []): array
    {
        return $this->orderPayload($product, array_merge([
            'delivery_mode' => 'delivery',
            'recipient_first_name' => 'Anita', 'recipient_last_name' => 'Verma', 'recipient_phone' => '9811122233',
            'pickup_type' => null,
            'address_line_1' => '221B Baker Street',
            'city' => 'Mohali',
            'state' => 'Punjab',
            'pincode' => '140603',
        ], $overrides));
    }

    private function makeOrder(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'GBTESTORDER'.random_int(10, 99),
            'user_id' => null,
            'guest_name' => 'Test Customer',
            'guest_email' => 'secret-customer@example.com',
            'guest_phone' => '9123456789',
            'delivery_mode' => 'pickup',
            'pickup_type' => 'parcel',
            'scheduled_date' => now()->addDay()->format('Y-m-d'),
            'scheduled_time' => '10:00:00',
            'subtotal' => 499,
            'delivery_fee' => 0,
            'total' => 499,
            'currency' => 'INR',
            'payment_method' => 'online',
            'payment_status' => 'pending',
            'order_status' => 'new',
        ], $attrs));
    }

    private function postWebhook(string $payload, ?string $signature)
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($signature !== null) {
            $server['HTTP_X_RAZORPAY_SIGNATURE'] = $signature;
        }

        return $this->call('POST', '/api/v1/payments/webhook', [], [], [], $server, $payload);
    }

    private function capturedPayload(string $razorpayOrderId): string
    {
        return json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => [
            'id' => 'pay_FORGED', 'order_id' => $razorpayOrderId, 'status' => 'captured',
        ]]]]);
    }

    // ── SEC-01 webhook: missing secret / forged signature ─────────────

    public function test_webhook_is_rejected_when_no_secret_is_configured(): void
    {
        config(['razorpay.webhook_secret' => '']);
        $this->fakeShiprocket();

        $order = $this->makeOrder(['delivery_mode' => 'delivery', 'pickup_type' => null]);
        Payment::create(['order_id' => $order->id, 'razorpay_order_id' => 'order_X1', 'status' => 'created', 'amount' => 499, 'currency' => 'INR']);

        $payload = $this->capturedPayload('order_X1');
        // An attacker can compute this: HMAC with an empty key.
        $forged = hash_hmac('sha256', $payload, '');

        $this->postWebhook($payload, $forged)->assertStatus(400);

        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertDatabaseCount('payment_webhook_logs', 0);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'orders/create/adhoc'));
    }

    public function test_webhook_with_wrong_signature_cannot_mark_order_paid_or_trigger_shiprocket(): void
    {
        $this->fakeShiprocket();

        $order = $this->makeOrder(['delivery_mode' => 'delivery', 'pickup_type' => null]);
        Payment::create(['order_id' => $order->id, 'razorpay_order_id' => 'order_X2', 'status' => 'created', 'amount' => 499, 'currency' => 'INR']);

        $this->postWebhook($this->capturedPayload('order_X2'), 'deadbeef')->assertStatus(400);

        $this->assertSame('pending', $order->fresh()->payment_status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'orders/create/adhoc'));
    }

    public function test_webhook_without_signature_header_is_rejected(): void
    {
        $this->postWebhook($this->capturedPayload('order_X3'), null)->assertStatus(400);
    }

    public function test_valid_webhook_marks_delivery_order_paid_and_sends_it_to_shiprocket_once(): void
    {
        $this->fakeShiprocket();

        $order = $this->makeOrder(['delivery_mode' => 'delivery', 'pickup_type' => null]);
        $order->address()->associate(\App\Models\Address::factory()->create())->save();
        Payment::create(['order_id' => $order->id, 'razorpay_order_id' => 'order_X4', 'status' => 'created', 'amount' => 499, 'currency' => 'INR']);

        $payload = $this->capturedPayload('order_X4');
        $signature = hash_hmac('sha256', $payload, 'test_webhook_secret');

        $this->postWebhook($payload, $signature)->assertOk();

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('111', $order->shiprocket_order_id);

        // Replay of the same event must not create a second Shiprocket order.
        $this->postWebhook($payload, $signature)->assertOk();
        $creates = Http::recorded(fn ($request) => str_contains($request->url(), 'orders/create/adhoc'));
        $this->assertCount(1, $creates);
    }

    public function test_payment_verify_is_rejected_when_key_secret_is_not_configured(): void
    {
        config(['razorpay.key_secret' => '']);

        $order = $this->makeOrder();
        Payment::create(['order_id' => $order->id, 'razorpay_order_id' => 'order_X5', 'status' => 'created', 'amount' => 499, 'currency' => 'INR']);

        $forged = hash_hmac('sha256', 'order_X5|pay_1', '');

        $this->postJson('/api/v1/payments/verify', [
            'razorpay_order_id' => 'order_X5',
            'razorpay_payment_id' => 'pay_1',
            'razorpay_signature' => $forged,
        ])->assertStatus(422);

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    // ── SEC-02 guest order lookup must not leak personal data ─────────

    public function test_phone_lookup_returns_tracking_data_without_personal_details(): void
    {
        // The lookup query uses MySQL's REGEXP_REPLACE; SQLite has no such function.
        \Illuminate\Support\Facades\DB::connection()->getPdo()->sqliteCreateFunction(
            'REGEXP_REPLACE',
            fn ($value, $pattern, $replacement) => preg_replace('/'.$pattern.'/', $replacement, (string) $value),
            3,
        );

        $this->makeOrder(['guest_phone' => '9123456789']);

        $response = $this->postJson('/api/v1/orders/lookup', ['phone' => '9123456789'])->assertOk();

        $body = $response->getContent();
        $this->assertStringNotContainsString('secret-customer@example.com', $body);
        $this->assertStringNotContainsString('Test Customer', $body);
        $this->assertStringNotContainsString('9123456789', $body);
        $this->assertArrayNotHasKey('customer', $response->json('data.0'));
        $this->assertArrayNotHasKey('address', $response->json('data.0'));
        $this->assertSame('new', $response->json('data.0.status'));
    }

    // ── SEC-03 order numbers can't be guessed by counting up ──────────
    // Format: a running count (easy to say and search) plus a random code, e.g. GB0000001-K7Q4.

    public function test_new_order_numbers_count_up_but_carry_a_random_code(): void
    {
        $product = $this->makeProduct();

        $a = $this->postJson('/api/v1/orders', $this->orderPayload($product))->assertCreated()->json('data.orderNumber');
        $b = $this->postJson('/api/v1/orders', $this->orderPayload($product))->assertCreated()->json('data.orderNumber');

        $this->assertMatchesRegularExpression('/^GB0000001-[A-HJKMNP-Z2-9]{4}$/', $a);
        $this->assertMatchesRegularExpression('/^GB0000002-[A-HJKMNP-Z2-9]{4}$/', $b);
    }

    // ── SEC-04 token expiry ───────────────────────────────────────────

    public function test_sanctum_tokens_expire(): void
    {
        $this->assertGreaterThan(0, config('sanctum.expiration'));

        $user = User::factory()->create();
        $token = $user->createToken('t');
        $token->accessToken->forceFill(['created_at' => now()->subMinutes(config('sanctum.expiration') + 5)])->save();

        $this->withToken($token->plainTextToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    // ── SEC-05 security headers ───────────────────────────────────────

    public function test_api_responses_carry_security_headers(): void
    {
        $response = $this->getJson('/api/v1/categories');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
    }

    // ── SEC-06 login rate limits ──────────────────────────────────────

    public function test_login_is_rate_limited_per_account_across_ips(): void
    {
        $statuses = [];
        for ($i = 1; $i <= 12; $i++) {
            $statuses[] = $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
                ->postJson('/api/v1/auth/login', ['email' => 'victim@example.com', 'password' => 'wrong-password-'.$i])
                ->getStatusCode();
        }

        $this->assertContains(429, $statuses, 'Per-account limiter should block distributed guessing.');
    }

    public function test_admin_login_is_rate_limited_per_account_across_ips(): void
    {
        $statuses = [];
        for ($i = 1; $i <= 12; $i++) {
            $statuses[] = $this->withServerVariables(['REMOTE_ADDR' => "10.1.0.{$i}"])
                ->postJson('/api/v1/admin/auth/login', ['email' => 'admin@example.com', 'password' => 'wrong-password-'.$i])
                ->getStatusCode();
        }

        $this->assertContains(429, $statuses);
    }

    // ── Authorization (BOLA / function-level) ─────────────────────────

    public function test_admin_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/orders')->assertUnauthorized();
        $this->getJson('/api/v1/admin/products')->assertUnauthorized();
        $this->patchJson('/api/v1/admin/orders/1/status', ['status' => 'delivered'])->assertUnauthorized();
    }

    public function test_customer_token_cannot_use_admin_endpoints(): void
    {
        $customer = User::factory()->create(['role' => null]);

        $this->withToken($this->tokenFor($customer))->getJson('/api/v1/admin/orders')->assertForbidden();
        $this->withToken($this->tokenFor($customer))->getJson('/api/v1/admin/products')->assertForbidden();
    }

    public function test_staff_cannot_use_manager_only_endpoints(): void
    {
        [, $token] = $this->actingAsAdmin('staff');

        $this->withToken($token)->getJson('/api/v1/admin/products')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_user_cannot_read_another_users_order(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $order = $this->makeOrder(['user_id' => $owner->id]);

        $this->getJson('/api/v1/orders/'.$order->order_number)->assertUnauthorized();
        $this->withToken($this->tokenFor($other))->getJson('/api/v1/orders/'.$order->order_number)->assertForbidden();
        // The auth guard caches the previous request's user inside one test.
        $this->app['auth']->forgetGuards();
        $this->withToken($this->tokenFor($owner))->getJson('/api/v1/orders/'.$order->order_number)->assertOk();
    }

    public function test_user_cannot_create_payment_for_another_users_order(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $order = $this->makeOrder(['user_id' => $owner->id]);

        $this->withToken($this->tokenFor($other))
            ->postJson('/api/v1/payments/create-order', ['order_number' => $order->order_number])
            ->assertForbidden();
    }

    // ── Validation, mass assignment & business rules ──────────────────

    public function test_client_cannot_override_price_status_or_fees(): void
    {
        $product = $this->makeProduct(250);

        $response = $this->postJson('/api/v1/orders', $this->orderPayload($product, [
            'subtotal' => 1,
            'total' => 1,
            'delivery_fee' => 0,
            'payment_status' => 'paid',
            'order_status' => 'delivered',
            'user_id' => 999,
            'items' => [['product_slug' => $product->slug, 'size_label' => '0.5 kg', 'quantity' => 2, 'unit_price' => 1, 'price' => 1]],
        ]))->assertCreated();

        $this->assertEquals(500, $response->json('data.subtotal'));
        $this->assertEquals(500, $response->json('data.total'));
        $this->assertSame('pending', $response->json('data.paymentStatus'));
        $this->assertSame('new', $response->json('data.status'));
        $this->assertNull(Order::first()->user_id);
    }

    public function test_cash_on_delivery_is_rejected_for_delivery_orders(): void
    {
        $this->fakeShiprocket();
        $product = $this->makeProduct();

        $this->postJson('/api/v1/orders', $this->deliveryPayload($product, ['payment_method' => 'cod']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');
    }

    public function test_cash_on_delivery_is_still_allowed_for_pickup(): void
    {
        $product = $this->makeProduct();

        $this->postJson('/api/v1/orders', $this->orderPayload($product, ['payment_method' => 'cod']))->assertCreated();
    }

    /** @dataProvider badPincodes */
    public function test_delivery_pincode_must_be_exactly_six_digits(string $pincode): void
    {
        $this->fakeShiprocket();
        $product = $this->makeProduct();

        $this->postJson('/api/v1/orders', $this->deliveryPayload($product, ['pincode' => $pincode]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('pincode');
    }

    public static function badPincodes(): array
    {
        return [['40001'], ['4000011'], ['012345'], ['40000A'], ['4000 01']];
    }

    /** @dataProvider badPhones */
    public function test_phone_must_be_a_valid_indian_mobile(string $phone): void
    {
        $product = $this->makeProduct();

        $this->postJson('/api/v1/orders', $this->orderPayload($product, ['phone' => $phone]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public static function badPhones(): array
    {
        return [['12345'], ['5876543210'], ['98765'], ['abcdefghij'], ['0000000000']];
    }

    public function test_common_phone_formats_are_accepted(): void
    {
        $product = $this->makeProduct();

        foreach (['9876543210', '+91 98765 43210', '098765-43210'] as $phone) {
            $this->postJson('/api/v1/orders', $this->orderPayload($product, ['phone' => $phone]))->assertCreated();
        }
    }

    public function test_quantity_limits_are_enforced(): void
    {
        $product = $this->makeProduct();

        foreach ([0, -1, 21, 1.5, 'abc'] as $qty) {
            $this->postJson('/api/v1/orders', $this->orderPayload($product, [
                'items' => [['product_slug' => $product->slug, 'size_label' => '0.5 kg', 'quantity' => $qty]],
            ]))->assertStatus(422);
        }
    }

    public function test_malformed_and_oversized_input_is_rejected_without_leaking_internals(): void
    {
        $product = $this->makeProduct();

        $response = $this->postJson('/api/v1/orders', $this->orderPayload($product, [
            'first_name' => str_repeat('A', 500),
            'items' => 'not-an-array',
        ]))->assertStatus(422);

        $this->assertStringNotContainsString('vendor', $response->getContent());
        $this->assertStringNotContainsString('Stack trace', $response->getContent());
    }

    // ── Delivery fee: no free threshold, no fallback ──────────────────

    public function test_delivery_fee_is_the_courier_rate_even_for_large_carts(): void
    {
        $this->fakeShiprocket(rate: 180.5);
        $product = $this->asHamper($this->makeProduct(5000));

        // 400001 is more than 30 km away: courier rate, no free-delivery threshold.
        $response = $this->postJson('/api/v1/orders', $this->deliveryPayload($product, ['pincode' => '400001']))->assertCreated();

        $this->assertEquals(5000, $response->json('data.subtotal'));
        $this->assertEquals(180.5, $response->json('data.deliveryFee'));
        $this->assertEquals(5180.5, $response->json('data.total'));
    }

    public function test_delivery_order_is_rejected_when_no_courier_quote_is_available(): void
    {
        config([
            'services.shiprocket.email' => 'api-user@example.test',
            'services.shiprocket.password' => 'x',
            'services.shiprocket.pickup_pincode' => '140603',
        ]);
        Http::fake([
            '*/auth/login' => Http::response(['token' => 'fake-token']),
            '*/courier/serviceability/*' => Http::response(['data' => ['available_courier_companies' => []]]),
        ]);
        $product = $this->asHamper($this->makeProduct());

        // 400001 is more than 30 km away, so it needs a courier quote.
        $this->postJson('/api/v1/orders', $this->deliveryPayload($product, ['pincode' => '400001']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('pincode');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_pickup_orders_have_no_delivery_fee(): void
    {
        $product = $this->makeProduct();

        $this->postJson('/api/v1/orders', $this->orderPayload($product))
            ->assertCreated()
            ->assertJsonPath('data.deliveryFee', 0);
    }

    // ── Idempotency ───────────────────────────────────────────────────

    public function test_duplicate_order_submission_with_same_idempotency_key_creates_one_order(): void
    {
        $product = $this->makeProduct();
        $payload = $this->orderPayload($product);

        $first = $this->withHeader('Idempotency-Key', 'key-123')->postJson('/api/v1/orders', $payload)->assertCreated();
        $second = $this->withHeader('Idempotency-Key', 'key-123')->postJson('/api/v1/orders', $payload);

        $this->assertSame($first->json('data.orderNumber'), $second->json('data.orderNumber'));
        $this->assertDatabaseCount('orders', 1);
    }

    // ── Image upload restrictions ─────────────────────────────────────

    public function test_product_image_upload_rejects_non_image_files(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        [, $token] = $this->actingAsAdmin('manager');
        $product = $this->makeProduct();

        foreach (['shell.php', 'vector.svg', 'doc.pdf'] as $name) {
            $file = \Illuminate\Http\UploadedFile::fake()->create($name, 10, 'application/octet-stream');
            $this->withToken($token)
                ->postJson("/api/v1/admin/products/{$product->id}/images", ['image' => $file])
                ->assertStatus(422);
        }
    }
}
