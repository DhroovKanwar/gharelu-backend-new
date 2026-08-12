<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\ValidateCartRequest;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;

class CartController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
    ) {}

    public function validateCart(ValidateCartRequest $request): JsonResponse
    {
        $data = $request->validated();

        $result = $this->orderService->calculateCart(
            $data['items'],
            $data['delivery_mode'] ?? 'pickup',
        );

        return response()->json([
            'success' => true,
            'message' => 'Cart validated successfully.',
            'data' => [
                'items' => array_map(fn ($item) => [
                    'productSlug' => $item['product_slug'],
                    'productName' => $item['product_name'],
                    'productImage' => $item['product_image'],
                    'sizeLabel' => $item['size_label'],
                    'unitPrice' => $item['unit_price'],
                    'quantity' => $item['quantity'],
                    'lineTotal' => $item['line_total'],
                ], $result['items']),
                'subtotal' => $result['subtotal'],
                'deliveryFee' => $result['deliveryFee'],
                'total' => $result['total'],
            ],
        ]);
    }
}
