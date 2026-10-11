<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Set once the order has been created in Shiprocket.
            $table->string('shiprocket_order_id')->nullable()->index();
            $table->string('shiprocket_shipment_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['shiprocket_order_id', 'shiprocket_shipment_id']);
        });
    }
};
