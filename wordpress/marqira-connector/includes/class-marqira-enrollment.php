<?php
/**
 * Enrollment manager for MarQira Connector.
 *
 * Handles enrollment flow and credential storage.
 *
 * @package Marqira_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Marqira_Enrollment
 */
class Marqira_Enrollment {

	/**
	 * Option name for storing site credentials.
	 */
	const CREDENTIALS_OPTION = 'marqira_site_credentials';

	/**
	 * Per-request cache of decrypted credentials.
	 *
	 * Avoids repeating the AES-256-GCM decrypt on every get_credentials() call
	 * within a single request (is_enrolled(), the config fetcher, and the
	 * heartbeat sender can each call it). Sentinel false = "not yet loaded".
	 *
	 * @var array|null|false
	 */
	private static $credentials_cache = false;

	/**
	 * Check if the site is enrolled.
	 *
	 * @return bool
	 */
	public static function is_enrolled() {
		$credentials = self::get_credentials();
		return ! empty( $credentials['site_uuid'] ) && ! empty( $credentials['site_secret'] );
	}

	/**
	 * Get stored credentials (decrypted).
	 *
	 * @return array|null Credentials array or null if not enrolled
	 */
	public static function get_credentials() {
		// Return the per-request cached value if already resolved.
		// Sentinel false means "not yet loaded"; null is a valid cached
		// "not enrolled" result and short-circuits repeated option reads.
		if ( false !== self::$credentials_cache ) {
			return self::$credentials_cache;
		}

		$encrypted = get_option( self::CREDENTIALS_OPTION, '' );

		if ( empty( $encrypted ) ) {
			self::$credentials_cache = null;
			return null;
		}

		$decrypted = self::decrypt( $encrypted );

		if ( false === $decrypted ) {
			self::$credentials_cache = null;
			return null;
		}

		$credentials = json_decode( $decrypted, true );

		if ( ! is_array( $credentials ) ) {
			self::$credentials_cache = null;
			return null;
		}

		self::$credentials_cache = $credentials;

		return $credentials;
	}

