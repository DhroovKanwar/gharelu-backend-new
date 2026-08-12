<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUsers;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase, CreatesAdminUsers;

    // ── auth ──────────────────────────────────────────────────────────

    public function test_admin_can_login(): void
    {
        $admin = User::factory()->superAdmin()->create(['password' => bcrypt('correct-password')]);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'correct-password',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.role', 'super_admin')
            ->assertJsonStructure(['data' => ['user', 'token']]);
    }

    public function test_admin_login_rejects_wrong_password(): void
    {
        $admin = User::factory()->superAdmin()->create(['password' => bcrypt('correct-password')]);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
    }

    public function test_admin_login_rejects_non_admin_account(): void
    {
        $customer = User::factory()->create(['password' => bcrypt('correct-password')]);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => $customer->email,
            'password' => 'correct-password',
        ]);

        $response->assertStatus(422);
    }

    public function test_admin_login_is_rate_limited(): void
    {
        $admin = User::factory()->superAdmin()->create(['password' => bcrypt('correct-password')]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/admin/auth/login', [
                'email' => $admin->email,
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429);
    }

    public function test_admin_can_logout(): void
    {
        [, $token] = $this->actingAsAdmin();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/auth/logout');

        $response->assertOk()->assertJsonPath('success', true);
    }

    public function test_admin_can_fetch_profile(): void
    {
        [$admin, $token] = $this->actingAsAdmin('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/auth/me');

        $response->assertOk()
            ->assertJsonPath('data.email', $admin->email)
            ->assertJsonPath('data.role', 'manager');

        $this->assertArrayNotHasKey('password', $response->json('data'));
    }

    public function test_customer_token_cannot_access_admin_routes(): void
    {
        $customer = User::factory()->create();
        $token = $this->tokenFor($customer);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/dashboard');

        $response->assertStatus(403);
    }

    public function test_unauthenticated_request_to_admin_routes_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/admin/dashboard');

        $response->assertStatus(401);
    }

    // ── roles ─────────────────────────────────────────────────────────

    public function test_super_admin_can_access_dashboard_and_products(): void
    {
        [, $token] = $this->actingAsAdmin('super_admin');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/dashboard')->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/products')->assertOk();
    }

    public function test_manager_can_access_dashboard_and_products(): void
    {
        [, $token] = $this->actingAsAdmin('manager');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/dashboard')->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/products')->assertOk();
    }

    public function test_staff_can_access_orders_but_not_dashboard_or_products(): void
    {
        [, $token] = $this->actingAsAdmin('staff');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/orders')->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/dashboard')->assertStatus(403);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/products')->assertStatus(403);
    }

    public function test_staff_cannot_perform_manager_only_operations(): void
    {
        // Privilege escalation check: staff can view enquiries but must not
        // be able to change their status (manager+ only).
        [, $token] = $this->actingAsAdmin('staff');
        $enquiry = \App\Models\Enquiry::create([
            'name' => 'Test', 'email' => 'test@example.com', 'message' => 'Hi', 'status' => 'new',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/admin/enquiries/{$enquiry->id}/status", ['status' => 'resolved']);

        $response->assertStatus(403);
    }

    public function test_unauthorized_role_is_rejected(): void
    {
        // A user row with no role at all (ordinary customer) hitting an
        // admin-only endpoint directly.
        $customer = User::factory()->create();
        $token = $this->tokenFor($customer);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/orders');

        $response->assertStatus(403);
    }
}
