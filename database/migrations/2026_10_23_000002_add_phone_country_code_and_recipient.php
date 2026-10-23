<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // The sender's phone is stored without the country code (guest_phone); this is the code, e.g. +91.
            $table->string('guest_phone_country_code', 8)->default('+91');
        });

        Schema::table('addresses', function (Blueprint $table) {
            // Who a delivery is for, when that is not the person placing the order (gifts).
            $table->string('recipient_name', 120)->nullable();
            $table->string('recipient_phone', 20)->nullable();
            $table->string('recipient_phone_country_code', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropColumn(['recipient_name', 'recipient_phone', 'recipient_phone_country_code']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('guest_phone_country_code');
        });
    }
};
