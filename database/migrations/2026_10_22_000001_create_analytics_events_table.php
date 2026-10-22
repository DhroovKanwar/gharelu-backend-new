<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Anonymous storefront analytics: a random browser id, no IP, no user link.
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->string('visitor_id', 64);
            $table->string('type', 16); // pageview | click
            $table->string('label', 120); // page path for pageviews, button/link text for clicks
            $table->string('path', 255);
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['visitor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
    }
};
