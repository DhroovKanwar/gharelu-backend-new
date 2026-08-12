<?php

namespace Tests\Feature\Admin;

use App\Models\CustomCakeRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUsers;
use Tests\TestCase;

class AdminCustomCakeRequestTest extends TestCase
{
    use RefreshDatabase, CreatesAdminUsers;

    private function makeRequest(array $attrs = []): CustomCakeRequest
    {
        return CustomCakeRequest::create(array_merge([
            'first_name' => 'Priya',
            'last_name' => 'Sharma',
            'phone' => '9876543210',
            'occasion' => 'Birthday',
            'delivery_type' => 'pickup',
            'status' => 'new',
        ], $attrs));
    }

    public function test_manager_can_list_custom_cake_requests(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $this->makeRequest();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/custom-cake-requests');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_staff_can_view_but_not_update_status(): void
    {
        [, $token] = $this->actingAsAdmin('staff');
        $cakeRequest = $this->makeRequest();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/admin/custom-cake-requests/{$cakeRequest->id}")
            ->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/admin/custom-cake-requests/{$cakeRequest->id}/status", ['status' => 'reviewing'])
            ->assertStatus(403);
    }

    public function test_manager_can_update_status(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $cakeRequest = $this->makeRequest();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/admin/custom-cake-requests/{$cakeRequest->id}/status", ['status' => 'quoted']);

        $response->assertOk()->assertJsonPath('data.status', 'quoted');
    }

    public function test_invalid_status_is_rejected(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $cakeRequest = $this->makeRequest();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/admin/custom-cake-requests/{$cakeRequest->id}/status", ['status' => 'not-real']);

        $response->assertStatus(422);
    }

    public function test_customer_cannot_access_custom_cake_admin_endpoints(): void
    {
        $customer = User::factory()->create();
        $token = $this->tokenFor($customer);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/custom-cake-requests')
            ->assertStatus(403);
    }
}
