<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The storefront hampers are shown without the "Pro" suffix. Display names
 * only; slugs stay so existing carts and orders keep working.
 */
return new class extends Migration
{
    private const NAMES = [
        'classic-hamper-pro' => ['Classic Hamper', 'Classic Hamper Pro'],
        'signature-hamper-pro' => ['Signature Hamper', 'Signature Hamper Pro'],
        'luxe-hamper-pro' => ['Luxe Hamper', 'Luxe Hamper Pro'],
    ];

    public function up(): void
    {
        foreach (self::NAMES as $slug => [$name]) {
            DB::table('products')->where('slug', $slug)->update(['name' => $name]);
        }
    }

    public function down(): void
    {
        foreach (self::NAMES as $slug => [, $old]) {
            DB::table('products')->where('slug', $slug)->update(['name' => $old]);
        }
    }
};
