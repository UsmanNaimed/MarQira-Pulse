<?php
/**
 * Cloudflare-aware client IP detection.
 *
 * @package Marqira_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

/**
 * Class Marqira_Cloudflare
 *
 * Detects Cloudflare proxy requests and resolves the real client IP
 * from the trusted CF-Connecting-IP header when appropriate.
 */
class Marqira_Cloudflare {

        /**
         * Current Cloudflare IPv4 ranges.
         *
         * Source: https://www.cloudflare.com/ips-v4
         *
         * @var string[]
         */
        const CLOUDFLARE_IPV4_RANGES = array(
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
        );

        /**
         * Current Cloudflare IPv6 ranges.
         *
         * Source: https://www.cloudflare.com/ips-v6
         *
         * @var string[]
         */
        const CLOUDFLARE_IPV6_RANGES = array(
                '2400:cb00::/32',
                '2606:4700::/32',
                '2803:f800::/32',
                '2405:b500::/32',
                '2405:8100::/32',
                '2a06:98c0::/29',
                '2c0f:f248::/32',
        );

        /**
         * Return the bundled (compiled-in) Cloudflare ranges only.
         *
         * This method performs NO network access and NO calls into the config
         * fetcher, so it is always safe to use as a terminal fallback. The
         * config fetcher's fallback path MUST call this method (never
         * get_all_ranges()) to avoid the recursion that previously exhausted
         * PHP memory:
         *
         *     get_all_ranges() -> Config_Fetcher::get_cloudflare_ranges()
         *         -> (fallback) get_all_ranges() -> ... infinite loop.
         *
         * @return string[]
         */
        public static function get_bundled_ranges() {
                return array_merge( self::CLOUDFLARE_IPV4_RANGES, self::CLOUDFLARE_IPV6_RANGES );
        }

        /**
         * Return all Cloudflare ranges (IPv4 + IPv6).
         *
         * Phase 4: Prefers dynamic ranges fetched (and cached) by the config
         * fetcher, and falls back to the bundled ranges. The bundled fallback
         * uses get_bundled_ranges() directly so this method can never recurse
         * through the config fetcher.
         *
         * @return string[]
         */
        public static function get_all_ranges() {
                // Try to fetch from API (cached for 24h by the config fetcher).
                $dynamic_ranges = Marqira_Config_Fetcher::get_cloudflare_ranges();

                // If dynamic fetch successful, use it.
                if ( ! empty( $dynamic_ranges ) && is_array( $dynamic_ranges ) ) {
                        return $dynamic_ranges;
                }

                // Terminal fallback — bundled ranges only, never recursive.
                return self::get_bundled_ranges();
        }

        /**
         * Determine whether an IP belongs to Cloudflare.
         *
         * @param string $ip IP address.
         * @return bool
         */
        public static function is_cloudflare_ip( $ip ) {
                $ip = Marqira_IP_Utils::normalize( $ip );
                if ( false === $ip ) {
                        return false;
                }

                return Marqira_IP_Utils::ip_in_list( $ip, self::get_all_ranges() );
        }

        /**
         * Resolve the real client IP for the current request.
         *
         * Resolution order (per spec §9):
         *   1. Start from REMOTE_ADDR.
         *   2. If REMOTE_ADDR is within a known Cloudflare range AND a valid
         *      CF-Connecting-IP header is present, use CF-Connecting-IP.
         *   3. Otherwise use REMOTE_ADDR.
         *
         * Arbitrary X-Forwarded-For headers are never trusted.
         *
         * @return array {
         *     @type string $ip         Resolved client IP address.
         *     @type string $source     'REMOTE_ADDR' or 'CF-Connecting-IP'.
         *     @type bool   $cloudflare Whether the request came via Cloudflare.
         * }
         */
        public static function get_real_client_ip() {
                $remote_addr = isset( $_SERVER['REMOTE_ADDR'] )
                        ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
                        : '';

                $remote_addr = Marqira_IP_Utils::normalize( $remote_addr );

                $result = array(
                        'ip'         => false !== $remote_addr ? $remote_addr : '',
                        'source'     => 'REMOTE_ADDR',
                        'cloudflare' => false,
                );

                if ( false === $remote_addr ) {
                        return $result;
                }

                if ( ! self::is_cloudflare_ip( $remote_addr ) ) {
                        // Not a Cloudflare proxy — use REMOTE_ADDR as-is.
                        return $result;
                }

                $result['cloudflare'] = true;

                // REMOTE_ADDR is a Cloudflare edge node; trust CF-Connecting-IP.
                if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
                        $cf_ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
                        $cf_ip = Marqira_IP_Utils::normalize( $cf_ip );

                        if ( false !== $cf_ip ) {
                                $result['ip']     = $cf_ip;
                                $result['source'] = 'CF-Connecting-IP';
                        }
                }

                return $result;
        }
}
