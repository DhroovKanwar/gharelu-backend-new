<?php

namespace App\Services;

/**
 * Which delivery zone a pincode is in, measured from the shop:
 *
 *   near  within 10 km (inclusive)   flat table, left column
 *   mid   10 to 30 km                flat table, right column
 *   far   more than 30 km            courier (Shiprocket) rate
 *
 * No distance API is used: the shop keeps lists of its nearby pincodes. A pincode on the
 * near list is "near", one on the mid list is "mid", and every other real pincode is "far".
 * A pincode that isn't a real Indian pincode (bad format or not in database/data/pincodes.txt)
 * has no zone, so no charge is guessed for it.
 *
 * Lists (exact pincodes, or a prefix ending in * such as "1406*"):
 *   near  config services.delivery.near_pincodes  (env DELIVERY_NEAR_PINCODES)
 *   mid   config services.delivery.mid_pincodes   (env DELIVERY_MID_PINCODES), or, when that is
 *         empty, the starter list in database/data/mid_zone_pincodes.txt
 */
class PincodeZoneService
{
    public const NEAR = 'near';

    public const MID = 'mid';

    public const FAR = 'far';

    private static ?array $known = null;

    private static ?array $midFile = null;

    /** @return self::NEAR|self::MID|self::FAR|null null when the pincode can't be verified */
    public function zone(string $pincode): ?string
    {
        if (! $this->isKnown($pincode)) {
            return null;
        }

        if ($this->matches((array) config('services.delivery.near_pincodes', []), $pincode)) {
            return self::NEAR;
        }

        return $this->matches($this->midList(), $pincode) ? self::MID : self::FAR;
    }

    public function isKnown(string $pincode): bool
    {
        if (! preg_match('/^[1-9][0-9]{5}$/', $pincode)) {
            return false;
        }

        // A pincode the shop lists explicitly is always accepted, even if the directory misses it.
        if ($this->matches((array) config('services.delivery.near_pincodes', []), $pincode)
            || $this->matches($this->midList(), $pincode)) {
            return true;
        }

        self::$known ??= array_flip(array_filter(
            array_map('trim', (array) @file(database_path('data/pincodes.txt'))),
        ));

        return isset(self::$known[$pincode]);
    }

    /** @return string[] */
    private function midList(): array
    {
        $configured = (array) config('services.delivery.mid_pincodes', []);

        if ($configured !== []) {
            return $configured;
        }

        return self::$midFile ??= array_values(array_filter(
            array_map('trim', (array) @file(database_path('data/mid_zone_pincodes.txt'))),
        ));
    }

    /** @param string[] $entries exact pincodes or a prefix ending in * */
    private function matches(array $entries, string $pincode): bool
    {
        foreach ($entries as $entry) {
            if ($entry === $pincode || (str_ends_with($entry, '*') && str_starts_with($pincode, rtrim($entry, '*')))) {
                return true;
            }
        }

        return false;
    }
}