	/**
	 * Enroll the site using an enrollment token.
	 *
	 * @param string $token Enrollment token from the dashboard.
	 * @return array|WP_Error Result array on success, WP_Error on failure.
	 */
	public static function enroll( $token ) {
		if ( empty( $token ) || ! is_string( $token ) ) {
			return new WP_Error( 'invalid_token', __( 'Invalid enrollment token.', 'marqira-connector' ) );
		}

		$token = sanitize_text_field( trim( $token ) );

		// Get API URL from settings or use default
		$api_url = self::get_api_url();

		// Collect site metadata
		$enrollment_data = array(
			'token'            => $token,
			'domain'           => self::get_domain(),
			'home_url'         => home_url(),
			'site_url'         => site_url(),
			'wp_version'       => get_bloginfo( 'version' ),
			'php_version'      => PHP_VERSION,
			'plugin_version'   => MARQIRA_CONNECTOR_VERSION,
			'server_ip'        => self::get_server_ip(),
			'server_hostname'  => self::get_server_hostname(),
			'server_software'  => self::get_server_software(),
			'is_multisite'     => is_multisite(),
			'network_data'     => self::get_network_data(),
		);

		// Send enrollment request
		$response = wp_remote_post(
			rtrim( $api_url, '/' ) . '/api/v1/enrollment',
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $enrollment_data ),
			)
		);

		// Transport-level failure (DNS, TLS, timeout, connection refused).
		if ( is_wp_error( $response ) ) {
			$reason = self::classify_transport_error( $response );

			Marqira_Logger::log(
				'enrollment_failed',
				sprintf( 'Enrollment request failed: %s', $reason ),
				'error'
			);

			return new WP_Error(
				'enrollment_failed',
				__( 'Could not reach the MarQira API. Please check the site\'s outbound connectivity and try again.', 'marqira-connector' )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$result      = json_decode( $body, true );

		if ( 201 !== (int) $status_code || empty( $result['success'] ) ) {
			// Record a safe, specific reason for Diagnostics without leaking
			// tokens, secrets, signatures, headers, or raw response bodies.
			$reason       = self::classify_http_status( $status_code );
			$user_message = self::user_message_for_status( $status_code );

			Marqira_Logger::log(
				'enrollment_failed',
				sprintf( 'Enrollment rejected (%s).', $reason ),
				'error'
			);

			return new WP_Error( 'enrollment_failed', $user_message );
		}

		// Validate the credential fields are present before storing anything.
		if ( empty( $result['site_uuid'] ) || empty( $result['site_secret'] ) || empty( $result['kid'] ) ) {
			Marqira_Logger::log(
				'enrollment_failed',
				'Enrollment response missing required credential fields.',
				'error'
			);

			return new WP_Error(
				'enrollment_failed',
				__( 'The MarQira API returned an incomplete enrollment response. Please try again.', 'marqira-connector' )
			);
		}

		// Store credentials (encrypted with authenticated AES-256-GCM).
		$credentials = array(
			'site_uuid'    => $result['site_uuid'],
			'site_secret'  => $result['site_secret'],
			'kid'          => $result['kid'],
			'api_url'      => isset( $result['api_url'] ) ? $result['api_url'] : $api_url,
			'enrolled_at'  => gmdate( 'Y-m-d H:i:s' ),
			'config'       => isset( $result['config'] ) ? $result['config'] : array(),
		);

		$encrypted = self::encrypt( wp_json_encode( $credentials ) );

		// Fail closed: never persist unencrypted or empty credentials.
		if ( false === $encrypted || '' === $encrypted ) {
			Marqira_Logger::log(
				'enrollment_failed',
				'Failed to encrypt site credentials (no usable encryption key).',
				'error'
			);

			return new WP_Error(
				'enrollment_failed',
				__( 'Could not securely store the site credentials. Please define MARQIRA_SECRET_KEY in wp-config.php and try again.', 'marqira-connector' )
			);
		}

		update_option( self::CREDENTIALS_OPTION, $encrypted );

		// Prime the per-request cache with the freshly stored credentials.
		self::$credentials_cache = $credentials;

		// Ensure the recurring heartbeat cron is scheduled now that the site is
		// enrolled. Idempotent — it never creates a duplicate event.
		if ( class_exists( 'Marqira_Heartbeat' ) ) {
			Marqira_Heartbeat::ensure_scheduled();
		}

		// Log enrollment (never log the raw token, secret, or full UUID).
		Marqira_Logger::log(
			'site_enrolled',
			sprintf( 'Site enrolled successfully. Site UUID: %s', substr( $result['site_uuid'], 0, 8 ) . '...' ),
			'info'
		);

		return $credentials;
	}

	/**
	 * Map a WP_Error from wp_remote_post to a safe diagnostic reason.
	 *
	 * @param WP_Error $error Transport error.
	 * @return string Safe, human-readable reason (no sensitive data).
	 */
	private static function classify_transport_error( $error ) {
		$message = strtolower( $error->get_error_message() );

		if ( false !== strpos( $message, 'timed out' ) || false !== strpos( $message, 'timeout' ) ) {
			return 'connection timeout';
		}
		if ( false !== strpos( $message, 'could not resolve host' ) || false !== strpos( $message, 'name or service not known' ) ) {
			return 'DNS resolution failure';
		}
		if ( false !== strpos( $message, 'ssl' ) || false !== strpos( $message, 'tls' ) || false !== strpos( $message, 'certificate' ) ) {
			return 'TLS/SSL failure';
		}
		if ( false !== strpos( $message, 'refused' ) ) {
			return 'connection refused';
		}

		return 'network error';
	}

	/**
	 * Map an HTTP status code to a safe diagnostic reason.
	 *
	 * @param int $status_code HTTP status code.
	 * @return string Safe, human-readable reason.
	 */
	private static function classify_http_status( $status_code ) {
		$status_code = (int) $status_code;

		switch ( true ) {
			case ( 401 === $status_code || 422 === $status_code ):
				return sprintf( 'HTTP %d — invalid or expired enrollment token', $status_code );
			case ( 429 === $status_code ):
				return 'HTTP 429 — rate limited';
			case ( $status_code >= 500 ):
				return sprintf( 'HTTP %d — API error', $status_code );
			case ( 0 === $status_code ):
				return 'no HTTP response';
			default:
				return sprintf( 'HTTP %d — unexpected response', $status_code );
		}
	}

	/**
	 * Provide a concise, non-sensitive user-facing message for a status code.
	 *
	 * @param int $status_code HTTP status code.
	 * @return string
	 */
	private static function user_message_for_status( $status_code ) {
		$status_code = (int) $status_code;

		if ( 401 === $status_code || 422 === $status_code ) {
			return __( 'The enrollment code is invalid or has expired. Generate a new code and try again.', 'marqira-connector' );
		}
		if ( 429 === $status_code ) {
			return __( 'Too many enrollment attempts. Please wait a minute and try again.', 'marqira-connector' );
		}
		if ( $status_code >= 500 ) {
			return __( 'The MarQira API reported a server error. Please try again shortly.', 'marqira-connector' );
		}

		return __( 'Enrollment failed. Please try again.', 'marqira-connector' );
	}

	/**
	 * Disconnect the site (remove credentials).
	 *
	 * @return bool True on success.
	 */
	public static function disconnect() {
		delete_option( self::CREDENTIALS_OPTION );

		// Reset the per-request cache to the "not enrolled" state.
		self::$credentials_cache = null;
		delete_transient( 'marqira_last_heartbeat_sent' );
		delete_transient( 'marqira_allowed_ips' );
		delete_transient( 'marqira_cloudflare_ranges' );

		Marqira_Logger::log(
			'site_disconnected',
			'Site disconnected from MarQira.',
			'info'
		);

		return true;
	}

	/**
	 * Encrypt data using authenticated AES-256-GCM.
	 *
	 * Delegates to Marqira_Crypto (versioned, tamper-evident payload).
	 *
	 * @param string $plaintext Data to encrypt.
	 * @return string|false Versioned ciphertext, or false on failure.
	 */
	private static function encrypt( $plaintext ) {
		return Marqira_Crypto::encrypt( $plaintext );
	}

	/**
	 * Decrypt data encrypted with encrypt().
	 *
	 * Fails closed: returns false on any tampering or format error.
	 *
	 * @param string $ciphertext Versioned ciphertext.
	 * @return string|false Plaintext on success, false on failure.
	 */
	private static function decrypt( $ciphertext ) {
		return Marqira_Crypto::decrypt( $ciphertext );
	}

	/**
	 * Get the API URL (from credentials or default).
	 *
	 * @return string
	 */
	public static function get_api_url() {
		$credentials = self::get_credentials();
		if ( ! empty( $credentials['api_url'] ) ) {
			return $credentials['api_url'];
		}
		return 'https://api.marqira.com';
	}

	/**
	 * Get the primary domain.
	 *
	 * @return string
	 */
	private static function get_domain() {
		return parse_url( home_url(), PHP_URL_HOST );
	}

	/**
	 * Get server IP address.
	 *
	 * @return string
	 */
	private static function get_server_ip() {
		return isset( $_SERVER['SERVER_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) )
			: '';
	}

	/**
	 * Get server hostname.
	 *
	 * @return string
	 */
	private static function get_server_hostname() {
		$hostname = gethostname();
		if ( false === $hostname || '' === $hostname ) {
			$hostname = isset( $_SERVER['SERVER_NAME'] )
				? sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) )
				: '';
		}
		return (string) $hostname;
	}

	/**
	 * Get server software.
	 *
	 * @return string
	 */
	private static function get_server_software() {
		return isset( $_SERVER['SERVER_SOFTWARE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) )
			: '';
	}

	/**
	 * Get multisite network data (if applicable).
	 *
	 * @return array|null
	 */
	private static function get_network_data() {
		if ( ! is_multisite() ) {
			return null;
		}

		$sites_count = get_blog_count();

		return array(
			'sites_count' => $sites_count,
			'network_url' => network_home_url(),
		);
	}
}
