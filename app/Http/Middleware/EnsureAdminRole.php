<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorization gate for every /v1/admin/* route. Must run after
 * `auth:sanctum`. The check is against the authenticated user's `role`
 * column, not the token — so a customer token can never pass this, no
 * matter which endpoint issued it, and there is nothing a stolen token
 * alone can do to escalate privilege.
 */
class EnsureAdminRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasAnyRole(...$roles)) {
            abort(403, 'You are not authorized to access this resource.');
        }

        return $next($request);
    }
}
