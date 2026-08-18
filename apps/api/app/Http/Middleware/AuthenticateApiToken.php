<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token authentication for the external automation API (§12/§13).
 *
 * The dashboard SPA authenticates with Sanctum sessions; external clients
 * (e.g. n8n) instead present a raw API token as `Authorization: Bearer <token>`.
 * This guard:
 *
 *   1. Resolves the raw token to an ACTIVE (non-revoked, non-expired) ApiToken,
 *      comparing only SHA-256 hashes. Any failure returns 401 without leaking
 *      which check failed.
 *   2. Enforces the token's optional CIDR allowlist against the (trusted-proxy
 *      resolved) client IP. A request from outside the allowlist is 403.
 *   3. Establishes the TenantContext from the token's organization and binds the
 *      request's authenticated user to the token's OWNING user. From here on the
 *      request is authorized EXACTLY as that user — `Site::visibleTo($user)` and
 *      every policy behave identically to a dashboard session, so a token can
 *      never reach a website or analytics its user is not authorized for, even
 *      by manipulating a UUID in the URL (that yields a 404).
 *   4. Records `last_used_at` for observability.
 *
 * The resolved token is stashed on the request as the `api_token` attribute so
 * downstream handlers can check abilities (see EnsureTokenAbility).
 */
class AuthenticateApiToken
{
    public function __construct(private TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $raw = $this->bearerToken($request);

        if ($raw === null || $raw === '') {
            return $this->unauthorized('Missing API token.');
        }

        $token = ApiToken::findActiveByRawToken($raw);

        if ($token === null) {
            return $this->unauthorized('Invalid or expired API token.');
        }

        // The token must resolve to a real, active user and organization,
        // otherwise it cannot be authorized against anything (fail closed).
        $user = $token->user;
        if ($user === null || ! $user->isActive()) {
            return $this->unauthorized('Invalid or expired API token.');
        }

        $organization = $token->organization;
        if ($organization === null) {
            return $this->unauthorized('Invalid or expired API token.');
        }

        // Enforce the CIDR allowlist if one is configured on the token.
        $allowed = $token->allowed_ips ?? [];
        if (! empty($allowed) && ! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            return response()->json([
                'error' => 'Requests from this IP address are not allowed for this token.',
            ], 403);
        }

        // Establish tenant context and bind the request user to the token's
        // owning user. Everything downstream authorizes as this user.
        $this->tenantContext->setOrganization($organization);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('api_token', $token);

        // Best-effort usage tracking — never block the request on it.
        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }

    /**
     * Extract the bearer token, tolerating any casing of the scheme.
     */
    private function bearerToken(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');

        if (preg_match('/^\s*Bearer\s+(.+)$/i', $header, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function unauthorized(string $message): Response
    {
        return response()->json(['error' => $message], 401);
    }
}
