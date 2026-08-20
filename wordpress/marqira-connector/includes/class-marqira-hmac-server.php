<?php
/**
 * Inbound HMAC verifier for MarQira Connector.
 *
 * This is the mirror image of Marqira_Hmac_Client: instead of signing plugin →
 * API requests, it VERIFIES API → plugin requests. It is what allows the
 * MarQira control plane to securely push an "update this site now" command to
 * the connector's REST endpoint the moment the operator clicks the button,
 * rather than waiting for the next WP-Cron heartbeat.
 *
 * Verification mirrors the API's HmacAuthentication middleware exactly:
 *   1. All five X-MarQira-* headers must be present.
 *   2. The site UUID in the header must match this site's enrolled UUID.
 *   3. The timestamp must be within ±300s of now (clock-skew tolerance).
 *   4. The signature must verify (constant-time) against this site's secret.
 *   5. The nonce must not have been seen before (replay protection).
 *
 * The canonical string signed on BOTH sides is a fixed, permalink-independent
 * path constant (self::SIGN_PATH...), never the raw REST request URI. WordPress
 * sites can serve REST under /wp-json/… or ?rest_route=…, in a subdirectory, or
 * behind a reverse proxy, so reconstructing the "path" from the incoming
 * request is fragile. Both the API signer and this verifier agree on the stable
 * logical path (e.g. "/marqira/v1/execute-update"), which keeps signatures
 * portable while still binding each signature to a specific endpoint.
 *
 * @package Marqira_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Marqira_Hmac_Server
 */
class Marqira_Hmac_Server {

	/**
	 * Clock-skew tolerance in seconds (matches the API's ±5 minutes).
	 */
	const TIMESTAMP_TOLERANCE = 300;

	/**
	 * Transient prefix used to remember consumed nonces (replay protection).
	 */
	const NONCE_PREFIX = 'marqira_in_nonce_';

	/**
	 * How long a nonce is remembered. Must exceed the timestamp tolerance so a
	 * captured request can never be replayed inside its own validity window.
	 */
	const NONCE_TTL = 600;

	/**
	 * Verify an inbound signed REST request.
	 *
	 * @param WP_REST_Request $request   The incoming REST request.
	 * @param string          $sign_path Stable logical path the signature covers
	 *                                    (e.g. "/marqira/v1/execute-update").
	 * @return true|WP_Error True when the signature is valid, WP_Error otherwise.
	 */
	public static function verify( $request, $sign_path ) {
		if ( ! class_exists( 'Marqira_Enrollment' ) || ! Marqira_Enrollment::is_enrolled() ) {
			return new WP_Error( 'marqira_not_enrolled', 'This site is not connected to MarQira.', array( 'status' => 403 ) );
		}

		$credentials = Marqira_Enrollment::get_credentials();
		if ( empty( $credentials['site_uuid'] ) || empty( $credentials['site_secret'] ) || empty( $credentials['kid'] ) ) {
			return new WP_Error( 'marqira_no_credentials', 'Site credentials are unavailable.', array( 'status' => 403 ) );
		}

		$site      = $request->get_header( 'x_marqira_site' );
		$timestamp = $request->get_header( 'x_marqira_timestamp' );
		$nonce     = $request->get_header( 'x_marqira_nonce' );
		$kid       = $request->get_header( 'x_marqira_kid' );
		$signature = $request->get_header( 'x_marqira_signature' );

		// 1. All headers present.
		if ( empty( $site ) || empty( $timestamp ) || empty( $nonce ) || empty( $kid ) || empty( $signature ) ) {
			return new WP_Error( 'marqira_missing_headers', 'Missing required authentication headers.', array( 'status' => 401 ) );
		}

		// 2. The request must be addressed to THIS site.
		if ( ! hash_equals( (string) $credentials['site_uuid'], (string) $site ) ) {
			return new WP_Error( 'marqira_site_mismatch', 'Signature is not for this site.', array( 'status' => 401 ) );
		}

		// Key id must match the one issued at enrollment.
		if ( ! hash_equals( (string) $credentials['kid'], (string) $kid ) ) {
			return new WP_Error( 'marqira_bad_kid', 'Unknown signing key.', array( 'status' => 401 ) );
		}

		// 3. Timestamp within tolerance.
		if ( ! self::timestamp_valid( $timestamp ) ) {
			return new WP_Error( 'marqira_stale_timestamp', 'Request timestamp expired or invalid.', array( 'status' => 401 ) );
		}

		// 4. Verify signature over the stable canonical string.
		$body           = $request->get_body();
		$body           = is_string( $body ) ? $body : '';
		$canonical_data = self::build_canonical( $request->get_method(), $sign_path, $timestamp, $nonce, $body );
		$expected       = hash_hmac( 'sha256', $canonical_data, $credentials['site_secret'] );

		if ( ! hash_equals( $expected, (string) $signature ) ) {
			return new WP_Error( 'marqira_bad_signature', 'Invalid request signature.', array( 'status' => 401 ) );
		}

		// 5. Replay protection: a nonce may be used exactly once.
		if ( ! self::claim_nonce( $nonce ) ) {
			return new WP_Error( 'marqira_replay', 'This request has already been processed.', array( 'status' => 401 ) );
		}

		return true;
	}

	/**
	 * Build the canonical data string. Identical shape to the API's
	 * HmacService::buildCanonicalData (query is always empty for signed pushes).
	 *
	 * @param string $method    HTTP method.
	 * @param string $path      Stable logical path.
	 * @param string $timestamp Unix timestamp string.
	 * @param string $nonce     Unique nonce.
	 * @param string $body      Raw request body.
	 * @return string
	 */
	private static function build_canonical( $method, $path, $timestamp, $nonce, $body ) {
		return implode(
			"\n",
			array(
				strtoupper( (string) $method ),
				$path,
				'', // canonical query — always empty for signed control pushes.
				(string) $timestamp,
				(string) $nonce,
				hash( 'sha256', $body ),
			)
		);
	}

	/**
	 * Validate the timestamp is within tolerance of the current time.
	 *
	 * @param string|int $timestamp Unix timestamp (seconds).
	 * @return bool
	 */
	private static function timestamp_valid( $timestamp ) {
		$timestamp = (int) $timestamp;
		if ( $timestamp <= 0 ) {
			return false;
		}
		return abs( time() - $timestamp ) <= self::TIMESTAMP_TOLERANCE;
	}

	/**
	 * Atomically claim a nonce. Returns false if it was already used.
	 *
	 * Uses the WordPress transient cache as a short-lived store. add_option-style
	 * atomicity isn't guaranteed across all object caches, but the combination of
	 * a fresh random nonce per request plus the timestamp window makes a
	 * meaningful replay attempt require winning a sub-second race — and the
	 * signature itself already prevents forgery.
	 *
	 * @param string $nonce Nonce value.
	 * @return bool True when freshly claimed, false when already seen.
	 */
	private static function claim_nonce( $nonce ) {
		$key = self::NONCE_PREFIX . hash( 'sha256', (string) $nonce );

		if ( false !== get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, 1, self::NONCE_TTL );
		return true;
	}
}
