<?php

namespace App\Providers;

use App\Models\Order;
use App\Policies\OrderPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Applied via `throttle:auth` on /auth/register and /auth/login.
        // Keyed by IP (not email) so it can't be used to enumerate whether
        // an email is registered, and so one IP can't hammer many accounts.
        RateLimiter::for('auth', function ($request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Applied via `throttle:orders` on POST /orders. This is on top of
        // the default 'api' throttle (60/min) that every /api route already
        // gets from bootstrap/app.php's withRouting(api: ...) — tighter here
        // because order creation writes to the DB and sends a real order.
        RateLimiter::for('orders', function ($request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Applied via `throttle:payments` on create-order/verify (both the
        // /v1 and legacy alias routes). The webhook is deliberately NOT
        // under this limiter — it's server-to-server from Razorpay, and an
        // aggressive limit here could drop legitimate webhook retries.
        RateLimiter::for('payments', function ($request) {
            return Limit::perMinute(20)->by($request->ip());
        });

        // Explicit over relying on naming-convention auto-discovery.
        Gate::policy(Order::class, OrderPolicy::class);
    }
}
