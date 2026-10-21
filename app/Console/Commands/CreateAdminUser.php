<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdminUser extends Command
{
    protected $signature = 'admin:create {--name=} {--email=} {--role=super_admin : super_admin, manager or staff}';

    protected $description = 'Create an admin panel user. The password is asked for interactively, so it never lands in shell history.';

    private const ROLES = ['super_admin', 'manager', 'staff'];

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Name');
        $email = $this->option('email') ?: $this->ask('Email');
        $role = $this->option('role');

        if (! in_array($role, self::ROLES, true)) {
            $this->error('Role must be one of: '.implode(', ', self::ROLES).'.');

            return self::FAILURE;
        }

        $password = $this->secret('Password (min 12 characters)');
        if ($password !== $this->secret('Confirm password')) {
            $this->error('The passwords do not match.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:12'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        User::create(['name' => $name, 'email' => $email, 'password' => $password, 'role' => $role]);

        $this->info("Created {$role} {$email}.");

        return self::SUCCESS;
    }
}
