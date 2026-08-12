<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Deliberately no 'id' key — order_number is the only externally
        // usable identifier; the internal auto-increment PK never leaves this API.
        return [
            'orderNumber' => $this->order_number,
            'status' => $this->order_status,
            'paymentStatus' => $this->payment_status,
            'paymentMethod' => $this->payment_method,
            'deliveryMode' => $this->delivery_mode,
            'pickupType' => $this->pickup_type,
            'scheduledDate' => $this->scheduled_date?->format('Y-m-d'),
            'scheduledTime' => $this->scheduled_time,
            'customer' => [
                'name' => $this->guest_name,
                'email' => $this->guest_email,
                'phone' => $this->guest_phone,
            ],
            'address' => $this->whenLoaded('address', fn () => $this->address ? [
                'addressLine1' => $this->address->address_line_1,
                'addressLine2' => $this->address->address_line_2,
                'city' => $this->address->city,
                'state' => $this->address->state,
                'pincode' => $this->address->pincode,
                'landmark' => $this->address->landmark,
            ] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'productName' => $item->product_name,
                'productImage' => $item->product_image,
                'sizeLabel' => $item->size_label,
                'unitPrice' => (float) $item->unit_price,
                'quantity' => $item->quantity,
                'lineTotal' => (float) $item->line_total,
            ])->values()),
            'subtotal' => (float) $this->subtotal,
            'deliveryFee' => (float) $this->delivery_fee,
            'total' => (float) $this->total,
            'currency' => $this->currency,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
