<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Order\AdminOrderIndexRequest;
use App\Http\Requests\Admin\Order\UpdateOrderStatusRequest;
use App\Http\Resources\Admin\AdminOrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

class AdminOrderController extends Controller
{
    public function index(AdminOrderIndexRequest $request): JsonResponse
    {
        $data = $request->validated();
        $perPage = $data['per_page'] ?? 20;

        $query = Order::query()->with(['items', 'address']);

        if (! empty($data['order_status'])) {
            $query->where('order_status', $data['order_status']);
        }
        if (! empty($data['payment_status'])) {
            $query->where('payment_status', $data['payment_status']);
        }
        if (! empty($data['payment_method'])) {
            $query->where('payment_method', $data['payment_method']);
        }
        if (! empty($data['delivery_mode'])) {
            $query->where('delivery_mode', $data['delivery_mode']);
        }
        if (! empty($data['date'])) {
            $query->whereDate('scheduled_date', $data['date']);
        }
        if (! empty($data['search'])) {
            $query->where('order_number', 'like', '%'.$data['search'].'%');
        }

        $orders = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Orders retrieved successfully.',
            'data' => AdminOrderResource::collection($orders)->collection,
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        $order->load(['items', 'address']);

        return response()->json([
            'success' => true,
            'message' => 'Order retrieved successfully.',
            'data' => new AdminOrderResource($order),
        ]);
    }

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): JsonResponse
    {
        // Only order_status is ever written here — payment_status is
        // controlled exclusively by PaymentService/webhooks, never by an
        // admin request, no matter what else is in the payload.
        $order->update(['order_status' => $request->validated('order_status')]);
        $order->load(['items', 'address']);

        return response()->json([
            'success' => true,
            'message' => 'Order status updated successfully.',
            'data' => new AdminOrderResource($order),
        ]);
    }
}
