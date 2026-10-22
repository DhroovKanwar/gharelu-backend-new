<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit trail for payments an admin marked as paid by hand (money reached
        // the gateway but the order never got updated). Who, when, why, which payment.
        Schema::create('order_payment_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 16);
            $table->string('to_status', 16);
            $table->string('razorpay_payment_id', 64)->nullable();
            $table->string('remarks', 500);
            $table->timestamp('created_at')->useCurrent();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payment_adjustments');
    }
};
