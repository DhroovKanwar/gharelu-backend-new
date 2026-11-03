<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Order;
use App\Services\ShiprocketOrderSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A cached Shiprocket token that has been blacklisted must not block order pushes for days. */
class ShiprocketTokenRetryTest extends TestCase
{
    use RefreshDatabase;

    /** Credentials only: each test registers its own Http fakes (the first matching fake wins). */
    private function configureShiprocket(): void
    {
        config([
            'services.shiprocket.email' => 'api-user@example.test',
            'services.shiprocket.password' => 'not-a-real-password',
            'services.shiprocket.pickup_pincode' => '140603',
        ]);
    }

    private function paidDeliveryOrder(): Order
    {
        $order = Order::create([
            'order_number' => 'GBRETRY'.random_int(10, 99),
            'guest_name' => 'Test Customer',
            'guest_email' => 'customer@example.com',
            'guest_phone' => '9123456789',
            'delivery_mode' => 'delivery',
            'scheduled_date' => now()->addDay()->format('Y-m-d'),
            'scheduled_time' => '10:00:00',
            'subtotal' => 499,
            'delivery_fee' => 0,
            'total' => 499,
            'currency' => 'INR',
            'payment_method' => 'online',
            'payment_status' => 'paid',
            'order_status' => 'confirmed',
        ]);
        $order->address()->associate(Address::factory()->create())->save();

        return $order;
    }

    public function test_blacklisted_cached_token_is_replaced_and_the_push_is_retried(): void
    {
        $this->configureShiprocket();
        Cache::put('shiprocket.token', 'stale-blacklisted-token', now()->addDays(8));

        Http::fake([
            '*/auth/login' => Http::response(['token' => 'fresh-token']),
            '*/orders/create/adhoc' => Http::sequence()
                ->push(['message' => 'The token has been blacklisted', 'status_code' => 401], 401)
                ->push(['order_id' => 777, 'shipment_id' => 888]),
        ]);

        $order = $this->paidDeliveryOrder();

        $this->assertTrue(app(ShiprocketOrderSync::class)->push($order));
        $this->assertSame('777', $order->fresh()->shiprocket_order_id);
        $this->assertSame('fresh-token', Cache::get('shiprocket.token'));

        $creates = Http::recorded(fn ($request) => str_contains($request->url(), 'orders/create/adhoc'));
        $this->assertCount(2, $creates);
    }

    public function test_a_second_401_gives_up_without_looping(): void
    {
        $this->configureShiprocket();

        Http::fake([
            '*/auth/login' => Http::response(['token' => 'fresh-token']),
            '*/orders/create/adhoc' => Http::response(['message' => 'Unauthenticated.'], 401),
        ]);

        $order = $this->paidDeliveryOrder();

        $this->assertFalse(app(ShiprocketOrderSync::class)->push($order));
        $this->assertNull($order->fresh()->shiprocket_order_id);

        $creates = Http::recorded(fn ($request) => str_contains($request->url(), 'orders/create/adhoc'));
        $this->assertCount(2, $creates);
    }
}
