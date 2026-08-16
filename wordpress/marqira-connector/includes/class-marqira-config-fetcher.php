<?php
/**
 * Config fetcher for MarQira Connector.
 *
 * Fetches allowed IPs and Cloudflare ranges from the MarQira API.
 * Caches in transients with fallback to bundled defaults.
 *
 * @package Marqira_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Marqira_Config_Fetcher
 */
class Marqira_Config_Fetcher {

	/**
	 * Transient TTL (24 hours).
	 */
	const CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * Get allowed MarQira infrastructure IPs.
	 *
	 * Fetches from API, caches for 24h, falls back to bundled default.
	 *
	 * @return array List of IPs/CIDRs.
	 */
	public static function get_allowed_ips() {
		// Check cache
		$cached = get_transient( 'marqira_allowed_ips' );
		if ( false !== $cached && is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		// Fetch from API if enrolled
		if ( Marqira_Enrollment::is_enrolled() ) {
			$fetched = self::fetch_from_api( '/api/v1/config/allowed-ips', 'allowed_ips' );
			if ( false !== $fetched && is_array( $fetched ) && ! empty( $fetched ) ) {
				set_transient( 'marqira_allowed_ips', $fetched, self::CACHE_TTL );
				return $fetched;
			}
		}

		// Fallback to bundled default
		$default = array( '187.77.136.105' );
		set_transient( 'marqira_allowed_ips', $default, self::CACHE_TTL );
		return $default;
	}

	/**
	 * Get Cloudflare IP ranges.
	 *
	 * Fetches from API, caches for 24h, falls back to bundled ranges.
	 *
	 * @return array Combined IPv4 + IPv6 ranges.
	 */
	public static function get_cloudflare_ranges() {
		// Check cache
		$cached = get_transient( 'marqira_cloudflare_ranges' );
		if ( false !== $cached && is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		// Fetch from API if enrolled
		if ( Marqira_Enrollment::is_enrolled() ) {
			$fetched = self::fetch_cloudflare_ranges_from_api();
			if ( false !== $fetched && is_array( $fetched ) && ! empty( $fetched ) ) {
				set_transient( 'marqira_cloudflare_ranges', $fetched, self::CACHE_TTL );
				return $fetched;
			}
		}

		// Fallback to bundled ranges.
		//
		// IMPORTANT: call get_bundled_ranges() (compiled-in list only) and
		// NEVER get_all_ranges() here — get_all_ranges() calls back into this
		// method, which would recurse infinitely and exhaust PHP memory when
		// the cache is empty and the site is not enrolled / the API is down.
		$bundled = Marqira_Cloudflare::get_bundled_ranges();
		set_transient( 'marqira_cloudflare_ranges', $bundled, self::CACHE_TTL );
		return $bundled;
	}

	/**
	 * Fetch a config value from the API.
	 *
	 * @param string $path      API path.
	 * @param string $json_key  Key in JSON response.
	 * @return array|false      Value on success, false on failure.
	 */
	private static function fetch_from_api( $path, $json_key ) {
		$credentials = Marqira_Enrollment::get_credentials();
		if ( empty( $credentials ) ) {
			return false;
		}

		$api_url = Marqira_Enrollment::get_api_url();
		$url     = rtrim( $api_url, '/' ) . $path;

		// Build HMAC-authenticated request
		$headers = Marqira_Hmac_Client::generate_headers( 'GET', $path, array(), '', $credentials );

		if ( empty( $headers ) ) {
			return false;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code !== 200 ) {
			return false;
		}

		$body   = wp_remote_retrieve_body( $response );
		$result = json_decode( $body, true );

		if ( ! is_array( $result ) || ! isset( $result[ $json_key ] ) ) {
			return false;
		}

		return $result[ $json_key ];
	}

	/**
	 * Fetch Cloudflare ranges from API.
	 *
	 * @return array|false Combined ranges or false on failure.
	 */
	private static function fetch_cloudflare_ranges_from_api() {
		$credentials = Marqira_Enrollment::get_credentials();
		if ( empty( $credentials ) ) {
			return false;
		}

		$api_url = Marqira_Enrollment::get_api_url();
		$path    = '/api/v1/config/cloudflare-ranges';
		$url     = rtrim( $api_url, '/' ) . $path;

		$headers = Marqira_Hmac_Client::generate_headers( 'GET', $path, array(), '', $credentials );

		if ( empty( $headers ) ) {
			return false;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code !== 200 ) {
			return false;
		}

		$body   = wp_remote_retrieve_body( $response );
		$result = json_decode( $body, true );

		if ( ! is_array( $result ) ) {
			return false;
		}

		// Combine IPv4 and IPv6 ranges
		$ipv4 = isset( $result['ipv4'] ) && is_array( $result['ipv4'] ) ? $result['ipv4'] : array();
		$ipv6 = isset( $result['ipv6'] ) && is_array( $result['ipv6'] ) ? $result['ipv6'] : array();

		return array_merge( $ipv4, $ipv6 );
	}

	/**
	 * Clear all config caches (for testing/troubleshooting).
	 */
	public static function clear_cache() {
		delete_transient( 'marqira_allowed_ips' );
		delete_transient( 'marqira_cloudflare_ranges' );
	}
}
