<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_cake_requests', function (Blueprint $table) {
            $table->id();

            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone');
            $table->string('email')->nullable();

            $table->string('occasion');
            $table->string('other_occasion')->nullable();
            $table->date('occasion_date')->nullable();
            $table->string('preferred_time')->nullable();
            $table->string('custom_time')->nullable();
            $table->unsignedInteger('people_count')->nullable();

            $table->enum('delivery_type', ['delivery', 'pickup']);
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('pincode', 10)->nullable();
            $table->string('landmark')->nullable();

            $table->string('flavour')->nullable();
            $table->string('shape')->nullable();
            $table->string('theme')->nullable();
            $table->text('cake_message')->nullable();
            $table->boolean('eggless')->default(false);
            $table->string('budget')->nullable();
            $table->text('notes')->nullable();
            $table->string('reference_image_path')->nullable();

            $table->enum('status', [
                'new', 'reviewing', 'quoted', 'confirmed', 'rejected',
            ])->default('new');

            $table->timestamps();

            $table->index('status');
            $table->index('occasion_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_cake_requests');
    }
};
