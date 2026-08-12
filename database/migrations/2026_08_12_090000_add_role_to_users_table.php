<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Reuses the existing `users` table + Sanctum guard for admin auth instead of
// a separate admins table/guard — customers keep `role = null`; anything
// non-null is an admin. Phase 7 admin roles only; no permission table yet
// (kept intentionally simple per the Phase 7 spec).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['super_admin', 'manager', 'staff'])
                ->nullable()
                ->default(null)
                ->after('password');

            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });
    }
};
