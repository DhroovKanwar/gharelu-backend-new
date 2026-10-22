<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private function loginToken(User $admin, string $password): string
    {
        return $this->postJson('/api/v1/admin/auth/login', [
            'email' => $admin->email,
            'password' => $password,
        ])->assertOk()->json('data.token');
    }

    public function test_admin_can_change_password_and_other_sessions_are_signed_out(): void
    {
        $admin = User::factory()->superAdmin()->create(['password' => 'old-password-123']);
        $current = $this->loginToken($admin, 'old-password-123');
        $other = $this->loginToken($admin, 'old-password-123');

        $this->withToken($current)->postJson('/api/v1/admin/auth/password', [
            'current_password' => 'old-password-123',
            'password' => 'brand-new-password-1',
            'password_confirmation' => 'brand-new-password-1',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('brand-new-password-1', $admin->fresh()->password));
        $this->assertSame(1, $admin->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($current)->getJson('/api/v1/admin/auth/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
    }

    public function test_it_rejects_a_wrong_current_password_a_short_or_unconfirmed_new_one_and_a_reused_one(): void
    {
        $admin = User::factory()->superAdmin()->create(['password' => 'old-password-123']);
        $token = $this->loginToken($admin, 'old-password-123');

        $cases = [
            ['current_password' => 'nope-nope-nope', 'password' => 'brand-new-password-1', 'password_confirmation' => 'brand-new-password-1'],
            ['current_password' => 'old-password-123', 'password' => 'short', 'password_confirmation' => 'short'],
            ['current_password' => 'old-password-123', 'password' => 'brand-new-password-1', 'password_confirmation' => 'different-password-1'],
            ['current_password' => 'old-password-123', 'password' => 'old-password-123', 'password_confirmation' => 'old-password-123'],
        ];

        foreach ($cases as $payload) {
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->postJson('/api/v1/admin/auth/password', $payload)->assertStatus(422);
        }

        $this->assertTrue(Hash::check('old-password-123', $admin->fresh()->password));
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/v1/admin/auth/password', [])->assertUnauthorized();
    }
}
