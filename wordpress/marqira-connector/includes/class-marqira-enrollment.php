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
		$encrypted = get_option( self::CREDENTIALS_OPTION, '' );

		if ( empty( $encrypted ) ) {
			return null;
		}

		$decrypted = self::decrypt( $encrypted );

		if ( false === $decrypted ) {
			return null;
		}

		$credentials = json_decode( $decrypted, true );

		if ( ! is_array( $credentials ) ) {
			return null;
		}

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

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$result      = json_decode( $body, true );

		if ( $status_code !== 201 || empty( $result['success'] ) ) {
			$error_message = isset( $result['error'] ) ? $result['error'] : __( 'Enrollment failed.', 'marqira-connector' );
			return new WP_Error( 'enrollment_failed', $error_message );
		}

		// Store credentials (encrypted)
		$credentials = array(
			'site_uuid'    => $result['site_uuid'],
			'site_secret'  => $result['site_secret'],
			'kid'          => $result['kid'],
			'api_url'      => isset( $result['api_url'] ) ? $result['api_url'] : $api_url,
			'enrolled_at'  => gmdate( 'Y-m-d H:i:s' ),
			'config'       => isset( $result['config'] ) ? $result['config'] : array(),
		);

		$encrypted = self::encrypt( wp_json_encode( $credentials ) );
		update_option( self::CREDENTIALS_OPTION, $encrypted );

		// Log enrollment
		Marqira_Logger::log(
			'site_enrolled',
			sprintf( 'Site enrolled successfully. Site UUID: %s', substr( $result['site_uuid'], 0, 8 ) . '...' ),
			'info'
		);

		return $credentials;
	}

	/**
	 * Disconnect the site (remove credentials).
	 *
	 * @return bool True on success.
	 */
	public static function disconnect() {
		delete_option( self::CREDENTIALS_OPTION );
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
	 * Encrypt data using WordPress salts.
	 *
	 * @param string $plaintext Data to encrypt.
	 * @return string Base64-encoded ciphertext.
	 */
	private static function encrypt( $plaintext ) {
		$key    = self::get_encryption_key();
		$iv     = openssl_random_pseudo_bytes( 16 );
		$cipher = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return base64_encode( $iv . $cipher );
	}

	/**
	 * Decrypt data encrypted with encrypt().
	 *
	 * @param string $ciphertext Base64-encoded ciphertext.
	 * @return string|false Plaintext on success, false on failure.
	 */
	private static function decrypt( $ciphertext ) {
		$key  = self::get_encryption_key();
		$data = base64_decode( $ciphertext, true );

		if ( false === $data || strlen( $data ) < 17 ) {
			return false;
		}

		$iv     = substr( $data, 0, 16 );
		$cipher = substr( $data, 16 );

		return openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
	}

	/**
	 * Derive encryption key from WordPress salts.
	 *
	 * @return string 32-byte key.
	 */
	private static function get_encryption_key() {
		$salts = array(
			defined( 'AUTH_KEY' ) ? AUTH_KEY : '',
			defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '',
			defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : '',
			defined( 'NONCE_KEY' ) ? NONCE_KEY : '',
		);
		$salt = implode( '|', $salts );
		return hash( 'sha256', $salt, true );
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
