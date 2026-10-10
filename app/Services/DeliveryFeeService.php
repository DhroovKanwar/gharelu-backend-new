<?php

namespace App\Services;

/**
 * Single place the delivery-fee rule lives. OrderService never hardcodes
 * pricing logic — it just calls calculate(). Change the rule here only.
 *
 * Fee = cheapest Shiprocket courier rate for the pincode and cart weight.
 * There is no free-delivery threshold: delivery always costs the courier
 * rate (pickup is free). If Shiprocket is not configured or returns no
 * quote, a flat fallback fee is used so checkout never breaks.
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
     */
    public function quote(string $deliveryMode, ?string $pincode = null, float $netWeightKg = 0.0): array
    {
        if ($deliveryMode !== 'delivery') {
            return ['fee' => 0.0, 'courier' => null, 'etd' => null, 'source' => 'pickup'];
        }

        if ($pincode && $this->shiprocket->isConfigured()) {
            $weight = max(0.5, $netWeightKg + config('services.shiprocket.packaging_weight_kg'));

            $rate = $this->shiprocket->cheapestRate($pincode, round($weight, 2));

            if ($rate) {
                return ['fee' => $rate['rate'], 'courier' => $rate['courier'], 'etd' => $rate['etd'], 'source' => 'shiprocket'];
            }
        }

        return ['fee' => (float) config('services.shiprocket.fallback_fee'), 'courier' => null, 'etd' => null, 'source' => 'fallback'];
    }
}
