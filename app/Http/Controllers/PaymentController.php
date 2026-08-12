<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VerifyPaymentRequest;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Razorpay\Api\Api as RazorpayApi;

class PaymentController extends Controller
{
    /**
     * POST /api/payments/create-order
     *
     * This is THE fix for the client-price-trust gap identified in the
     * security review: the amount is recalculated here from `orders.total`
     * (itself computed server-side in OrderController from live product
     * prices, never from a number the frontend sent) — never from anything
     * passed in this request body. Razorpay then enforces that the
     * customer actually pays exactly this amount.
     */
    public function createOrder(Request $request)
    {
        $request->validate(['order_id' => ['required', 'string', 'exists:orders,id']]);

        $order = Order::findOrFail($request->order_id);

        if ($order->payment_status === 'paid') {
            return response()->json(['message' => 'Order already paid'], 409);
        }

        $api = new RazorpayApi(config('services.razorpay.key'), config('services.razorpay.secret'));

        $razorpayOrder = $api->order->create([
            'receipt' => $order->id,
            'amount' => $order->total * 100, // paise
            'currency' => 'INR',
            'notes' => ['local_order_id' => $order->id],
        ]);

        $order->update(['razorpay_order_id' => $razorpayOrder->id]);

        return response()->json([
            'id' => $razorpayOrder->id,
            'amount' => $order->total,
            'currency' => 'INR',
        ]);
    }

    /**
     * POST /api/payments/verify
     *
     * Called from Checkout.jsx's Razorpay `handler` callback. Recomputes
     * the HMAC-SHA256 signature and only marks the order paid on an exact
     * match — this is what stops a forged/replayed payment_id from being
     * accepted. The webhook below is the backstop for cases where this
     * client-side call never fires (tab closed mid-payment, etc).
     */
    public function verify(VerifyPaymentRequest $request)
    {
        $order = Order::findOrFail($request->order_id);

        $expectedSignature = hash_hmac(
            'sha256',
            $request->razorpay_order_id.'|'.$request->razorpay_payment_id,
            config('services.razorpay.secret'),
        );

        if (! hash_equals($expectedSignature, $request->razorpay_signature)) {
            Log::warning('Razorpay signature mismatch', ['order_id' => $order->id]);

            return response()->json(['verified' => false], 422);
        }

        $order->update([
            'payment_status' => 'paid',
            'razorpay_payment_id' => $request->razorpay_payment_id,
        ]);

        return response()->json(['verified' => true, 'order' => $order]);
    }

    /**
     * POST /api/payments/webhook
     *
     * Razorpay webhook — server-to-server, independent of anything the
     * browser does. Configure this URL + a separate webhook secret in the
     * Razorpay dashboard. This is what guarantees payment confirmation
     * even if the customer's browser never gets to call verify() above.
     */
    public function webhook(Request $request)
    {
        $signature = $request->header('X-Razorpay-Signature');
        $expected = hash_hmac('sha256', $request->getContent(), config('services.razorpay.webhook_secret'));

        if (! hash_equals($expected, (string) $signature)) {
            Log::warning('Razorpay webhook signature mismatch');

            return response()->json(['status' => 'invalid signature'], 400);
        }

        $payload = $request->input('payload.payment.entity', []);
        $localOrderId = $payload['notes']['local_order_id'] ?? null;

        if ($localOrderId && ($order = Order::find($localOrderId))) {
            $order->update([
                'payment_status' => 'paid',
                'razorpay_payment_id' => $payload['id'] ?? $order->razorpay_payment_id,
            ]);
        }

        return response()->json(['status' => 'ok']);
    }
}
