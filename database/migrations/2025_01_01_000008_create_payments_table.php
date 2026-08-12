<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            // Razorpay identifiers — needed for reconciliation/support lookups.
            $table->string('razorpay_order_id')->unique();
            $table->string('razorpay_payment_id')->nullable()->unique();

            // HMAC signature of (order_id|payment_id), not a secret itself —
            // kept only long enough to be useful for dispute/audit trails.
            $table->string('razorpay_signature')->nullable();

            $table->enum('status', [
                'created', 'authorized', 'captured', 'failed', 'refunded',
            ])->default('created');

            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('INR');

            // Non-sensitive gateway metadata only — e.g. "card", "upi", "netbanking".
            // Never card numbers, CVV, UPI PIN, or any credential.
            $table->string('method')->nullable();
            $table->string('error_code')->nullable();
            $table->string('error_description')->nullable();

            $table->timestamps();

            $table->index('order_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
