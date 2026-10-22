<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Single counter row behind order numbers like GB0000001 (existing orders keep their old numbers).
        Schema::create('order_number_sequence', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedInteger('last_number')->default(0);
        });

        DB::table('order_number_sequence')->insert(['id' => 1, 'last_number' => 0]);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_number_sequence');
    }
};
