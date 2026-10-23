<?php

namespace App\Services\Sms;

use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Order confirmed" SMS to the customer and the shop. Sent at most once per
 * order, whichever of the order-created / payment-verify / webhook paths gets
 * there first. Never throws: an SMS problem must not break an order.
 */
class OrderSmsNotifier
{
    public function __construct(private readonly SmsSender $sms) {}

    public function confirmed(Order $order): void
    {
        // Atomic claim so two requests racing for the same order send only one set of SMS.
        $claimed = Order::whereKey($order->id)->whereNull('confirmation_sms_sent_at')
            ->update(['confirmation_sms_sent_at' => now()]);

        if (! $claimed) {
            return;
        }

        try {
            $total = number_format((float) $order->total, 0, '.', '');
            $mode = $order->delivery_mode === 'delivery' ? 'Delivery' : 'Pickup';
            $date = $order->scheduled_date?->format('d M');
            $when = trim($date.($order->scheduled_time ? ', '.date('h:i A', strtotime((string) $order->scheduled_time)) : ''));
            $pay = $order->payment_method === 'cod' ? 'Pay on '.strtolower($mode === 'Delivery' ? 'delivery' : 'pickup') : 'Paid online';

            // The SMS provider only reaches Indian numbers; a foreign number's last 10 digits could belong to someone else.
            if ($order->senderIsIndian()) {
                $this->sms->send((string) $order->guest_phone, 'customer', [
                'name' => strtok((string) $order->guest_name, ' ') ?: 'Customer',
                'order' => $order->order_number,
                'total' => $total,
                'mode' => $mode,
                'when' => $when,
                'pay' => $pay,
                ]);
            }

            foreach (config('sms.admin_phones', []) as $adminPhone) {
                $this->sms->send($adminPhone, 'admin', [
                    'order' => $order->order_number,
                    'name' => $order->guest_name,
                    'phone' => $order->senderIsIndian()
                        ? substr(preg_replace('/\D/', '', (string) $order->guest_phone), -10)
                        : ($order->guest_phone_country_code.preg_replace('/\D/', '', (string) $order->guest_phone)),
                    'total' => $total,
                    'mode' => $mode,
                    'date' => $date,
                ]);
            }
        } catch (Throwable $e) {
            // Allow a later event (e.g. the Razorpay webhook) to try again.
            Order::whereKey($order->id)->update(['confirmation_sms_sent_at' => null]);
            Log::warning('Order confirmation SMS failed', ['order' => $order->order_number, 'error' => $e->getMessage()]);
        }
    }
}
