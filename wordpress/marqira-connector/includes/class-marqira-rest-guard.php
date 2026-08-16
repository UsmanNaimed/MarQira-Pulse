<?php
/**
 * REST API access guard.
 *
 * @package Marqira_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Marqira_Rest_Guard
 *
 * Optional, opt-in restriction of the WordPress REST API to approved
 * sources. Designed for the MarQira origin-bypass threat model: it blocks
 * requests that reach the origin server directly from IPs that are neither
 * approved MarQira infrastructure nor the site's own CDN (Cloudflare), while
 * leaving normal CDN-fronted visitors and logged-in administrators
 * unaffected.
 *
 * This feature is DISABLED by default. When disabled, the public REST API
 * behaves exactly as WordPress ships it.
 */
class Marqira_Rest_Guard {

	/**
	 * Constructor. Registers the REST dispatch filter.
	 */
	public function __construct() {
		add_filter( 'rest_pre_dispatch', array( $this, 'restrict_rest_access' ), 5, 3 );
	}

	/**
	 * Restrict REST API access when the feature is enabled.
	 *
	 * Allow order (any match permits the request):
	 *   1. Logged-in users (cookie/session auth) — keeps wp-admin, the block
	 *      editor and authenticated site features working.
	 *   2. Requests arriving through Cloudflare (REMOTE_ADDR is a Cloudflare
	 *      proxy) — normal visitors on CDN-fronted sites.
	 *   3. Requests whose resolved client IP is in the allowed MarQira list.
	 * Everything else is blocked with HTTP 403.
	 *
	 * @param mixed           $result  Response to replace the default, or null.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Current request.
	 * @return mixed
	 */
	public function restrict_rest_access( $result, $server, $request ) {
		// Never interfere if a previous handler already produced a result.
		if ( ! empty( $result ) ) {
			return $result;
		}

		$settings = marqira_connector_get_settings();

		if ( empty( $settings['rest_restriction_enabled'] ) ) {
			return $result;
		}

		// 1. Logged-in users are always allowed (cookie/session auth).
		if ( is_user_logged_in() ) {
			return $result;
		}

		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] )
			? Marqira_IP_Utils::normalize( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) )
			: false;

		// 2. Requests through the site's CDN (Cloudflare) are allowed so that
		//    normal visitors are never blocked on CDN-fronted sites.
		if ( false !== $remote_addr && Marqira_Cloudflare::is_cloudflare_ip( $remote_addr ) ) {
			return $result;
		}

		$client      = Marqira_Cloudflare::get_real_client_ip();
		$client_ip   = isset( $client['ip'] )     ? $client['ip']     : '';
		$source      = isset( $client['source'] ) ? $client['source'] : 'REMOTE_ADDR';
		$allowed_ips = ( isset( $settings['allowed_ips'] ) && is_array( $settings['allowed_ips'] ) )
			? $settings['allowed_ips']
			: array();

		// 3. Approved MarQira infrastructure IPs are allowed.
		if ( '' !== $client_ip && Marqira_IP_Utils::ip_in_list( $client_ip, $allowed_ips ) ) {
			return $result;
		}

		$route = ( $request instanceof WP_REST_Request ) ? $request->get_route() : '';

		Marqira_Logger::log_rest_denied( $client_ip, $source, $route );

		return new WP_Error(
			'marqira_rest_forbidden',
			__( 'REST API access from this address is not permitted.', 'marqira-connector' ),
			array( 'status' => 403 )
		);
	}
}
