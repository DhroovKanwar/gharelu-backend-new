<?php

namespace App\Services\WhatsApp;

use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Order confirmed" WhatsApp message to the customer. Sent at most once per order, whichever of
 * the order-created / payment-verify / webhook paths gets there first. Never throws.
 */
class OrderWhatsAppNotifier
{
    public function __construct(private readonly WhatsAppSender $whatsapp) {}

    public function confirmed(Order $order): void
    {
        // The sender must be an Indian number: the sender class messages +91 plus the last 10 digits.
        if (! $this->whatsapp->configured() || ! $order->guest_phone || ! $order->senderIsIndian()) {
            return;
        }

        $claimed = Order::whereKey($order->id)->whereNull('confirmation_whatsapp_sent_at')
            ->update(['confirmation_whatsapp_sent_at' => now()]);

        if (! $claimed) {
            return;
        }

        try {
            $sent = $this->whatsapp->sendTemplate((string) $order->guest_phone, (string) config('whatsapp.customer_template'), [
                strtok((string) $order->guest_name, ' ') ?: 'Customer',
                $order->order_number,
                number_format((float) $order->total, 0, '.', ''),
                $order->scheduled_date?->format('d M') ?? '-',
            ]);

            if (! $sent) {
                // Let a later event (e.g. the Razorpay webhook) try again.
                Order::whereKey($order->id)->update(['confirmation_whatsapp_sent_at' => null]);
            }
        } catch (Throwable $e) {
            Order::whereKey($order->id)->update(['confirmation_whatsapp_sent_at' => null]);
            Log::warning('Order WhatsApp message failed', ['order' => $order->order_number, 'error' => $e->getMessage()]);
        }
    }
}
