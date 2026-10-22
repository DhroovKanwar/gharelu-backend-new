<?php

namespace App\Services;

use App\Jobs\SendOrderConfirmations;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentWebhookLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private readonly RazorpayService $razorpay,
        private readonly ShiprocketOrderSync $shiprocketSync,
    ) {}

    /**
     * Creates (or reuses) a Razorpay order for a local order. The amount is
     * always read from `orders.total` — never from the request.
     */
    public function createOrderPayment(Order $order, ?User $user): Payment
    {
        $this->assertCanPay($order, $user);

        // COD must never go through Razorpay — enforced server-side, not
        // just by the frontend choosing not to call this endpoint.
        if ($order->payment_method === 'cod') {
            throw ValidationException::withMessages([
                'payment_method' => ['Cash on Delivery orders cannot be paid via Razorpay.'],
            ]);
        }

        return DB::transaction(function () use ($order) {
            // Lock the order row for the duration of this check+create so two
            // near-simultaneous requests for the same order can't both call
            // Razorpay and create two orders.
            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->first();

            if ($lockedOrder->payment_status === 'paid') {
                throw ValidationException::withMessages([
                    'order' => ['This order has already been paid.'],
                ]);
            }

            $existing = Payment::where('order_id', $lockedOrder->id)
                ->whereIn('status', ['created', 'authorized', 'captured'])
                ->latest()
                ->first();

            if ($existing) {
                // Idempotent — an in-flight or completed payment already
                // exists for this order; don't call Razorpay again.
                return $existing;
            }

            $razorpayOrder = $this->razorpay->createOrder(
                $lockedOrder->order_number,
                (float) $lockedOrder->total,
                $lockedOrder->currency,
            );

            return Payment::create([
                'order_id' => $lockedOrder->id,
                'razorpay_order_id' => $razorpayOrder['id'],
                'status' => 'created',
                'amount' => $lockedOrder->total,
                'currency' => $lockedOrder->currency,
            ]);
        });
    }

    /**
     * Guest orders (user_id null): the order_number itself — already handed
     * to the client the moment the order was placed — is the proof of
     * possession, consistent with the rest of the guest-checkout design
     * (no separate guest-lookup mechanism exists anywhere in this API).
     * Registered-customer orders: must be authenticated as that exact user.
     */
    private function assertCanPay(Order $order, ?User $user): void
    {
        if ($order->user_id !== null && (! $user || $user->id !== $order->user_id)) {
            abort(403, 'You are not authorized to pay for this order.');
        }
    }

    /**
     * Verifies a Razorpay payment signature server-side and, if valid,
     * marks the payment captured and the order paid. Never trusts a
     * frontend "payment succeeded" claim on its own.
     */
    public function verifyPayment(array $data): Order
    {
        $razorpayOrderId = $data['razorpay_order_id'];
        $razorpayPaymentId = $data['razorpay_payment_id'];
        $signature = $data['razorpay_signature'];

        $payment = Payment::where('razorpay_order_id', $razorpayOrderId)->first();

        if (! $payment) {
            throw ValidationException::withMessages([
                'razorpay_order_id' => ['No matching payment found for this order.'],
            ]);
        }

        if (! $this->razorpay->verifySignature($razorpayOrderId, $razorpayPaymentId, $signature)) {
            throw ValidationException::withMessages([
                'razorpay_signature' => ['Payment signature verification failed.'],
            ]);
        }

        $order = DB::transaction(function () use ($payment, $razorpayPaymentId, $signature) {
            $lockedPayment = Payment::where('id', $payment->id)->lockForUpdate()->first();
            $order = Order::where('id', $lockedPayment->order_id)->lockForUpdate()->first();

            // A different payment_id trying to attach to a payment record
            // that already has one recorded — reject regardless of status.
            // (Checked before the idempotent-return below, so an
            // already-captured payment can't be silently reattached to a
            // different Razorpay payment_id.)
            if ($lockedPayment->razorpay_payment_id && $lockedPayment->razorpay_payment_id !== $razorpayPaymentId) {
                throw ValidationException::withMessages([
                    'razorpay_payment_id' => ['This payment does not match the expected order.'],
                ]);
            }

            // Idempotent — already verified/captured previously with this
            // exact payment_id; re-verifying must not double-process.
            if ($lockedPayment->status === 'captured') {
                return $order;
            }

            $lockedPayment->update([
                'razorpay_payment_id' => $razorpayPaymentId,
                'razorpay_signature' => $signature,
                'status' => 'captured',
            ]);

            if ($order->payment_status !== 'paid') {
                $order->update(['payment_status' => 'paid']);
            }

            return $order->refresh();
        });

        // Outside the transaction, and never throws: a Shiprocket problem
        // must not fail a payment that has already been captured.
        $this->shiprocketSync->push($order);
        SendOrderConfirmations::dispatchAfterResponse($order->id);

        return $order;
    }

    /**
     * An admin marks an order paid by hand — for when the money reached the gateway
     * (or cash was collected) but the order never updated. Always leaves an audit row
     * with the admin, the remarks and the Razorpay payment id.
     */
    public function markPaidManually(Order $order, User $admin, ?string $razorpayPaymentId, string $remarks): Order
    {
        $order = DB::transaction(function () use ($order, $admin, $razorpayPaymentId, $remarks) {
            $locked = Order::lockForUpdate()->findOrFail($order->id);

            if ($locked->payment_status === 'paid') {
                throw ValidationException::withMessages(['payment_status' => ['This order is already marked as paid.']]);
            }

            if ($locked->payment_method === 'online') {
                if (! $razorpayPaymentId) {
                    throw ValidationException::withMessages(['razorpay_payment_id' => ['Enter the Razorpay payment id for an online order.']]);
                }

                // One gateway payment can only ever settle one order.
                $usedElsewhere = Payment::where('razorpay_payment_id', $razorpayPaymentId)->where('order_id', '!=', $locked->id)->exists()
                    || DB::table('order_payment_adjustments')->where('razorpay_payment_id', $razorpayPaymentId)->where('order_id', '!=', $locked->id)->exists();
                if ($usedElsewhere) {
                    throw ValidationException::withMessages(['razorpay_payment_id' => ['This payment id is already linked to another order.']]);
                }

                // Keep the payments row in step when this order has one that is still open.
                $payment = Payment::where('order_id', $locked->id)->whereIn('status', ['created', 'authorized', 'failed'])->latest('id')->first();
                $payment?->update(['razorpay_payment_id' => $razorpayPaymentId, 'status' => 'captured']);
            }

            $from = $locked->payment_status;
            $locked->update(['payment_status' => 'paid']);

            DB::table('order_payment_adjustments')->insert([
                'order_id' => $locked->id,
                'admin_id' => $admin->id,
                'from_status' => $from,
                'to_status' => 'paid',
                'razorpay_payment_id' => $razorpayPaymentId,
                'remarks' => $remarks,
                'created_at' => now(),
            ]);

            return $locked->refresh();
        });

        // Same follow-ups as an automatic payment; each is once-only and never throws.
        $this->shiprocketSync->push($order);
        SendOrderConfirmations::dispatchAfterResponse($order->id);

        return $order;
    }

    /**
     * Processes a Razorpay webhook payload. Signature must already have
     * been verified by the caller before this runs — see
     * verifyWebhookSignature() below, kept separate so the controller can
     * reject bad signatures before any DB work happens.
     */
    public function verifyWebhookSignature(string $rawPayload, ?string $signature): bool
    {
        return $signature !== null && $this->razorpay->verifyWebhookSignature($rawPayload, $signature);
    }

    public function handleWebhookEvent(string $rawPayload): void
    {
        $payload = json_decode($rawPayload, true) ?? [];
        $eventType = $payload['event'] ?? 'unknown';
        $eventId = $payload['id'] ?? md5($rawPayload);

        // Idempotency — an identical event (or identical raw body, if
        // Razorpay ever redelivers without an explicit event id) is never
        // processed twice.
        if (PaymentWebhookLog::where('razorpay_event_id', $eventId)->exists()) {
            return;
        }

        PaymentWebhookLog::create([
            'event_type' => $eventType,
            'razorpay_event_id' => $eventId,
            // Minimal, safe subset only — never the full raw payload.
            'payload' => $this->minimalPayload($payload),
            'signature_valid' => true,
            'processed_at' => now(),
        ]);

        match ($eventType) {
            'payment.captured' => $this->applyCaptured($payload),
            'payment.failed' => $this->applyFailed($payload),
            'refund.processed' => $this->applyRefunded($payload),
            default => null,
        };
    }

    private function minimalPayload(array $payload): array
    {
        $entity = $payload['payload']['payment']['entity']
            ?? $payload['payload']['refund']['entity']
            ?? [];

        return [
            'event' => $payload['event'] ?? null,
            'order_id' => $entity['order_id'] ?? null,
            'payment_id' => $entity['id'] ?? null,
            'status' => $entity['status'] ?? null,
            'error_code' => $entity['error_code'] ?? null,
            'error_description' => $entity['error_description'] ?? null,
        ];
    }

    private function applyCaptured(array $payload): void
    {
        $entity = $payload['payload']['payment']['entity'] ?? null;
        if (! $entity || empty($entity['order_id'])) {
            return;
        }

        $paidOrderId = null;

        DB::transaction(function () use ($entity, &$paidOrderId) {
            $payment = Payment::where('razorpay_order_id', $entity['order_id'])->lockForUpdate()->first();
            if (! $payment) {
                return;
            }

            $payment->update([
                'razorpay_payment_id' => $entity['id'] ?? $payment->razorpay_payment_id,
                'status' => 'captured',
            ]);

            $order = Order::where('id', $payment->order_id)->lockForUpdate()->first();
            if ($order && $order->payment_status !== 'paid') {
                $order->update(['payment_status' => 'paid']);
            }

            $paidOrderId = $order?->id;
        });

        if ($paidOrderId && ($order = Order::find($paidOrderId))) {
            $this->shiprocketSync->push($order);
            SendOrderConfirmations::dispatchAfterResponse($order->id);
        }
    }

    private function applyFailed(array $payload): void
    {
        $entity = $payload['payload']['payment']['entity'] ?? null;
        if (! $entity || empty($entity['order_id'])) {
            return;
        }

        DB::transaction(function () use ($entity) {
            $payment = Payment::where('razorpay_order_id', $entity['order_id'])->lockForUpdate()->first();
            if (! $payment) {
                return;
            }

            // Never downgrade an already-captured payment because of a
            // late/duplicate failed event from a different attempt.
            if ($payment->status === 'captured') {
                return;
            }

            $payment->update([
                'status' => 'failed',
                'error_code' => $entity['error_code'] ?? null,
                'error_description' => $entity['error_description'] ?? null,
            ]);

            $order = Order::where('id', $payment->order_id)->lockForUpdate()->first();
            if ($order && $order->payment_status !== 'paid') {
                $order->update(['payment_status' => 'failed']);
            }
        });
    }

    private function applyRefunded(array $payload): void
    {
        $entity = $payload['payload']['refund']['entity'] ?? null;
        $razorpayOrderId = $entity['order_id'] ?? null;
        $razorpayPaymentId = $entity['payment_id'] ?? null;

        DB::transaction(function () use ($razorpayOrderId, $razorpayPaymentId) {
            $query = Payment::query();

            if ($razorpayOrderId) {
                $query->where('razorpay_order_id', $razorpayOrderId);
            } elseif ($razorpayPaymentId) {
                $query->where('razorpay_payment_id', $razorpayPaymentId);
            } else {
                return;
            }

            $payment = $query->lockForUpdate()->first();
            if (! $payment) {
                return;
            }

            $payment->update(['status' => 'refunded']);

            $order = Order::where('id', $payment->order_id)->lockForUpdate()->first();
            if ($order) {
                $order->update(['payment_status' => 'refunded']);
            }
        });
    }
}
