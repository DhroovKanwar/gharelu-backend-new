<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
    ) {}

    public function store(StoreOrderRequest $request): JsonResponse
    {
        // Optional auth: resolves the Bearer token if present, but never
        // forces authentication — guest checkout must keep working.
        $user = $request->user('sanctum');

        $order = $this->orderService->createOrder(
            $request->validated(),
            $user,
            $request->header('Idempotency-Key'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Order placed successfully.',
            'data' => new OrderResource($order),
        ], 201);
    }

    /**
     * Public guest order lookup by phone number — no auth required, since
     * guest checkout means most orders have no account to log into. Matches
     * on the last 10 digits of guest_phone so "+91 98765 43210", "98765
     * 43210" and "9876543210" all resolve to the same number regardless of
     * how it was formatted when the order was placed or is typed here.
     */
    public function lookup(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'max:20'],
        ]);

        $digits = preg_replace('/\D+/', '', $request->input('phone'));
        $last10 = substr($digits, -10);

        if (strlen($last10) < 10) {
            return response()->json([
                'success' => false,
                'message' => 'Please enter a valid 10-digit mobile number.',
                'data' => [],
            ], 422);
        }

        $orders = Order::query()
            ->whereRaw("REGEXP_REPLACE(guest_phone, '[^0-9]', '') LIKE ?", ['%'.$last10])
            ->with(['items', 'address'])
            ->latest()
            ->limit(20)
            ->get();

        return response()->json([
            'success' => true,
            'message' => $orders->isEmpty()
                ? 'No orders found for this number.'
                : 'Orders retrieved successfully.',
            'data' => OrderResource::collection($orders),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $orders = $request->user()
            ->orders()
            ->with(['items', 'address'])
            ->latest()
            ->paginate(10);

        return response()->json([
            'success' => true,
            'message' => 'Orders retrieved successfully.',
            'data' => OrderResource::collection($orders)->collection,
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $order = Order::query()
            ->with(['items', 'address'])
            ->where('order_number', $orderNumber)
            ->firstOrFail();

        // Throws AuthorizationException (403) if this order isn't the
        // authenticated user's — never reveals whether the order_number
        // exists at all to someone who doesn't own it vs a typo.
        Gate::authorize('view', $order);

        return response()->json([
            'success' => true,
            'message' => 'Order retrieved successfully.',
            'data' => new OrderResource($order),
        ]);
    }
}
