<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One counter row per (Indian) calendar year; invoice numbers look like GB001/2026.
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('invoice_number', 20)->nullable()->unique();
        });

        // Number the existing orders in the order they were placed.
        $counters = [];
        DB::table('orders')->orderBy('created_at')->orderBy('id')->get(['id', 'created_at'])->each(function ($order) use (&$counters) {
            $year = (int) \Illuminate\Support\Carbon::parse($order->created_at, 'UTC')->setTimezone('Asia/Kolkata')->format('Y');
            $counters[$year] = ($counters[$year] ?? 0) + 1;

            DB::table('orders')->where('id', $order->id)->update([
                'invoice_number' => sprintf('GB%03d/%d', $counters[$year], $year),
            ]);
        });

        foreach ($counters as $year => $last) {
            DB::table('invoice_sequences')->insert(['year' => $year, 'last_number' => $last]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('invoice_number');
        });

        Schema::dropIfExists('invoice_sequences');
    }
};
