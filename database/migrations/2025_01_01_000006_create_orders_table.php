<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            // BIGINT auto-increment primary key (per correction #2 — never a string PK).
            $table->id();

            // Separate unique human-readable identifier, e.g. "GB482913".
            $table->string('order_number')->unique();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Guest checkout fields — always populated (even for registered
            // users) so an order is self-contained and readable on its own.
            $table->string('guest_name');
            $table->string('guest_email');
            $table->string('guest_phone');

            $table->enum('delivery_mode', ['delivery', 'pickup']);
            $table->enum('pickup_type', ['dine_in', 'parcel'])->nullable();

            $table->foreignId('address_id')
                ->nullable()
                ->constrained('addresses')
                ->nullOnDelete();

            $table->date('scheduled_date')->nullable();
            $table->time('scheduled_time')->nullable();

            $table->decimal('subtotal', 10, 2);
            $table->decimal('delivery_fee', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
            $table->string('currency', 3)->default('INR');
            $table->enum('payment_method', [
                'online',
                'cod',
            ]);
            $table->enum('payment_status', ['pending', 'paid', 'failed', 'refunded'])
                ->default('pending');
            $table->enum('order_status', [
                'new',
                'confirmed',
                'preparing',
                'ready',
                'out_for_delivery',
                'completed',
                'cancelled',
            ])->default('new');

            // Prevents duplicate order creation from double-submits/retries.
            $table->string('idempotency_key')->nullable()->unique();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index(['payment_status', 'order_status']);
            $table->index('scheduled_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
