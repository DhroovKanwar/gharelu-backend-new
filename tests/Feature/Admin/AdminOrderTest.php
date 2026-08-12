<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUsers;
use Tests\TestCase;

class AdminOrderTest extends TestCase
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
            'payment_method' => 'cod',
            'payment_status' => 'pending',
            'order_status' => 'new',
        ], $attrs));
    }

    public function test_admin_can_list_orders_with_pagination(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $this->makeOrder();
        $this->makeOrder();
        $this->makeOrder();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/orders?per_page=1');

        $response->assertOk()->assertJsonStructure([
            'data', 'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_admin_can_filter_orders_by_status(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $this->makeOrder(['order_status' => 'new']);
        $this->makeOrder(['order_status' => 'completed']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/orders?order_status=completed');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('completed', $response->json('data.0.status'));
    }

    public function test_admin_can_search_orders_by_order_number(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $order = $this->makeOrder(['order_number' => 'GB555555']);
        $this->makeOrder(['order_number' => 'GB111111']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/orders?search=555555');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($order->order_number, $response->json('data.0.orderNumber'));
    }

    public function test_admin_can_view_single_order(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $order = $this->makeOrder();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/admin/orders/{$order->id}");

        $response->assertOk()->assertJsonPath('data.orderNumber', $order->order_number);
    }

    public function test_admin_can_update_order_status(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $order = $this->makeOrder(['order_status' => 'new']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", ['order_status' => 'confirmed']);

        $response->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'order_status' => 'confirmed']);
    }

    public function test_invalid_order_status_is_rejected(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $order = $this->makeOrder();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", ['order_status' => 'not-a-real-status']);

        $response->assertStatus(422);
    }

    public function test_admin_cannot_manually_set_payment_status_via_status_update(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $order = $this->makeOrder(['payment_status' => 'pending']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", [
                'order_status' => 'confirmed',
                'payment_status' => 'paid',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'payment_status' => 'pending']);
    }

    public function test_staff_can_view_and_update_orders(): void
    {
        [, $token] = $this->actingAsAdmin('staff');
        $order = $this->makeOrder();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/orders')->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", ['order_status' => 'confirmed'])
            ->assertOk();
    }

    public function test_customer_cannot_access_admin_order_endpoints(): void
    {
        $order = $this->makeOrder();
        $customer = User::factory()->create();
        $token = $this->tokenFor($customer);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertStatus(403);
    }
}
