<?php

namespace App\Services\Admin;

use App\Models\CustomCakeRequest;
use App\Models\Enquiry;
use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdminDashboardService
{
    /**
     * Deliberately not cached — admins need order/stock counts to be
     * current, and this endpoint is low-traffic (admin-only), so the
     * staleness/complexity trade-off of caching isn't worth it here.
     *
     * $range (optional) scopes orders/revenue to a window, e.g.
     * ['from' => Carbon, 'to' => Carbon]. Everything else here is a
     * point-in-time snapshot (current stock, pending queues, subscriber
     * count) that isn't meaningful to scope the same way, so it stays as-is
     * regardless of $range — null preserves the original all-time behavior.
     */
    public function stats(?array $range = null): array
    {
        $ordersQuery = Order::query();
        $revenueQuery = Order::query()->where('payment_status', 'paid');

        if ($range) {
            $ordersQuery->whereBetween('created_at', [$range['from'], $range['to']]);
            $revenueQuery->whereBetween('created_at', [$range['from'], $range['to']]);
        }

        return [
            'orders' => [
                'total' => (clone $ordersQuery)->count(),
                'today' => Order::whereDate('created_at', today())->count(),
                'new' => (clone $ordersQuery)->where('order_status', 'new')->count(),
                'completed' => (clone $ordersQuery)->where('order_status', 'completed')->count(),
                'cancelled' => (clone $ordersQuery)->where('order_status', 'cancelled')->count(),
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
                'total' => (float) (clone $revenueQuery)->sum('total'),
                'today' => (float) Order::where('payment_status', 'paid')
                    ->whereDate('created_at', today())
                    ->sum('total'),
            ],
        ];
    }

    /**
     * Top products by quantity sold within $range (all-time when null).
     * Cancelled orders never count towards "best selling" — the item was
     * ordered but never actually fulfilled/paid for.
     */
    public function bestSellers(?array $range = null, int $limit = 10): array
    {
        $query = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.order_status', '!=', 'cancelled')
            ->select(
                'order_items.product_id',
                DB::raw('SUM(order_items.quantity) as quantity_sold'),
                DB::raw('SUM(order_items.line_total) as revenue'),
            )
            ->groupBy('order_items.product_id')
            ->orderByDesc('quantity_sold')
            ->limit($limit);

        if ($range) {
            $query->whereBetween('orders.created_at', [$range['from'], $range['to']]);
        }

        $rows = $query->get();

        $products = Product::query()
            ->with(['images' => fn ($q) => $q->where('is_primary', true)])
            ->whereIn('id', $rows->pluck('product_id'))
            ->get()
            ->keyBy('id');

        return $rows
            ->map(function ($row) use ($products) {
                $product = $products->get($row->product_id);

                return [
                    'productId' => $row->product_id,
                    'name' => $product?->name ?? 'Deleted product',
                    'slug' => $product?->slug,
                    'image' => $this->imageUrl($product?->images->first()?->path),
                    'quantitySold' => (int) $row->quantity_sold,
                    'revenue' => (float) $row->revenue,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Seeded demo images store a full external URL in `path` rather than a
     * local storage-relative path — pass those through as-is instead of
     * double-prefixing them with the storage disk URL.
     */
    private function imageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            ? $path
            : Storage::disk('public')->url($path);
    }
}
