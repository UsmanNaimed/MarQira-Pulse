<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to platform Owner accounts.
 *
 * Platform-level administration (account management) is Owner-only. This is
 * enforced server-side; the dashboard also hides the UI, but that is never the
 * security boundary (see §2 / §30).
 */
class EnsureOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isOwner()) {
            return response()->json([
                'error' => 'This action requires platform Owner privileges.',
            ], 403);
        }

        return $next($request);
    }
}
