<?php

namespace App\Services;

use App\Models\Address;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private readonly DeliveryFeeService $deliveryFeeService,
    ) {}

    /**
     * @param  array  $data  Already-validated request data from StoreOrderRequest.
     */
    public function createOrder(array $data, ?User $user, ?string $idempotencyKey): Order
    {
        // Fast path: a request with this key already succeeded before this call.
        if ($idempotencyKey) {
            $existing = Order::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing->load(['items', 'address']);
            }
        }

        try {
            return DB::transaction(function () use ($data, $user, $idempotencyKey) {
                [$lineItems, $subtotal] = $this->buildLineItems($data['items']);

                $deliveryFee = $this->deliveryFeeService->calculate($data['delivery_mode'], $subtotal);
                $total = $subtotal + $deliveryFee;

                $addressId = null;
                if ($data['delivery_mode'] === 'delivery') {
                    $addressId = Address::create([
                        'user_id' => $user?->id,
                        'address_line_1' => $data['address_line_1'],
                        'address_line_2' => $data['address_line_2'] ?? null,
                        'city' => $data['city'],
                        'state' => $data['state'],
                        'pincode' => $data['pincode'],
                        'landmark' => $data['landmark'] ?? null,
                        'is_default' => false,
                    ])->id;
                }

                $order = Order::create([
                    'order_number' => $this->generateUniqueOrderNumber(),
                    'user_id' => $user?->id,
                    'guest_name' => trim($data['first_name'].' '.$data['last_name']),
                    'guest_email' => $data['email'],
                    'guest_phone' => $data['phone'],
                    'delivery_mode' => $data['delivery_mode'],
                    'pickup_type' => $data['pickup_type'] ?? null,
                    'address_id' => $addressId,
                    'scheduled_date' => $data['scheduled_date'],
                    'scheduled_time' => $data['scheduled_time'],
                    'subtotal' => $subtotal,
                    'delivery_fee' => $deliveryFee,
                    'total' => $total,
                    'currency' => 'INR',
                    'payment_method' => $data['payment_method'],
                    // Never client-controlled — backend owns these always.
                    'payment_status' => 'pending',
                    'order_status' => 'new',
                    'idempotency_key' => $idempotencyKey,
                    'notes' => $data['notes'] ?? null,
                ]);

                foreach ($lineItems as $item) {
                    $order->items()->create($item);
                }

                return $order->load(['items', 'address']);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two concurrent requests with the same Idempotency-Key: the
            // losing transaction lands here. The whole transaction above
            // (address + order + items) was already rolled back by
            // DB::transaction() before this catch runs, so there's nothing
            // to clean up — just hand back the winner's order.
            if ($idempotencyKey && str_contains($e->getMessage(), 'idempotency_key')) {
                $existing = Order::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing->load(['items', 'address']);
                }
            }

            throw $e;
        }
    }

    /**
     * Same pricing engine as createOrder(), without persisting anything.
     * Used by the cart validation endpoint so the two never drift apart.
     *
     * @return array{items: array<int, array>, subtotal: float, deliveryFee: float, total: float}
     */
    public function calculateCart(array $items, string $deliveryMode = 'pickup'): array
    {
        [$lineItems, $subtotal] = $this->buildLineItems($items);
        $deliveryFee = $this->deliveryFeeService->calculate($deliveryMode, $subtotal);

        return [
            'items' => $lineItems,
            'subtotal' => $subtotal,
            'deliveryFee' => $deliveryFee,
            'total' => round($subtotal + $deliveryFee, 2),
        ];
    }

    /**
     * Re-derives every price from the database. Nothing from the request
     * (price, size price, subtotal, total) is ever trusted here.
     *
     * @return array{0: array<int, array>, 1: float} [line items, subtotal]
     */
    private function buildLineItems(array $items): array
    {
        $lineItems = [];
        $subtotal = 0.0;

        foreach ($items as $line) {
            // lockForUpdate: prevents this product's price/active-state from
            // changing under us for the duration of this transaction if an
            // admin edit lands at the same moment as a checkout.
            $product = Product::query()
                ->where('slug', $line['product_slug'])
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $product) {
                throw ValidationException::withMessages([
                    'items' => ["Product \"{$line['product_slug']}\" is not available."],
                ]);
            }

            $unitPrice = (float) $product->base_price;
            $sizeLabel = null;

            if (! empty($line['size_label'])) {
                $size = $product->sizes()->where('label', $line['size_label'])->first();

                if (! $size) {
                    throw ValidationException::withMessages([
                        'items' => ["Size \"{$line['size_label']}\" is not valid for \"{$product->name}\"."],
                    ]);
                }

                $unitPrice = (float) $size->price;
                $sizeLabel = $size->label;
            }

            $quantity = (int) $line['quantity'];
            $lineTotal = round($unitPrice * $quantity, 2);
            $subtotal += $lineTotal;

            $primaryImage = $product->images->firstWhere('is_primary', true)
                ?? $product->images->first();

            $lineItems[] = [
                'product_id' => $product->id,
                // Extra key beyond OrderItem's $fillable — harmless when this
                // array is passed to $order->items()->create() in
                // createOrder(), and needed for the cart endpoint's response.
                'product_slug' => $product->slug,
                'product_name' => $product->name,
                'product_image' => $primaryImage?->path,
                'size_label' => $sizeLabel,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'line_total' => $lineTotal,
            ];
        }

        return [$lineItems, round($subtotal, 2)];
    }

    private function generateUniqueOrderNumber(): string
    {
        do {
            $candidate = 'GB'.random_int(100000, 999999);
        } while (Order::where('order_number', $candidate)->exists());

        return $candidate;
    }
}
