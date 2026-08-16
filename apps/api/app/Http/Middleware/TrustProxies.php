<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;

/**
 * Trusted proxy configuration for MarQira Pulse.
 *
 * The set of trusted proxies is driven entirely by the TRUSTED_PROXIES env var
 * (e.g. the Coolify/Traefik Docker subnet). It is NEVER hardcoded to "*" — a
 * wildcard would let any client spoof X-Forwarded-* headers and defeat IP
 * allow-listing. When the env var is empty, no proxies are trusted (fail safe).
 */
class TrustProxies
{
    /**
     * The forwarded headers that may be trusted from a configured proxy.
     */
    public const HEADERS = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_AWS_ELB;

    /**
     * Parse TRUSTED_PROXIES into an array of IPs/CIDRs.
     *
     * @return array<int, string>
     */
    public static function proxies(): array
    {
        $value = env('TRUSTED_PROXIES', '');

        if (is_string($value) && trim($value) !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $value))));
        }

        return [];
    }
}
