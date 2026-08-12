<?php

namespace Tests\Feature\Admin;

use App\Models\Enquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUsers;
use Tests\TestCase;

class AdminEnquiryTest extends TestCase
{
    use RefreshDatabase, CreatesAdminUsers;

    private function makeEnquiry(array $attrs = []): Enquiry
    {
        return Enquiry::create(array_merge([
            'name' => 'Priya Sharma',
            'email' => 'priya@example.com',
            'message' => 'Do you deliver to Andheri?',
            'status' => 'new',
        ], $attrs));
    }

    public function test_manager_can_list_enquiries(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $this->makeEnquiry();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/enquiries');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_staff_can_view_but_not_update_status(): void
    {
        [, $token] = $this->actingAsAdmin('staff');
        $enquiry = $this->makeEnquiry();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/admin/enquiries/{$enquiry->id}")
            ->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/admin/enquiries/{$enquiry->id}/status", ['status' => 'resolved'])
            ->assertStatus(403);
    }

    public function test_manager_can_update_status(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $enquiry = $this->makeEnquiry();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/admin/enquiries/{$enquiry->id}/status", ['status' => 'resolved']);

        $response->assertOk()->assertJsonPath('data.status', 'resolved');
    }

    public function test_customer_cannot_access_enquiry_admin_endpoints(): void
    {
        $customer = User::factory()->create();
        $token = $this->tokenFor($customer);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/enquiries')
            ->assertStatus(403);
    }
}
