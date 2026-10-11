<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\ShiprocketOrderSync;
use Illuminate\Console\Command;

class SyncShiprocketOrders extends Command
{
    protected $signature = 'shiprocket:sync';

    protected $description = 'Send paid delivery orders that are not in Shiprocket yet';

    public function handle(ShiprocketOrderSync $sync): int
    {
        $orders = Order::where('delivery_mode', 'delivery')
            ->where('payment_status', 'paid')
            ->whereNull('shiprocket_order_id')
            ->get();

        foreach ($orders as $order) {
            $this->line($order->order_number.': '.($sync->push($order) ? 'sent' : 'failed (see log)'));
        }

        $this->info($orders->count().' order(s) processed.');

        return self::SUCCESS;
    }
}
