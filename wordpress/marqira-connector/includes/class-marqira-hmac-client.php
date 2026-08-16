<?php
/**
 * HMAC client for MarQira API authentication.
 *
 * Generates HMAC-SHA256 signatures for plugin → API requests.
 *
 * @package Marqira_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Marqira_Hmac_Client
 */
class Marqira_Hmac_Client {

	/**
	 * Generate HMAC authentication headers for a request.
	 *
	 * @param string $method     HTTP method (GET, POST).
	 * @param string $path       Request path (e.g., /api/v1/heartbeat).
	 * @param array  $query      Query parameters.
	 * @param string $body       Request body (JSON string or empty).
	 * @param array  $credentials Site credentials from enrollment.
	 * @return array Headers array for wp_remote_post/get.
	 */
	public static function generate_headers( $method, $path, $query, $body, $credentials ) {
		if ( empty( $credentials['site_uuid'] ) || empty( $credentials['site_secret'] ) || empty( $credentials['kid'] ) ) {
			return array();
		}

		$timestamp = (string) time();
		$nonce     = self::generate_nonce();
		$signature = self::compute_signature( $method, $path, $query, $timestamp, $nonce, $body, $credentials['site_secret'] );

		return array(
			'X-MarQira-Site'      => $credentials['site_uuid'],
			'X-MarQira-Timestamp' => $timestamp,
			'X-MarQira-Nonce'     => $nonce,
			'X-MarQira-Kid'       => $credentials['kid'],
			'X-MarQira-Signature' => $signature,
			'Content-Type'        => 'application/json',
		);
	}

	/**
	 * Compute HMAC-SHA256 signature.
	 *
	 * @param string $method    HTTP method.
	 * @param string $path      Request path.
	 * @param array  $query     Query parameters.
	 * @param string $timestamp Unix timestamp.
	 * @param string $nonce     Unique nonce.
	 * @param string $body      Request body.
	 * @param string $secret    Site secret (base64-encoded).
	 * @return string Hex-encoded signature.
	 */
	private static function compute_signature( $method, $path, $query, $timestamp, $nonce, $body, $secret ) {
		$canonical_query = self::canonicalize_query_string( $query );
		$body_hash       = hash( 'sha256', $body );

		$canonical_data = implode(
			"\n",
			array(
				strtoupper( $method ),
				$path,
				$canonical_query,
				$timestamp,
				$nonce,
				$body_hash,
			)
		);

		return hash_hmac( 'sha256', $canonical_data, $secret );
	}

	/**
	 * Canonicalize query parameters.
	 *
	 * @param array $params Query parameters.
	 * @return string Canonical query string.
	 */
	private static function canonicalize_query_string( $params ) {
		if ( empty( $params ) ) {
			return '';
		}

		ksort( $params );

		$parts = array();
		foreach ( $params as $key => $value ) {
			$parts[] = rawurlencode( $key ) . '=' . rawurlencode( (string) $value );
		}

		return implode( '&', $parts );
	}

	/**
	 * Generate a unique nonce.
	 *
	 * @return string 32-character hex nonce.
	 */
	private static function generate_nonce() {
		return bin2hex( random_bytes( 16 ) );
	}
}
