<?php

namespace App\Services;

use App\Models\Address;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OrderService
{
    private const HAMPER_CATEGORY_SLUG = 'hampers';

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
                $this->assertDeliveryModeAllowed($lineItems, $data['delivery_mode']);

                $quote = $this->deliveryFeeService->quote(
                    $data['delivery_mode'],
                    $data['pincode'] ?? null,
                    $this->totalWeightKg($lineItems),
                );
                $deliveryFee = $quote['fee'];
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
                    'invoice_number' => $this->nextInvoiceNumber(),
                    'user_id' => $user?->id,
                    'guest_name' => trim($data['first_name'].' '.($data['last_name'] ?? '')),
                    'guest_email' => $data['email'] ?? null,
                    'guest_phone' => $data['phone'],
                    'delivery_mode' => $data['delivery_mode'],
                    'pickup_type' => $data['pickup_type'] ?? null,
                    'address_id' => $addressId,
                    'scheduled_date' => $data['scheduled_date'],
                    'scheduled_time' => $data['scheduled_time'] ?? null,
                    'estimated_delivery_date' => $this->deliveryFeeService->estimatedDate($quote),
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
     * @return array{items: array<int, array>, subtotal: float, deliveryFee: float, estimatedDelivery: ?string, total: float}
     */
    public function calculateCart(array $items, string $deliveryMode = 'pickup', ?string $pincode = null): array
    {
        [$lineItems, $subtotal] = $this->buildLineItems($items);
        $this->assertDeliveryModeAllowed($lineItems, $deliveryMode);
        $quote = $this->deliveryFeeService->quote($deliveryMode, $pincode, $this->totalWeightKg($lineItems));
        $deliveryFee = $quote['fee'];

        return [
            'items' => $lineItems,
            'subtotal' => $subtotal,
            'deliveryFee' => $deliveryFee,
            'estimatedDelivery' => $this->deliveryFeeService->estimatedDate($quote),
            'total' => round($subtotal + $deliveryFee, 2),
        ];
    }

    /**
     * Delivery is for hampers only: every other menu item is pickup only, and
     * hampers cannot share an order with other items.
     */
    private function assertDeliveryModeAllowed(array $lineItems, string $deliveryMode): void
    {
        $productIds = array_values(array_unique(array_column($lineItems, 'product_id')));
        $hampers = Product::query()
            ->whereIn('id', $productIds)
            ->whereHas('category', fn ($q) => $q->where('slug', self::HAMPER_CATEGORY_SLUG))
            ->count();

        if ($hampers > 0 && $hampers < count($productIds)) {
            throw ValidationException::withMessages([
                'items' => ['Hampers must be ordered separately from other menu items.'],
            ]);
        }

        if ($hampers === 0 && $deliveryMode === 'delivery') {
            throw ValidationException::withMessages([
                'delivery_mode' => ['Delivery is available for hampers only. Other menu items are pickup only.'],
            ]);
        }
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

            if ($product->stock_status === 'out_of_stock') {
                throw ValidationException::withMessages([
                    'items' => ["\"{$product->name}\" is out of stock right now."],
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
                'weight_grams' => $product->weight_grams,
                'product_image' => $primaryImage?->path,
                'size_label' => $sizeLabel,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'line_total' => $lineTotal,
            ];
        }

        return [$lineItems, round($subtotal, 2)];
    }

    private function randomToken(int $length): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0/O/1/I/L
        $token = '';
        for ($i = 0; $i < $length; $i++) {
            $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $token;
    }

    /** Net product weight in kg; products without a weight use the configured default. */
    private function totalWeightKg(array $lineItems): float
    {
        $defaultGrams = (int) round(config('services.shiprocket.default_item_weight_kg') * 1000);

        $grams = 0;
        $guessed = [];
        foreach ($lineItems as $item) {
            if (($item['weight_grams'] ?? null) === null) {
                $guessed[] = $item['product_slug'] ?? $item['product_id'];
            }
            $grams += ($item['weight_grams'] ?? $defaultGrams) * $item['quantity'];
        }

        // A guessed weight can under-quote shipping; make that visible so the
        // missing product weights get filled in (admin product form).
        if ($guessed !== []) {
            Log::warning('Shipping weight guessed for products without weight_grams', ['products' => array_values(array_unique($guessed))]);
        }

        return $grams / 1000;
    }

    /**
     * Next invoice number for this (Indian) calendar year, e.g. GB001/2026. Must run inside the
     * order transaction: the counter row is locked so two orders never share a number.
     */
    private function nextInvoiceNumber(): string
    {
        $year = (int) now('Asia/Kolkata')->format('Y');

        DB::table('invoice_sequences')->insertOrIgnore(['year' => $year, 'last_number' => 0]);
        DB::table('invoice_sequences')->where('year', $year)->lockForUpdate()->first();
        DB::table('invoice_sequences')->where('year', $year)->increment('last_number');
        $number = (int) DB::table('invoice_sequences')->where('year', $year)->value('last_number');

        return sprintf('GB%03d/%d', $number, $year);
    }

    private function generateUniqueOrderNumber(): string
    {
        do {
            // Not guessable: the order number alone authorises guest payment
            // creation, so it must not be enumerable like GB + 6 digits.
            $candidate = 'GB'.$this->randomToken(10);
        } while (Order::where('order_number', $candidate)->exists());

        return $candidate;
    }
}
