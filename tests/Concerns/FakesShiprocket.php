<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Http;

trait FakesShiprocket
{
    /** Configures Shiprocket credentials and fakes its HTTP API (no real calls). */
    protected function fakeShiprocket(float $rate = 120.0): void
    {
        config([
            'services.shiprocket.email' => 'api-user@example.test',
            'services.shiprocket.password' => 'not-a-real-password',
            'services.shiprocket.pickup_pincode' => '140603',
        ]);

        Http::fake([
            '*/auth/login' => Http::response(['token' => 'fake-token']),
            '*/courier/serviceability/*' => Http::response([
                'data' => ['available_courier_companies' => [
                    ['rate' => $rate, 'courier_name' => 'Test Courier', 'etd' => 'Oct 20, 2026'],
                ]],
            ]),
            '*/orders/create/adhoc' => Http::response(['order_id' => 111, 'shipment_id' => 222]),
        ]);
    }
}
