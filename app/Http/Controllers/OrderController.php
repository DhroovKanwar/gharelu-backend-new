<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function store(StoreOrderRequest $request)
    {
        // Idempotency: a retried/double-clicked checkout with the same key
        // returns the existing order instead of creating a duplicate.
        $existing = Order::where('idempotency_key', $request->idempotency_key)->first();
        if ($existing) {
            return response()->json($existing->load('items'));
        }

        return DB::transaction(function () use ($request) {
            $productIds = collect($request->items)->pluck('product_id');
            $products = Product::whereIn('id', $productIds)->where('is_active', true)->get()->keyBy('id');

            // Server-side price recalculation — the ONLY source of truth
            // for what a customer actually owes. Anything the client sent
            // for price is ignored entirely.
            $lineItems = collect($request->items)->map(function ($item) use ($products) {
                $product = $products->get($item['product_id']);
                abort_if(! $product, 422, "Product {$item['product_id']} is not available.");

                $unitPrice = $product->price;
                if (! empty($item['size']) && is_array($product->sizes)) {
                    $sizeMatch = collect($product->sizes)->firstWhere('label', $item['size']);
                    if ($sizeMatch) {
                        $unitPrice = $sizeMatch['price'];
                    }
                }

                return [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'image' => $product->image,
                    'size' => $item['size'] ?? null,
                    'price' => $unitPrice,
                    'qty' => $item['qty'],
                ];
            });

            $subtotal = $lineItems->sum(fn ($i) => $i['price'] * $i['qty']);
            $deliveryFee = $subtotal >= 1500 ? 0 : 99;
            $total = $subtotal + $deliveryFee;

            $order = Order::create([
                'id' => 'GB'.random_int(100000, 999999),
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'email' => $request->email,
                'phone' => $request->phone,
                'delivery_mode' => $request->delivery_mode,
                'pickup_type' => $request->pickup_type,
                'address' => $request->address,
                'city' => $request->city,
                'pincode' => $request->pincode,
                'preferred_date' => $request->preferred_date,
                'preferred_time' => $request->preferred_time,
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'total' => $total,
                'payment_method' => $request->payment_method,
                'payment_status' => $request->payment_method === 'cod' ? 'cod_pending' : 'pending',
                'idempotency_key' => $request->idempotency_key,
            ]);

            $order->items()->createMany($lineItems->all());

            return response()->json($order->load('items'), 201);
        });
    }

    public function show(string $id)
    {
        return Order::with('items')->findOrFail($id);
    }
}
