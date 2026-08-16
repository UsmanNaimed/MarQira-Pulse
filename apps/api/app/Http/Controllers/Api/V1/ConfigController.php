<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Config controller for dynamic plugin configuration.
 *
 * Returns centrally managed allowed IPs and Cloudflare ranges.
 * Protected by HMAC authentication middleware.
 */
class ConfigController extends Controller
{
    /**
     * Return allowed MarQira infrastructure IPs.
     *
     * GET /api/v1/config/allowed-ips
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function allowedIps()
    {
        $allowedIps = config('marqira.allowed_ips', ['187.77.136.105']);

        return response()->json([
            'allowed_ips' => $allowedIps,
        ], 200);
    }

    /**
     * Return Cloudflare IP ranges.
     *
     * GET /api/v1/config/cloudflare-ranges
     *
     * For Phase 4: returns bundled static list.
     * Future: could fetch from Cloudflare API and cache.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function cloudflareRanges()
    {
        // Cloudflare IPv4 ranges (as of Phase 4)
        $ipv4Ranges = [
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '108.162.192.0/18',
            '131.0.72.0/22',
            '141.101.64.0/18',
            '162.158.0.0/15',
            '172.64.0.0/13',
            '173.245.48.0/20',
            '188.114.96.0/20',
            '190.93.240.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
        ];

        // Cloudflare IPv6 ranges
        $ipv6Ranges = [
            '2400:cb00::/32',
            '2606:4700::/32',
            '2803:f800::/32',
            '2405:b500::/32',
            '2405:8100::/32',
            '2a06:98c0::/29',
            '2c0f:f248::/32',
        ];

        return response()->json([
            'ipv4' => $ipv4Ranges,
            'ipv6' => $ipv6Ranges,
        ], 200);
    }
}
