<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Single place the delivery-fee rule lives. OrderService never hardcodes
 * pricing logic — it just calls calculate(). Change the rule here only.
 *
 * Fee = cheapest Shiprocket courier rate for the pincode and cart weight.
 * There is no free-delivery threshold and no fallback fee: pickup is free,
 * and a delivery order without a live courier quote is rejected.
 */
class DeliveryFeeService
{
    public function __construct(
        private readonly ShiprocketService $shiprocket,
    ) {}

    public function calculate(string $deliveryMode, ?string $pincode = null, float $netWeightKg = 0.0): float
    {
        return $this->quote($deliveryMode, $pincode, $netWeightKg)['fee'];
    }

    /**
     * @param  float  $netWeightKg  Weight of the products only; packaging is added here.
     * @return array{fee: float, courier: ?string, etd: ?string, source: string}
     *
     * @throws ValidationException when no courier quote is available.
     */
    public function quote(string $deliveryMode, ?string $pincode = null, float $netWeightKg = 0.0): array
    {
        if ($deliveryMode !== 'delivery') {
            return ['fee' => 0.0, 'courier' => null, 'etd' => null, 'source' => 'pickup'];
        }

        if ($pincode && $this->isLocalPincode($pincode)) {
            return ['fee' => (float) config('services.local_delivery.fee'), 'courier' => 'Local delivery', 'etd' => null, 'source' => 'local'];
        }

        $rate = null;

        if ($pincode) {
            $weight = max(0.5, $netWeightKg + config('services.shiprocket.packaging_weight_kg'));
            $rate = $this->shiprocket->cheapestRate($pincode, round($weight, 2));
        }

        if (! $rate) {
            throw ValidationException::withMessages([
                'pincode' => ["We couldn't calculate delivery for this pincode right now. Please check the pincode or try again."],
            ]);
        }

        return ['fee' => $rate['rate'], 'courier' => $rate['courier'], 'etd' => $rate['etd'], 'source' => 'shiprocket'];
    }

    private function isLocalPincode(string $pincode): bool
    {
        foreach (config('services.local_delivery.pincodes') as $entry) {
            if ($entry === $pincode || (str_ends_with($entry, '*') && str_starts_with($pincode, rtrim($entry, '*')))) {
                return true;
            }
        }

        return false;
    }
}
