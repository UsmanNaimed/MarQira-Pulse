<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the TenantContext for an authenticated dashboard user.
 *
 * The dashboard authenticates via Sanctum's stateful session guard. Once a user
 * is authenticated we resolve their organization and set it on the singleton
 * TenantContext so every downstream query is tenant-scoped. This mirrors how the
 * HMAC middleware sets tenant context for plugin requests — the rest of the
 * application never reads auth()->user() directly for tenancy (see §17).
 *
 * Fails closed: a user with no organization membership is rejected with 403.
 */
class SetTenantFromUser
{
    public function __construct(private TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $organization = $user->primaryOrganization();

        if (! $organization) {
            return response()->json([
                'error' => 'Your account is not associated with any organization.',
            ], 403);
        }

        $this->tenantContext->setOrganization($organization);

        return $next($request);
    }
}
