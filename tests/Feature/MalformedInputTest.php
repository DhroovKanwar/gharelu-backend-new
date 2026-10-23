<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wrong-typed input (arrays, objects, huge numbers, odd text) must be answered with a plain
 * 4xx error, never a 500 and never any internal detail.
 */
class MalformedInputTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string, array}> */
    public static function badRequests(): array
    {
        $junk = [
            'array as value' => ['x'],
            'object as value' => ['a' => 1],
            'huge number' => 99999999999999999999,
            'nested array' => [['x']],
            'sql-ish text' => "' OR 1=1 --",
            'null byte' => "a\0b",
        ];

        $cases = [];
        foreach ($junk as $label => $value) {
            $cases["orders / every field: {$label}"] = ['POST', '/api/v1/orders', array_fill_keys(
                ['first_name', 'last_name', 'email', 'phone', 'delivery_mode', 'pickup_type', 'address_line_1', 'city', 'state', 'pincode', 'scheduled_date', 'scheduled_time', 'payment_method', 'notes', 'items', 'idempotency_key'],
                $value,
            )];
            $cases["orders / phone only: {$label}"] = ['POST', '/api/v1/orders', ['phone' => $value]];
            $cases["cart validate: {$label}"] = ['POST', '/api/v1/cart/validate', ['delivery_mode' => $value, 'pincode' => $value, 'items' => $value]];
            $cases["order lookup: {$label}"] = ['POST', '/api/v1/orders/lookup', ['phone' => $value]];
            $cases["payment create: {$label}"] = ['POST', '/api/v1/payments/create-order', ['order_number' => $value]];
            $cases["payment verify: {$label}"] = ['POST', '/api/v1/payments/verify', ['razorpay_order_id' => $value, 'razorpay_payment_id' => $value, 'razorpay_signature' => $value]];
            $cases["enquiry: {$label}"] = ['POST', '/api/v1/enquiries', ['name' => $value, 'email' => $value, 'phone' => $value, 'message' => $value, 'source_page' => $value]];
            $cases["newsletter: {$label}"] = ['POST', '/api/v1/newsletter/subscribe', ['email' => $value]];
            $cases["analytics: {$label}"] = ['POST', '/api/v1/track', ['visitor_id' => $value, 'events' => $value]];
        }

        return $cases;
    }

    /** @dataProvider badRequests */
    public function test_malformed_input_gets_a_plain_4xx_never_a_500(string $method, string $uri, array $body): void
    {
        $response = $this->json($method, $uri, $body);

        $this->assertLessThan(500, $response->status(), "{$uri} answered {$response->status()}");

        $content = $response->getContent();
        foreach (['Stack trace', 'vendor/', '.php', 'SQLSTATE', 'Illuminate'] as $leak) {
            $this->assertStringNotContainsString($leak, (string) $content);
        }
    }
}
