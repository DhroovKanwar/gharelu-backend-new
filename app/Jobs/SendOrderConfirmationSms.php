<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Sms\OrderSmsNotifier;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Runs after the HTTP response is sent (dispatchAfterResponse), so the customer
 * never waits on the SMS provider and no queue worker is needed.
 */
class SendOrderConfirmationSms
{
    use Dispatchable;

    public function __construct(private readonly int $orderId) {}

    public function handle(OrderSmsNotifier $notifier): void
    {
        if ($order = Order::find($this->orderId)) {
            $notifier->confirmed($order);
        }
    }
}
