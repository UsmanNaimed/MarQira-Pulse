<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Origin IP Detection Service
 *
 * Analyzes DNS records and server-reported IPs to determine the most likely
 * origin IP address for a WordPress site, with confidence scoring.
 */
class OriginDetector
{
    /**
     * Analyze a site's domain and server IP to detect origin candidates.
     *
     * @param string $domain The site's domain name
     * @param string|null $serverIp The SERVER_ADDR reported by WordPress
     * @return array Analysis result with origin_ip, source, confidence, and metadata
     */
    public function analyze(string $domain, ?string $serverIp): array
    {
        $result = [
            'origin_ip' => null,
            'source' => null,
            'confidence' => 'unknown',
            'metadata' => [
                'dns_a_records' => [],
                'dns_aaaa_records' => [],
                'server_ip' => $serverIp,
                'server_ip_type' => null,
                'analysis_timestamp' => now()->toIso8601String(),
            ],
        ];

        // Clean domain (remove protocol, path, port)
        $cleanDomain = $this->cleanDomain($domain);
        if (empty($cleanDomain)) {
            $result['metadata']['error'] = 'Invalid domain';
            return $result;
        }

        // Fetch DNS records
        $dnsA = $this->getDnsRecords($cleanDomain, DNS_A);
        $dnsAAAA = $this->getDnsRecords($cleanDomain, DNS_AAAA);

        $result['metadata']['dns_a_records'] = $dnsA;
        $result['metadata']['dns_aaaa_records'] = $dnsAAAA;

        // Classify server IP
        if ($serverIp) {
            $result['metadata']['server_ip_type'] = $this->classifyIp($serverIp);
        }

        // Determine origin and confidence
        $this->determineOrigin($result, $serverIp, $dnsA, $dnsAAAA);

        return $result;
    }

    /**
     * Clean a domain string to extract just the hostname.
     *
     * @param string $domain
     * @return string|null
     */
    private function cleanDomain(string $domain): ?string
    {
        // Remove protocol
        $domain = preg_replace('#^https?://#i', '', $domain);

        // Remove path and query string
        $domain = preg_replace('#[/?].*$#', '', $domain);

        // Remove port
        $domain = preg_replace('#:\d+$#', '', $domain);

        // Remove www. prefix for DNS lookup (optional, but common)
        // $domain = preg_replace('#^www\.#i', '', $domain);

        return filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) ? $domain : null;
    }

    /**
     * Fetch DNS records for a domain.
     *
     * @param string $domain
     * @param int $type DNS_A or DNS_AAAA
     * @return array
     */
    private function getDnsRecords(string $domain, int $type): array
    {
        $ips = [];

        try {
            $records = @dns_get_record($domain, $type);

            if ($records === false) {
                return $ips;
            }

            foreach ($records as $record) {
                if ($type === DNS_A && isset($record['ip'])) {
                    $ips[] = $record['ip'];
                } elseif ($type === DNS_AAAA && isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        } catch (\Exception $e) {
            Log::warning('DNS lookup failed', [
                'domain' => $domain,
                'type' => $type === DNS_A ? 'A' : 'AAAA',
                'error' => $e->getMessage(),
            ]);
        }

        return array_values(array_unique($ips));
    }

    /**
     * Classify an IP address (public, private, reserved, cloudflare, etc.).
     *
     * @param string $ip
     * @return string
     */
    private function classifyIp(string $ip): string
    {
        // Validate IP
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return 'invalid';
        }

        // Check if private
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)) {
            // Not private, check if reserved
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE)) {
                // Public IP
                if ($this->isCloudflareIp($ip)) {
                    return 'cloudflare';
                }

                return 'public';
            }

            return 'reserved';
        }

        return 'private';
    }

    /**
     * Check if an IP is within Cloudflare's ranges (simplified check).
     *
     * @param string $ip
     * @return bool
     */
    private function isCloudflareIp(string $ip): bool
    {
        // Simplified Cloudflare IPv4 ranges (not exhaustive, for demo)
        // In production, you'd maintain the full list or use an API
        $cfRanges = [
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '190.93.240.0/20',
            '188.114.96.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '162.158.0.0/15',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '131.0.72.0/22',
        ];

        foreach ($cfRanges as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an IP is within a CIDR range.
     *
     * @param string $ip
     * @param string $cidr
     * @return bool
     */
    private function ipInRange(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr);

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = ~((1 << (32 - $mask)) - 1);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /**
     * Determine the most likely origin IP and confidence level.
     *
     * @param array &$result
     * @param string|null $serverIp
     * @param array $dnsA
     * @param array $dnsAAAA
     * @return void
     */
    private function determineOrigin(array &$result, ?string $serverIp, array $dnsA, array $dnsAAAA): void
    {
        $allDnsIps = array_merge($dnsA, $dnsAAAA);
        $serverIpType = $result['metadata']['server_ip_type'] ?? null;

        // Case 1: SERVER_ADDR matches a DNS record exactly
        if ($serverIp && in_array($serverIp, $allDnsIps, true)) {
            $result['origin_ip'] = $serverIp;
            $result['source'] = in_array($serverIp, $dnsA, true) ? 'dns_a_match' : 'dns_aaaa_match';
            $result['confidence'] = 'high';
            $result['metadata']['match_reason'] = 'SERVER_ADDR matches DNS record';
            return;
        }

        // Case 2: SERVER_ADDR is public and valid, but doesn't match DNS (likely behind CDN/proxy)
        if ($serverIp && $serverIpType === 'public') {
            $result['origin_ip'] = $serverIp;
            $result['source'] = 'server_addr';
            $result['confidence'] = 'medium';
            $result['metadata']['match_reason'] = 'SERVER_ADDR is public but does not match DNS (possible CDN/proxy)';
            return;
        }

        // Case 3: SERVER_ADDR is Cloudflare, use first DNS A record if available
        if ($serverIp && $serverIpType === 'cloudflare' && ! empty($dnsA)) {
            $result['origin_ip'] = $dnsA[0];
            $result['source'] = 'dns_a_cloudflare_detected';
            $result['confidence'] = 'medium';
            $result['metadata']['match_reason'] = 'SERVER_ADDR is Cloudflare, using first DNS A record';
            return;
        }

        // Case 4: SERVER_ADDR is private/reserved, use first public DNS record
        if ($serverIp && in_array($serverIpType, ['private', 'reserved'], true) && ! empty($dnsA)) {
            $result['origin_ip'] = $dnsA[0];
            $result['source'] = 'dns_a_server_private';
            $result['confidence'] = 'low';
            $result['metadata']['match_reason'] = 'SERVER_ADDR is private/reserved, using first DNS A record';
            return;
        }

        // Case 5: No SERVER_ADDR, but we have DNS records
        if (! $serverIp && ! empty($dnsA)) {
            $result['origin_ip'] = $dnsA[0];
            $result['source'] = 'dns_a_only';
            $result['confidence'] = 'low';
            $result['metadata']['match_reason'] = 'No SERVER_ADDR, using first DNS A record';
            return;
        }

        // Case 6: No reliable data
        $result['origin_ip'] = null;
        $result['source'] = null;
        $result['confidence'] = 'unknown';
        $result['metadata']['match_reason'] = 'Unable to determine origin IP';
    }
}
