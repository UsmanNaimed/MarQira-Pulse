<?php
/**
 * Heartbeat sender for MarQira Connector.
 *
 * Sends authenticated heartbeats to the MarQira API via wp-cron.
 *
 * @package Marqira_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Marqira_Heartbeat
 */
class Marqira_Heartbeat {

	/**
	 * Cron hook name.
	 */
	const CRON_HOOK = 'marqira_send_heartbeat';

	/**
	 * Initialize heartbeat system.
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'send_heartbeat' ) );
	}

	/**
	 * Register the heartbeat cron event (called on activation).
	 */
	public static function register_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Schedule for 10 minutes from now + random jitter (0-60 sec)
			$jitter    = wp_rand( 0, 60 );
			$next_time = time() + ( 10 * MINUTE_IN_SECONDS ) + $jitter;

			wp_schedule_event( $next_time, 'marqira_heartbeat_interval', self::CRON_HOOK );
		}
	}

	/**
	 * Unregister the heartbeat cron event (called on deactivation).
	 */
	public static function unregister_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Register custom cron interval (10 minutes + jitter).
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_cron_interval( $schedules ) {
		if ( ! isset( $schedules['marqira_heartbeat_interval'] ) ) {
			$schedules['marqira_heartbeat_interval'] = array(
				'interval' => 10 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 10 Minutes (MarQira Heartbeat)', 'marqira-connector' ),
			);
		}
		return $schedules;
	}

	/**
	 * Send a heartbeat to the MarQira API.
	 *
	 * Hooked to wp-cron.
	 */
	public static function send_heartbeat() {
		// Check if enrolled
		if ( ! Marqira_Enrollment::is_enrolled() ) {
			return;
		}

		$credentials = Marqira_Enrollment::get_credentials();
		if ( empty( $credentials ) ) {
			return;
		}

		// Collect site metadata
		$heartbeat_data = self::collect_metadata();

		// API endpoint
		$api_url = Marqira_Enrollment::get_api_url();
		$path    = '/api/v1/heartbeat';
		$url     = rtrim( $api_url, '/' ) . $path;

		// Build request
		$body    = wp_json_encode( $heartbeat_data );
		$headers = Marqira_Hmac_Client::generate_headers( 'POST', $path, array(), $body, $credentials );

		if ( empty( $headers ) ) {
			Marqira_Logger::log(
				'heartbeat_failed',
				'Failed to generate HMAC headers for heartbeat.',
				'error'
			);
			return;
		}

		// Send request
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => $headers,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			Marqira_Logger::log(
				'heartbeat_failed',
				sprintf( 'Heartbeat request failed: %s', $response->get_error_message() ),
				'error'
			);
			return;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code === 200 ) {
			// Success — update last sent timestamp
			set_transient( 'marqira_last_heartbeat_sent', time(), HOUR_IN_SECONDS );

			Marqira_Logger::log(
				'heartbeat_sent',
				'Heartbeat sent successfully.',
				'info'
			);
		} else {
			$body_text = (string) wp_remote_retrieve_body( $response );
			if ( strlen( $body_text ) > 200 ) {
				$body_text = substr( $body_text, 0, 200 ) . '…';
			}
			Marqira_Logger::log(
				'heartbeat_failed',
				sprintf( 'Heartbeat failed with status %d: %s', $status_code, $body_text ),
				'error'
			);
		}
	}

	/**
	 * Collect site metadata for heartbeat.
	 *
	 * @return array
	 */
	private static function collect_metadata() {
		$diagnostics = Marqira_Diagnostics::get_all();

		$data = array(
			'domain'           => parse_url( home_url(), PHP_URL_HOST ),
			'home_url'         => home_url(),
			'site_url'         => site_url(),
			'wp_version'       => $diagnostics['wp_version'],
			'php_version'      => $diagnostics['php_version'],
			'plugin_version'   => $diagnostics['plugin_version'],
			'server_ip'        => $diagnostics['server_addr'],
			'server_hostname'  => $diagnostics['server_hostname'],
			'server_software'  => $diagnostics['server_software'],
			'is_multisite'     => $diagnostics['is_multisite'],
		);

		// Add network data if multisite
		if ( is_multisite() ) {
			$data['network_data'] = array(
				'sites_count' => get_blog_count(),
				'network_url' => network_home_url(),
			);
		}

		// Add origin IP candidate (server_addr is the best guess for now)
		if ( ! empty( $diagnostics['server_addr'] ) ) {
			$data['origin_ip_candidate'] = $diagnostics['server_addr'];
		}

		return $data;
	}

	/**
	 * Get the timestamp of the last successful heartbeat.
	 *
	 * @return int|false Timestamp or false if never sent.
	 */
	public static function get_last_heartbeat_sent() {
		$timestamp = get_transient( 'marqira_last_heartbeat_sent' );
		return ( false !== $timestamp ) ? (int) $timestamp : false;
	}
}
