<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_logs', function (Blueprint $table) {
            $table->id();

            $table->string('event_type');

            // Unique per Razorpay event — the backbone of idempotent webhook
            // processing (a re-delivered webhook is a no-op on replay).
            $table->string('razorpay_event_id')->unique();

            // Minimal payload only: Razorpay webhook bodies don't contain
            // card/UPI credentials, but we still avoid storing anything
            // beyond what reconciliation/debugging actually needs.
            $table->json('payload');

            $table->boolean('signature_valid')->default(false);
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_logs');
    }
};
