<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSize;
use App\Services\DeliveryFeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Delivery charge by zone:
 *
 *   hampers   up to 10 km   10 to 30 km   more than 30 km
 *   1-2       Rs 100        Rs 150
 *   3-4       Rs 150        Rs 250        Shiprocket courier rate
 *   5+        Rs 250        Rs 500
 */
class DeliveryChargeTest extends TestCase
{
    use \Tests\Concerns\FakesShiprocket;
    use \Tests\Concerns\MakesHampers;
    use RefreshDatabase;

    private const NEAR = '140603';

    private const MID = '160059';

    private const FAR = '400001';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.delivery.near_pincodes' => [self::NEAR],
            'services.delivery.mid_pincodes' => [self::MID, '1342*'],
            // No courier configured unless a test calls fakeShiprocket(): the estimated date is just absent.
            'services.shiprocket.email' => null,
            'razorpay.key_id' => 'rzp_test_public_key',
            'razorpay.key_secret' => 'test_key_secret',
        ]);
    }

    private function hamper(float $price = 1000): Product
    {
        $product = Product::factory()->create([
            'category_id' => Category::factory()->create()->id,
            'base_price' => $price,
        ]);
        ProductSize::factory()->create(['product_id' => $product->id, 'label' => 'Box', 'price' => $price]);
        ProductImage::factory()->create(['product_id' => $product->id, 'is_primary' => true]);

        return $this->asHamper($product);
    }

    private function cart(Product $product, int $qty): array
    {
        return [['product_slug' => $product->slug, 'size_label' => 'Box', 'quantity' => $qty]];
    }

    private function validate(Product $product, int $qty, string $pincode)
    {
        return $this->postJson('/api/v1/cart/validate', [
            'delivery_mode' => 'delivery',
            'pincode' => $pincode,
            'items' => $this->cart($product, $qty),
        ]);
    }

    private function orderPayload(Product $product, int $qty, string $pincode, array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Priya',
            'last_name' => 'Sharma',
            'phone' => '9876543210',
            'delivery_mode' => 'delivery',
            'recipient_first_name' => 'Anita', 'recipient_last_name' => 'Verma', 'recipient_phone' => '9811122233',
            'address_line_1' => '221B Baker Street',
            'city' => 'Mohali',
            'state' => 'Punjab',
            'pincode' => $pincode,
            'scheduled_date' => now()->addDays(3)->format('Y-m-d'),
            'payment_method' => 'online',
            'items' => $this->cart($product, $qty),
        ], $overrides);
    }

    /** @return array<string, array{string, int, int|float}> */
    public static function zoneTable(): array
    {
        return [
            'within 10 km, 1 hamper' => [self::NEAR, 1, 100],
            'within 10 km, 2 hampers' => [self::NEAR, 2, 100],
            'within 10 km, 3 hampers' => [self::NEAR, 3, 150],
            'within 10 km, 4 hampers' => [self::NEAR, 4, 150],
            'within 10 km, 5 hampers' => [self::NEAR, 5, 250],
            'within 10 km, 8 hampers' => [self::NEAR, 8, 250],
            '10 to 30 km, 1 hamper' => [self::MID, 1, 150],
            '10 to 30 km, 2 hampers' => [self::MID, 2, 150],
            '10 to 30 km, 3 hampers' => [self::MID, 3, 250],
            '10 to 30 km, 4 hampers' => [self::MID, 4, 250],
            '10 to 30 km, 5 hampers' => [self::MID, 5, 500],
            '10 to 30 km, 8 hampers' => [self::MID, 8, 500],
            '10 to 30 km, listed by prefix' => ['134205', 1, 150],
            // Beyond 30 km the courier rate (Rs 180.5 in these tests) applies whatever the quantity.
            'over 30 km, 1 hamper' => [self::FAR, 1, 180.5],
            'over 30 km, 4 hampers' => [self::FAR, 4, 180.5],
            'over 30 km, 8 hampers' => [self::FAR, 8, 180.5],
        ];
    }

    /** @dataProvider zoneTable */
    public function test_cart_validate_charges_the_zone_rate(string $pincode, int $qty, int|float $fee): void
    {
        $this->fakeShiprocket(rate: 180.5);
        $this->config_zones();
        $product = $this->hamper(1000);

        $this->validate($product, $qty, $pincode)
            ->assertOk()
            ->assertJsonPath('data.deliveryFee', $fee)
            ->assertJsonPath('data.total', 1000 * $qty + $fee);
    }

    /** @dataProvider zoneTable */
    public function test_order_total_and_payment_amount_match_the_checkout_total(string $pincode, int $qty, int|float $fee): void
    {
        $this->fakeShiprocket(rate: 180.5);
        $this->config_zones();
        $product = $this->hamper(1000);
        $expected = 1000 * $qty + $fee;

        // What checkout shows ...
        $shown = $this->validate($product, $qty, $pincode)->assertOk()->json('data.total');

        // ... what the backend stores ...
        $order = $this->postJson('/api/v1/orders', $this->orderPayload($product, $qty, $pincode))
            ->assertCreated()
            ->assertJsonPath('data.deliveryFee', $fee)
            ->assertJsonPath('data.total', $expected);

        $this->assertEquals($expected, $shown);
        $this->assertEquals($expected, Order::where('order_number', $order->json('data.orderNumber'))->value('total'));

        // ... and what Razorpay is asked to charge.
        Http::fake(['api.razorpay.com/*' => Http::response([
            'id' => 'order_RZP1', 'entity' => 'order', 'amount' => (int) round($expected * 100), 'currency' => 'INR', 'status' => 'created',
        ])]);

        $this->postJson('/api/v1/payments/create-order', ['order_number' => $order->json('data.orderNumber')])
            ->assertOk()
            ->assertJsonPath('data.amount', $expected);

        Http::assertSent(fn ($request) => ($request->data()['amount'] ?? null) === (int) round($expected * 100));
    }

    /** fakeShiprocket() resets the zone lists; put the test's zones back. */
    private function config_zones(): void
    {
        config([
            'services.delivery.near_pincodes' => [self::NEAR],
            'services.delivery.mid_pincodes' => [self::MID, '1342*'],
        ]);
    }

    public function test_changing_the_hamper_quantity_changes_the_charge(): void
    {
        $product = $this->hamper();
        $fees = [];

        foreach ([2, 3, 4, 5] as $qty) {
            $fees[$qty] = $this->validate($product, $qty, self::MID)->json('data.deliveryFee');
        }

        $this->assertEquals([2 => 150, 3 => 250, 4 => 250, 5 => 500], $fees);
    }

    public function test_the_charge_follows_the_delivery_pincode(): void
    {
        $this->fakeShiprocket(rate: 180.5);
        $this->config_zones();
        $product = $this->hamper();

        foreach ([self::NEAR => 100, self::MID => 150, self::FAR => 180.5, '110001' => 180.5] as $pincode => $fee) {
            $this->validate($product, 1, (string) $pincode)->assertOk()->assertJsonPath('data.deliveryFee', $fee);
        }
    }

    public function test_near_and_mid_pincodes_do_not_need_the_courier(): void
    {
        Http::fake(); // anything that reached Shiprocket would be recorded
        $product = $this->hamper();

        $this->validate($product, 2, self::NEAR)->assertOk()->assertJsonPath('data.deliveryFee', 100);
        $this->validate($product, 2, self::MID)->assertOk()->assertJsonPath('data.deliveryFee', 150);

        Http::assertNothingSent();
    }

    public function test_the_default_mid_zone_list_covers_the_tricity(): void
    {
        config(['services.delivery.mid_pincodes' => []]);
        $product = $this->hamper();

        $this->validate($product, 1, '160017')->assertOk()->assertJsonPath('data.deliveryFee', 150); // Chandigarh
        $this->validate($product, 1, '134109')->assertOk()->assertJsonPath('data.deliveryFee', 150); // Panchkula
    }

    public function test_a_far_pincode_without_a_courier_quote_is_rejected_not_guessed(): void
    {
        $product = $this->hamper();

        // Shiprocket isn't configured here, so there is no courier rate for a far pincode.
        $this->validate($product, 1, self::FAR)->assertStatus(422)->assertJsonValidationErrors('pincode');
        $this->postJson('/api/v1/orders', $this->orderPayload($product, 1, self::FAR))
            ->assertStatus(422)
            ->assertJsonValidationErrors('pincode');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_the_rate_table_boundaries(): void
    {
        $this->assertSame(100.0, DeliveryFeeService::feeFor(true, 0)); // never below the 1-2 band
        $this->assertSame(100.0, DeliveryFeeService::feeFor(true, 2));
        $this->assertSame(150.0, DeliveryFeeService::feeFor(true, 3));
        $this->assertSame(150.0, DeliveryFeeService::feeFor(true, 4));
        $this->assertSame(250.0, DeliveryFeeService::feeFor(true, 5));
        $this->assertSame(150.0, DeliveryFeeService::feeFor(false, 2));
        $this->assertSame(250.0, DeliveryFeeService::feeFor(false, 4));
        $this->assertSame(500.0, DeliveryFeeService::feeFor(false, 5));
    }

    /** @dataProvider unverifiablePincodes */
    public function test_a_pincode_that_cannot_be_verified_is_rejected_not_guessed(string $pincode): void
    {
        $this->fakeShiprocket(rate: 180.5);
        $this->config_zones();
        $product = $this->hamper();

        $this->validate($product, 1, $pincode)->assertStatus(422)->assertJsonValidationErrors('pincode');

        $this->postJson('/api/v1/orders', $this->orderPayload($product, 1, $pincode))
            ->assertStatus(422)
            ->assertJsonValidationErrors('pincode');

        $this->assertDatabaseCount('orders', 0);
    }

    /** @return array<string, array{string}> */
    public static function unverifiablePincodes(): array
    {
        return [
            'not a real pincode' => ['999999'],
            'too short' => ['14060'],
            'letters' => ['abcdef'],
            'starts with zero' => ['012345'],
        ];
    }

    public function test_delivery_without_a_pincode_is_rejected(): void
    {
        $product = $this->hamper();

        $this->postJson('/api/v1/cart/validate', [
            'delivery_mode' => 'delivery', 'items' => $this->cart($product, 1),
        ])->assertStatus(422);
    }

    public function test_pickup_has_no_delivery_charge(): void
    {
        $product = $this->hamper();

        $this->postJson('/api/v1/cart/validate', [
            'delivery_mode' => 'pickup', 'items' => $this->cart($product, 3),
        ])->assertOk()->assertJsonPath('data.deliveryFee', 0);
    }
}
