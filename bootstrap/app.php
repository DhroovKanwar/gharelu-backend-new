<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
 ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Cloudflare / a load balancer every request arrives from the proxy, so per-visitor rate
        // limits would be shared by all customers. Set TRUSTED_PROXIES in .env (`*` or a comma-separated
        // list of proxy IPs) so the real visitor IP is read from X-Forwarded-For. Unset = trust nobody.
        $trusted = trim((string) env('TRUSTED_PROXIES', ''));
        $middleware->trustProxies(at: $trusted === '' ? null : ($trusted === '*' ? '*' : array_map('trim', explode(',', $trusted))));

        $middleware->api(append: [
            \App\Http\Middleware\SecurityHeaders::class,
        ]);

        $middleware->alias([
            'admin.role' => \App\Http\Middleware\EnsureAdminRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
