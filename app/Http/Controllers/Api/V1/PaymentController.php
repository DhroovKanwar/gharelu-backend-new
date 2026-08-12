<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\CreatePaymentOrderRequest;
use App\Http\Requests\Payment\VerifyPaymentRequest;
use App\Models\Order;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    public function createOrder(CreatePaymentOrderRequest $request): JsonResponse
    {
        $order = Order::where('order_number', $request->validated('order_number'))->firstOrFail();

        // Optional auth: resolves the Bearer token if present, but never
        // forces authentication — guest checkout must keep working.
        $user = $request->user('sanctum');

        $payment = $this->paymentService->createOrderPayment($order, $user);

        return response()->json([
            'success' => true,
            'message' => 'Razorpay order created successfully.',
            'data' => [
                // Public key only — the secret never leaves the server.
                'razorpayKeyId' => config('razorpay.key_id'),
                'razorpayOrderId' => $payment->razorpay_order_id,
                'orderNumber' => $order->order_number,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
            ],
        ]);
    }

    public function verify(VerifyPaymentRequest $request): JsonResponse
    {
        $order = $this->paymentService->verifyPayment($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Payment verified successfully.',
            'data' => [
                'orderNumber' => $order->order_number,
                'paymentStatus' => $order->payment_status,
                'orderStatus' => $order->order_status,
            ],
        ]);
    }

    public function webhook(Request $request): JsonResponse
    {
        $rawPayload = $request->getContent();
        $signature = $request->header('X-Razorpay-Signature');

        if (! $this->paymentService->verifyWebhookSignature($rawPayload, $signature)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid webhook signature.',
            ], 400);
        }

        $this->paymentService->handleWebhookEvent($rawPayload);

        return response()->json([
            'success' => true,
            'message' => 'Webhook processed.',
        ]);
    }
}
