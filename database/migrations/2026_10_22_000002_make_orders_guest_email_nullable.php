<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('guest_email')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Not reversible without inventing emails for orders that have none.
    }
};
