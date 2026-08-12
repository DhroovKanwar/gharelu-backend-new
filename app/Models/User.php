<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    // `role` is null for every ordinary customer — any non-null value is an
    // admin. Kept as a single column rather than a separate admins table/guard
    // so admin auth can reuse the existing Sanctum setup.
    public function isAdmin(): bool
    {
        return $this->role !== null;
    }

    public function hasAnyRole(string ...$roles): bool
    {
        return $this->role !== null && in_array($this->role, $roles, true);
    }
}
