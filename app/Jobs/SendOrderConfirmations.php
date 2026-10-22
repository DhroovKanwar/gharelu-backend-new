<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Mail\OrderEmailNotifier;
use App\Services\Sms\OrderSmsNotifier;
use App\Services\Telegram\OrderTelegramNotifier;
use App\Services\WhatsApp\OrderWhatsAppNotifier;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Sends the order-confirmation SMS and emails. Runs after the HTTP response is sent
 * (dispatchAfterResponse), so the customer never waits on a provider and no queue
 * worker is needed. Each notifier sends at most once per order and never throws.
 */
class SendOrderConfirmations
{
    use Dispatchable;

    public function __construct(private readonly int $orderId) {}

    public function handle(OrderSmsNotifier $sms, OrderEmailNotifier $email, OrderTelegramNotifier $telegram, OrderWhatsAppNotifier $whatsapp): void
    {
        if ($order = Order::find($this->orderId)) {
            $sms->confirmed($order);
            $email->confirmed($order);
            $telegram->confirmed($order);
            $whatsapp->confirmed($order);
        }
    }
}
