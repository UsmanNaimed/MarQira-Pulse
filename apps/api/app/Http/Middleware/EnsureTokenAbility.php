<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces that the authenticating API token was granted a required ability
 * before a route runs (§12/§22).
 *
 * Abilities are a coarse capability whitelist (`sites:read`, `sites:status`,
 * `sites:read-origin`). This middleware is applied per-route with the required
 * ability as a parameter, e.g. `->middleware('token.ability:sites:read')`.
 * It must run AFTER AuthenticateApiToken, which stashes the resolved token on
 * the request.
 */
class EnsureTokenAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $token = $request->attributes->get('api_token');

        if (! $token instanceof ApiToken || ! $token->hasAbility($ability)) {
            return response()->json([
                'error' => "This token is not permitted to perform '{$ability}'.",
            ], 403);
        }

        return $next($request);
    }
}
