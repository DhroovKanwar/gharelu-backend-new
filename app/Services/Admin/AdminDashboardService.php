<?php

namespace App\Services\Admin;

use App\Models\CustomCakeRequest;
use App\Models\Enquiry;
use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;

class AdminDashboardService
{
    /**
     * Deliberately not cached — admins need order/stock counts to be
     * current, and this endpoint is low-traffic (admin-only), so the
     * staleness/complexity trade-off of caching isn't worth it here.
     */
    public function stats(): array
    {
        return [
            'orders' => [
                'total' => Order::count(),
                'today' => Order::whereDate('created_at', today())->count(),
                'new' => Order::where('order_status', 'new')->count(),
                'completed' => Order::where('order_status', 'completed')->count(),
                'cancelled' => Order::where('order_status', 'cancelled')->count(),
            ],
            'customers' => [
                // Excludes admin accounts — only role=null users are customers.
                'total' => User::whereNull('role')->count(),
            ],
            'products' => [
                'active' => Product::where('is_active', true)->count(),
                'lowStock' => Product::where('stock_status', 'low_stock')->count(),
                'outOfStock' => Product::where('stock_status', 'out_of_stock')->count(),
            ],
            'customCakeRequests' => [
                'pending' => CustomCakeRequest::where('status', 'new')->count(),
            ],
            'enquiries' => [
                'pending' => Enquiry::where('status', 'new')->count(),
            ],
            'newsletter' => [
                'subscribers' => NewsletterSubscriber::count(),
            ],
            'revenue' => [
                // Only orders actually marked paid count as revenue — an
                // unpaid COD order is not revenue yet.
                'total' => (float) Order::where('payment_status', 'paid')->sum('total'),
                'today' => (float) Order::where('payment_status', 'paid')
                    ->whereDate('created_at', today())
                    ->sum('total'),
            ],
        ];
    }
}
