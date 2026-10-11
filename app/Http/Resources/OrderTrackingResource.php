<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public, phone-based order tracking. The only credential a caller needs is
 * a phone number, so this deliberately omits every personal detail (name,
 * email, phone, address) and returns order progress only.
 */
class OrderTrackingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'orderNumber' => $this->order_number,
            'status' => $this->order_status,
            'paymentStatus' => $this->payment_status,
            'paymentMethod' => $this->payment_method,
            'deliveryMode' => $this->delivery_mode,
            'pickupType' => $this->pickup_type,
            'scheduledDate' => $this->scheduled_date?->format('Y-m-d'),
            'scheduledTime' => $this->scheduled_time,
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
