<?php

namespace App\Services\Telegram;

use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "New order" alert to the shop's Telegram. Sent at most once per order, whichever of the
 * order-created / payment-verify / webhook paths gets there first. Never throws.
 */
class OrderTelegramNotifier
{
    public function __construct(private readonly TelegramSender $telegram) {}

    public function confirmed(Order $order): void
    {
        if (! $this->telegram->configured()) {
            return;
        }

        $claimed = Order::whereKey($order->id)->whereNull('confirmation_telegram_sent_at')
            ->update(['confirmation_telegram_sent_at' => now()]);

        if (! $claimed) {
            return;
        }

        try {
            if (! $this->telegram->send($this->message($order))) {
                // Let a later event (e.g. the Razorpay webhook) try again.
                Order::whereKey($order->id)->update(['confirmation_telegram_sent_at' => null]);
            }
        } catch (Throwable $e) {
            Order::whereKey($order->id)->update(['confirmation_telegram_sent_at' => null]);
            Log::warning('Order Telegram alert failed', ['order' => $order->order_number, 'error' => $e->getMessage()]);
        }
    }

    private function message(Order $order): string
    {
        $order->loadMissing(['items', 'address']);

        $lines = ["New order {$order->order_number}", ''];

        foreach ($order->items as $item) {
            $size = $item->size_label ? " ({$item->size_label})" : '';
            $lines[] = "{$item->quantity} x {$item->product_name}{$size}";
        }

        $paid = $order->payment_method === 'cod' ? 'Cash on delivery' : 'Paid online';
        $mode = $order->delivery_mode === 'delivery' ? 'Delivery' : 'Pickup';
        $when = trim(($order->scheduled_date?->format('d M Y') ?? '').' '.substr((string) $order->scheduled_time, 0, 5));

        $lines[] = '';
        $lines[] = 'Total: Rs '.number_format((float) $order->total, 0, '.', '').' ('.$paid.')';
        $lines[] = "{$mode}: {$when}";
        $lines[] = 'Customer: '.$order->guest_name.', '.($order->senderIsIndian() ? '' : $order->guest_phone_country_code.' ').preg_replace('/\D/', '', (string) $order->guest_phone);

        if ($order->address?->recipient_name) {
            $lines[] = 'Deliver to: '.$order->address->recipient_name.', '.$order->address->recipient_phone_country_code.' '.$order->address->recipient_phone;
        }

        if ($order->delivery_mode === 'delivery' && $order->address) {
            $lines[] = 'Address: '.collect([$order->address->address_line_1, $order->address->address_line_2, $order->address->city, $order->address->pincode])->filter()->implode(', ');
        }

        return implode("\n", $lines);
    }
}
