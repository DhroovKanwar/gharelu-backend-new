<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration for the hamper catalogue (the storefront uses "-pro" slugs):
 *  - renames the legacy "-max" hampers to "-pro" (skipped when the target
 *    slug already exists, so it can never violate the unique index), and
 *  - sets the net weight (grams) of the six hampers where it is still NULL,
 *    so an admin-edited weight is never overwritten.
 *
 * Idempotent: running it again changes nothing. Existing orders reference
 * products by id, so they are unaffected by the rename.
 */
return new class extends Migration
{
    private const RENAMES = [
        'classic-hamper-max' => ['classic-hamper-pro', 'Classic Hamper Pro'],
        'signature-hamper-max' => ['signature-hamper-pro', 'Signature Hamper Pro'],
        'luxe-hamper-max' => ['luxe-hamper-pro', 'Luxe Hamper Pro'],
    ];

    private const WEIGHTS = [
        'classic-hamper' => 1000,
        'classic-hamper-pro' => 1000,
        'signature-hamper' => 500,
        'signature-hamper-pro' => 500,
        'luxe-hamper' => 950,
        'luxe-hamper-pro' => 950,
    ];

    public function up(): void
    {
        DB::transaction(function () {
            foreach (self::RENAMES as $old => [$new, $name]) {
                if (DB::table('products')->where('slug', $new)->exists()) {
                    continue;
                }

                DB::table('products')->where('slug', $old)->update(['slug' => $new, 'name' => $name]);
            }

            foreach (self::WEIGHTS as $slug => $grams) {
                DB::table('products')->where('slug', $slug)->whereNull('weight_grams')->update(['weight_grams' => $grams]);
            }
        });
    }

    public function down(): void
    {
        // Intentionally a no-op: reverting could collide with slugs created
        // afterwards, and the weights are harmless to keep.
    }
};
