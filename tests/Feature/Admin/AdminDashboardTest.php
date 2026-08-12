<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUsers;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase, CreatesAdminUsers;

    private function makeOrder(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'GB'.random_int(100000, 999999),
            'guest_name' => 'Test Customer',
            'guest_email' => 'test@example.com',
            'guest_phone' => '9999999999',
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

    public function test_dashboard_returns_expected_statistics_shape(): void
    {
        [, $token] = $this->actingAsAdmin();

        $this->makeOrder(['order_status' => 'new', 'payment_status' => 'paid']);
        $this->makeOrder(['order_status' => 'completed', 'payment_status' => 'paid']);
        $this->makeOrder(['order_status' => 'cancelled']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/dashboard');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'orders' => ['total', 'today', 'new', 'completed', 'cancelled'],
                    'customers' => ['total'],
                    'products' => ['active', 'lowStock', 'outOfStock'],
                    'customCakeRequests' => ['pending'],
                    'enquiries' => ['pending'],
                    'newsletter' => ['subscribers'],
                    'revenue' => ['total', 'today'],
                ],
            ]);

        $response->assertJsonPath('data.orders.total', 3)
            ->assertJsonPath('data.orders.new', 1)
            ->assertJsonPath('data.orders.completed', 1)
            ->assertJsonPath('data.orders.cancelled', 1)
            ->assertJsonPath('data.revenue.total', 998);
    }

    public function test_dashboard_customer_count_excludes_admin_accounts(): void
    {
        [, $token] = $this->actingAsAdmin();
        User::factory()->count(2)->create(); // ordinary customers

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/dashboard');

        $response->assertOk()->assertJsonPath('data.customers.total', 2);
    }

    public function test_customer_cannot_access_dashboard(): void
    {
        $customer = User::factory()->create();
        $token = $this->tokenFor($customer);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/dashboard');

        $response->assertStatus(403);
    }
}
