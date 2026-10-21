<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_admin_with_a_hashed_password(): void
    {
        $this->artisan('admin:create', ['--name' => 'Owner', '--email' => 'owner@example.test'])
            ->expectsQuestion('Password (min 12 characters)', 'a-long-test-password')
            ->expectsQuestion('Confirm password', 'a-long-test-password')
            ->expectsOutput('Created super_admin owner@example.test.')
            ->assertSuccessful();

        $user = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertSame('super_admin', $user->role);
        $this->assertTrue(Hash::check('a-long-test-password', $user->password));
    }

    public function test_it_rejects_short_or_mismatched_passwords_and_bad_roles(): void
    {
        $this->artisan('admin:create', ['--name' => 'A', '--email' => 'a@example.test'])
            ->expectsQuestion('Password (min 12 characters)', 'short')
            ->expectsQuestion('Confirm password', 'short')
            ->assertFailed();

        $this->artisan('admin:create', ['--name' => 'A', '--email' => 'a@example.test'])
            ->expectsQuestion('Password (min 12 characters)', 'a-long-test-password')
            ->expectsQuestion('Confirm password', 'different-password-1')
            ->assertFailed();

        $this->artisan('admin:create', ['--name' => 'A', '--email' => 'a@example.test', '--role' => 'god'])->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
