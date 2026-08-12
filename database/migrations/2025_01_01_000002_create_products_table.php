<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')
                ->constrained('categories')
                ->restrictOnDelete();

            $table->string('slug')->unique();
            $table->string('name');
            $table->string('collection')->nullable();
            $table->text('description')->nullable();
            $table->longText('long_description')->nullable();
            $table->string('flavour')->nullable();

            $table->decimal('base_price', 10, 2);
            $table->decimal('old_price', 10, 2)->nullable();

            // JSON columns per approved architecture — avoids extra
            // normalized tables for simple, admin-rarely-edited lists.
            $table->json('ingredients')->nullable();
            $table->json('allergens')->nullable();

            $table->decimal('rating_cached', 3, 2)->default(0);
            $table->unsignedInteger('reviews_count_cached')->default(0);

            $table->boolean('is_featured')->default(false);
            $table->boolean('is_bestseller')->default(false);
            $table->boolean('is_active')->default(true);
            $table->enum('stock_status', ['in_stock', 'low_stock', 'out_of_stock'])
                ->default('in_stock');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'is_featured']);
            $table->index(['is_active', 'is_bestseller']);
            $table->index('stock_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
