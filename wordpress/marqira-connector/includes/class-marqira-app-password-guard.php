<?php
/**
 * Application Password authentication guard.
 *
 * @package Marqira_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Marqira_App_Password_Guard
 *
 * Restricts WordPress Application Password authentication to approved
 * MarQira infrastructure IP addresses. Does not affect cookie-based
 * wp-admin login or the public REST API.
 *
 * WordPress fires wp_authenticate_application_password_errors via do_action(),
 * passing the WP_Error object by PHP object reference. We add errors to that
 * object; WordPress then checks has_errors() and rejects auth if any exist.
 * Because this is an action (do_action, not apply_filters), we use add_action
 * and do NOT return a value.
 */
class Marqira_App_Password_Guard {

	/**
	 * Constructor. Registers the authentication action.
	 */
	public function __construct() {
		add_action(
			'wp_authenticate_application_password_errors',
			array( $this, 'handle_app_password_auth_errors' ),
			10,
			4
		);
	}

	/**
	 * Inspect an Application Password authentication attempt and deny it
	 * by adding a WP_Error if the client IP is not in the allowed list.
	 *
	 * WordPress hook: wp_authenticate_application_password_errors
	 * Fired by:       do_action() in class-wp-application-passwords.php
	 * Introduced:     WordPress 5.6
	 *
	 * @param WP_Error        $error        Error object (modified in place; do NOT return).
	 * @param WP_User         $user         Authenticating user.
	 * @param array|object    $item         The Application Password item being used.
	 * @param WP_REST_Request $request      The current REST request.
	 * @return void  — This is an action callback; return values are discarded.
	 */
	public function handle_app_password_auth_errors( $error, $user, $item, $request ) {
		$settings = marqira_connector_get_settings();

		// If protection is disabled, allow all Application Password requests.
		if ( empty( $settings['protection_enabled'] ) ) {
			return;
		}

		$client    = Marqira_Cloudflare::get_real_client_ip();
		$client_ip = isset( $client['ip'] )     ? $client['ip']     : '';
		$source    = isset( $client['source'] ) ? $client['source'] : 'REMOTE_ADDR';

		$username = ( $user instanceof WP_User ) ? $user->user_login : '';

		$allowed_ips = ( isset( $settings['allowed_ips'] ) && is_array( $settings['allowed_ips'] ) )
			? $settings['allowed_ips']
			: array();

		if ( '' !== $client_ip && Marqira_IP_Utils::ip_in_list( $client_ip, $allowed_ips ) ) {
			// IP is on the allowlist — authentication may proceed.
			Marqira_Logger::log_app_password_allowed( $client_ip, $source, $username );
			return;
		}

		// IP is not allowed — add an error to the shared WP_Error object.
		// WordPress checks has_errors() after this action fires and rejects
		// auth if any errors are present.
		$error->add(
			'marqira_ip_not_allowed',
			__(
				'Application Password authentication is restricted to approved MarQira infrastructure. Your IP address is not authorized.',
				'marqira-connector'
			)
		);

		Marqira_Logger::log_app_password_denied( $client_ip, $source, $username );
	}
}
