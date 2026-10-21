<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The plain Classic and Signature hampers are no longer sold (the Pro versions stay). They are deactivated,
 * not deleted, because past orders reference them.
 */
return new class extends Migration
{
    private const SLUGS = [
        'classic-hamper',
        'signature-hamper',
    ];

    public function up(): void
    {
        DB::table('products')->whereIn('slug', self::SLUGS)->update(['is_active' => false]);
    }

    public function down(): void
    {
        DB::table('products')->whereIn('slug', self::SLUGS)->update(['is_active' => true]);
    }
};
