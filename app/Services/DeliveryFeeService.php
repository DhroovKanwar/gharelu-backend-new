<?php

namespace App\Services;

/**
 * Single place the delivery-fee rule lives. OrderService never hardcodes
 * pricing logic — it just calls calculate(). Change the rule here only.
 */
class DeliveryFeeService
{
    private const FREE_DELIVERY_THRESHOLD = 999.00;

    private const FLAT_DELIVERY_FEE = 49.00;

    public function calculate(string $deliveryMode, float $subtotal): float
    {
        if ($deliveryMode !== 'delivery') {
            return 0.0;
        }

        return $subtotal >= self::FREE_DELIVERY_THRESHOLD ? 0.0 : self::FLAT_DELIVERY_FEE;
    }
}
