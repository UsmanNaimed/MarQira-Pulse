<?php
/**
 * Diagnostics collector for MarQira Connector.
 *
 * @package Marqira_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Marqira_Diagnostics
 *
 * Gathers environment and configuration information for display on
 * the admin settings page.
 */
class Marqira_Diagnostics {

	/**
	 * Collect all diagnostics data.
	 *
	 * @return array
	 */
	public static function get_all() {
		$settings = marqira_connector_get_settings();
		$client   = Marqira_Cloudflare::get_real_client_ip();

		$server_addr = isset( $_SERVER['SERVER_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) )
			: 'unknown';

		$hostname = gethostname();
		if ( false === $hostname || '' === $hostname ) {
			$hostname = isset( $_SERVER['SERVER_NAME'] )
				? sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) )
				: 'unknown';
		}

		$server_software = isset( $_SERVER['SERVER_SOFTWARE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) )
			: 'unknown';

		return array(
			'wp_version'               => get_bloginfo( 'version' ),
			'php_version'              => PHP_VERSION,
			'plugin_version'           => MARQIRA_CONNECTOR_VERSION,
			'server_addr'              => $server_addr,
			'server_hostname'          => (string) $hostname,
			'server_software'          => $server_software,
			'is_multisite'             => is_multisite(),
			'home_url'                 => home_url(),
			'site_url'                 => site_url(),
			'detected_ip'              => $client['ip'],
			'ip_source'                => $client['source'],
			'cloudflare_detected'      => $client['cloudflare'],
			'protection_enabled'       => (bool) $settings['protection_enabled'],
			'rest_restriction_enabled' => (bool) $settings['rest_restriction_enabled'],
			'allowed_ips'              => $settings['allowed_ips'],
			'https'                    => is_ssl(),
			'rest_url'                 => rest_url(),
			'log_cap'                  => defined( 'MARQIRA_CONNECTOR_LOG_CAP' ) ? (int) MARQIRA_CONNECTOR_LOG_CAP : 500,
		);
	}
}
