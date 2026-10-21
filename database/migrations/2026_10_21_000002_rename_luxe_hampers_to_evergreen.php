<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Luxe Hamper is now sold as the Evergreen Hamper (Luxe Hamper Pro keeps its name). Only the display names and
 * description wording change; slugs stay so existing carts and orders keep
 * working.
 */
return new class extends Migration
{
    private const NAMES = [
        'luxe-hamper' => 'Evergreen Hamper',
    ];

    public function up(): void
    {
        foreach (self::NAMES as $slug => $name) {
            DB::table('products')->where('slug', $slug)->update([
                'name' => $name,
                'description' => DB::raw("REPLACE(description, 'Luxe', 'Evergreen')"),
                'long_description' => DB::raw("REPLACE(long_description, 'Luxe', 'Evergreen')"),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('products')->where('slug', 'luxe-hamper')->update(['name' => 'Luxe Hamper']);
    }
};
