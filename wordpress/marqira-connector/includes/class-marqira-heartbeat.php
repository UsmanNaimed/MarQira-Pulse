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
	 * Custom cron interval name (registered via the cron_schedules filter).
	 */
	const CRON_INTERVAL = 'marqira_heartbeat_interval';

	/**
	 * Initialize heartbeat system.
	 *
	 * Runs on every request (hooked to `init`). Besides wiring the cron
	 * callback to the scheduled hook, it self-heals the schedule: if the site
	 * is enrolled but the recurring event is missing, it is recreated
	 * automatically. This is what lets already-installed sites recover after a
	 * plugin *upgrade* — which does NOT fire register_activation_hook() — with
	 * no reconnection and no manual WP-Cron configuration.
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'send_heartbeat' ) );

		// Self-heal the recurring schedule on normal plugin load.
		self::maybe_schedule();
	}

	/**
	 * Register the heartbeat cron event (called on activation).
	 *
	 * Delegates to maybe_schedule() so activation only schedules when the site
	 * is already enrolled. A freshly activated but unenrolled site has nothing
	 * to report; enrollment (and the init self-heal) schedule the event at the
	 * right time.
	 *
	 * @return void
	 */
	public static function register_cron() {
		self::maybe_schedule();
	}

	/**
	 * Schedule the recurring heartbeat event only when the site is enrolled.
	 *
	 * Safe to call on every request: it is idempotent and never creates a
	 * duplicate event.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		if ( ! Marqira_Enrollment::is_enrolled() ) {
			return;
		}

		self::ensure_scheduled();
	}

	/**
	 * Ensure exactly one recurring heartbeat event is scheduled.
	 *
	 * Uses wp_next_scheduled() as a guard so repeated calls across plugin
	 * loads, activations, upgrades and repeated enrollment can never accumulate
	 * duplicate cron entries.
	 *
	 * @return bool True if an event is scheduled (already or newly), false on failure.
	 */
	public static function ensure_scheduled() {
		// Already scheduled — never create a duplicate.
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return true;
		}

		// Schedule 10 minutes out + random jitter (0-60s) to spread load across
		// many customer sites. The 10-minute cadence stays well under the
		// backend's 20-minute "online" / 30-minute "offline" thresholds, so a
		// single missed beat never flips a healthy site offline.
		$jitter    = wp_rand( 0, 60 );
		$next_time = time() + ( 10 * MINUTE_IN_SECONDS ) + $jitter;

		$scheduled = wp_schedule_event( $next_time, self::CRON_INTERVAL, self::CRON_HOOK );

		if ( false === $scheduled ) {
			Marqira_Logger::log(
				'heartbeat_schedule_failed',
				'Could not schedule the recurring heartbeat cron event.',
				'error'
			);
			return false;
		}

		return true;
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
		if ( ! isset( $schedules[ self::CRON_INTERVAL ] ) ) {
			$schedules[ self::CRON_INTERVAL ] = array(
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
