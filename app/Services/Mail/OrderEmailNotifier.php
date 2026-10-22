<?php

namespace App\Services\Mail;

use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * "Order confirmed" email to the customer (only if they gave an address) and to
 * the shop mailbox(es). Sent at most once per order; never throws.
 */
class OrderEmailNotifier
{
    public function confirmed(Order $order): void
    {
        $shop = config('mail.order_notify', []);

        if (! $order->guest_email && ! $shop) {
            return;
        }

        $claimed = Order::whereKey($order->id)->whereNull('confirmation_email_sent_at')
            ->update(['confirmation_email_sent_at' => now()]);

        if (! $claimed) {
            return;
        }

        $order->loadMissing(['items', 'address']);

        // Each recipient is sent on its own, so one bad address cannot block the others.
        $failed = false;
        if ($order->guest_email) {
            $failed = ! $this->send($order->guest_email, new OrderConfirmationMail($order), $order) || $failed;
        }
        foreach ($shop as $address) {
            $failed = ! $this->send($address, new OrderConfirmationMail($order, forShop: true), $order) || $failed;
        }

        if ($failed) {
            // Let a later event (e.g. the Razorpay webhook) try again.
            Order::whereKey($order->id)->update(['confirmation_email_sent_at' => null]);
        }
    }

    private function send(string $to, OrderConfirmationMail $mail, Order $order): bool
    {
        try {
            Mail::to($to)->send($mail);

            return true;
        } catch (Throwable $e) {
            Log::warning('Order confirmation email failed', ['order' => $order->order_number, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
