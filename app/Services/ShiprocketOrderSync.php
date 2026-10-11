<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes a paid delivery order into Shiprocket (orders/create/adhoc).
 * Safe to call repeatedly: it does nothing once the order has a Shiprocket id.
 * Courier assignment / pickup booking stays manual in the Shiprocket panel.
 */
class ShiprocketOrderSync
{
    private const URL = 'https://apiv2.shiprocket.in/v1/external/orders/create/adhoc';

    public function __construct(
        private readonly ShiprocketService $shiprocket,
    ) {}

    public function shouldSync(Order $order): bool
    {
        return $order->delivery_mode === 'delivery'
            && $order->payment_status === 'paid'
            && ! $order->shiprocket_order_id
            && $this->shiprocket->isConfigured();
    }

    /** Never throws — a Shiprocket problem must not break payment handling. */
    public function push(Order $order): bool
    {
        if (! $this->shouldSync($order)) {
            return false;
        }

        try {
            $order->loadMissing(['items', 'address']);
            $payload = $this->payload($order);

            $response = Http::withToken($this->shiprocket->token())
                ->timeout(15)
                ->post(self::URL, $payload);

            $orderId = $response->json('order_id');

            if (! $response->successful() || ! $orderId) {
                Log::warning('Shiprocket order create failed', [
                    'order' => $order->order_number,
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);

                return false;
            }

            $order->forceFill([
                'shiprocket_order_id' => (string) $orderId,
                'shiprocket_shipment_id' => $response->json('shipment_id') ? (string) $response->json('shipment_id') : null,
            ])->save();

            return true;
        } catch (Throwable $e) {
            Log::warning('Shiprocket order create error', ['order' => $order->order_number, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function payload(Order $order): array
    {
        $address = $order->address;
        [$firstName, $lastName] = array_pad(explode(' ', trim($order->guest_name), 2), 2, '');

        $weights = Product::whereIn('id', $order->items->pluck('product_id'))->pluck('weight_grams', 'id');
        $defaultGrams = (int) round(config('services.shiprocket.default_item_weight_kg') * 1000);
        $grams = 0;
        foreach ($order->items as $item) {
            $grams += ($weights[$item->product_id] ?? $defaultGrams) * $item->quantity;
        }
        $weightKg = round($grams / 1000 + config('services.shiprocket.packaging_weight_kg'), 2);

        return [
            'order_id' => $order->order_number,
            'order_date' => $order->created_at->format('Y-m-d H:i'),
            'pickup_location' => config('services.shiprocket.pickup_location'),
            'billing_customer_name' => $firstName,
            'billing_last_name' => $lastName,
            'billing_address' => $address->address_line_1,
            'billing_address_2' => $address->address_line_2 ?? '',
            'billing_city' => $address->city,
            'billing_pincode' => $address->pincode,
            'billing_state' => $address->state,
            'billing_country' => 'India',
            'billing_email' => $order->guest_email,
            'billing_phone' => $order->guest_phone,
            'shipping_is_billing' => true,
            'order_items' => $order->items->map(fn ($i) => [
                'name' => $i->product_name,
                'sku' => (string) $i->product_id,
                'units' => (int) $i->quantity,
                'selling_price' => (float) $i->unit_price,
            ])->values()->all(),
            'payment_method' => 'Prepaid',
            'shipping_charges' => (float) $order->delivery_fee,
            'sub_total' => (float) $order->subtotal,
            'length' => config('services.shiprocket.parcel_length_cm'),
            'breadth' => config('services.shiprocket.parcel_breadth_cm'),
            'height' => config('services.shiprocket.parcel_height_cm'),
            'weight' => $weightKg,
            'comment' => $order->notes,
        ];
    }
}
