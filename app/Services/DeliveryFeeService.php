<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Single place the delivery-fee rule lives. OrderService never hardcodes
 * pricing logic — it just calls quote(). Change the rule here only.
 *
 * Fee depends on the delivery pincode's zone (see PincodeZoneService) and, for the two
 * nearby zones, on how many hampers are in the order (every delivery order is hampers only):
 *
 *   hampers   up to 10 km   10 to 30 km
 *   1-2       Rs 100        Rs 150
 *   3-4       Rs 150        Rs 250
 *   5+        Rs 250        Rs 500
 *
 * More than 30 km: the cheapest Shiprocket courier rate for the pincode and cart weight.
 *
 * Pickup is free. There is no fallback fee: a pincode that can't be verified, or a far
 * pincode with no courier quote, is rejected rather than charged a guess.
 */
class DeliveryFeeService
{
    /** [max hampers in the band (null = no limit), fee up to 10 km, fee 10 to 30 km] */
    private const FEE_TABLE = [
        [2, 100.0, 150.0],
        [4, 150.0, 250.0],
        [null, 250.0, 500.0],
    ];

    public function __construct(
        private readonly PincodeZoneService $zones,
        private readonly ShiprocketService $shiprocket,
    ) {}

    public function calculate(string $deliveryMode, ?string $pincode = null, int $hamperCount = 0, float $netWeightKg = 0.0): float
    {
        return $this->quote($deliveryMode, $pincode, $hamperCount, $netWeightKg)['fee'];
    }

    /**
     * @param  int  $hamperCount  Total hampers (sum of quantities) in the order.
     * @param  float  $netWeightKg  Weight of the products only; packaging is added here for the courier quote.
     * @return array{fee: float, courier: ?string, etd: ?string, source: string, zone: ?string}
     *
     * @throws ValidationException when the pincode can't be verified or a far pincode has no courier quote.
     */
    public function quote(string $deliveryMode, ?string $pincode = null, int $hamperCount = 0, float $netWeightKg = 0.0): array
    {
        if ($deliveryMode !== 'delivery') {
            return ['fee' => 0.0, 'courier' => null, 'etd' => null, 'source' => 'pickup', 'zone' => null];
        }

        $zone = $pincode ? $this->zones->zone($pincode) : null;

        if ($zone === null) {
            throw ValidationException::withMessages([
                'pincode' => ["We couldn't verify this pincode for delivery. Please check it and try again."],
            ]);
        }

        $weight = round(max(0.5, $netWeightKg + config('services.shiprocket.packaging_weight_kg')), 2);

        if ($zone === PincodeZoneService::FAR) {
            $rate = $this->courierRate((string) $pincode, $weight);

            if (! $rate) {
                throw ValidationException::withMessages([
                    'pincode' => ["We couldn't calculate delivery for this pincode right now. Please check the pincode or try again."],
                ]);
            }

            return ['fee' => $rate['rate'], 'courier' => $rate['courier'], 'etd' => $rate['etd'], 'source' => 'shiprocket', 'zone' => $zone];
        }

        // Courier ETD is a nice-to-have for the "estimated delivery" line; the fee never depends on it.
        return [
            'fee' => self::feeFor($zone === PincodeZoneService::NEAR, $hamperCount),
            'courier' => null,
            'etd' => $this->courierRate((string) $pincode, $weight)['etd'] ?? null,
            'source' => 'zone',
            'zone' => $zone,
        ];
    }

    /** The table charge for a hamper count; $near means within 10 km (exactly 10 km counts as near). */
    public static function feeFor(bool $near, int $hamperCount): float
    {
        $hampers = max(1, $hamperCount);

        foreach (self::FEE_TABLE as [$maxHampers, $nearFee, $midFee]) {
            if ($maxHampers === null || $hampers <= $maxHampers) {
                return $near ? $nearFee : $midFee;
            }
        }

        return 0.0; // unreachable: the last band has no limit
    }

    /**
     * Estimated arrival date (Y-m-d) from a quote: the courier's ETD when it gave one. Null for pickup
     * or when no estimate is available.
     *
     * @param  array{etd: ?string, source: string}  $quote
     */
    public function estimatedDate(array $quote): ?string
    {
        if ($quote['source'] === 'pickup' || ! $quote['etd']) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($quote['etd'])->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{rate: float, courier: string, etd: ?string}|null */
    private function courierRate(string $pincode, float $weight): ?array
    {
        try {
            return $this->shiprocket->cheapestRate($pincode, $weight);
        } catch (\Throwable) {
            return null;
        }
    }
}
