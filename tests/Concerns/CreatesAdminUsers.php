<?php

namespace Tests\Concerns;

use App\Models\User;

trait CreatesAdminUsers
{
    private function makeAdmin(string $role = 'super_admin'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function actingAsAdmin(string $role = 'super_admin'): array
    {
        $admin = $this->makeAdmin($role);

        return [$admin, $this->tokenFor($admin)];
    }
}
