<?php
/**
 * Privacy-safe visitor tracking for MarQira Connector.
 *
 * Phase 8 — Visitor Analytics. Tracks daily unique visitors and pageviews
 * without storing PII. Uses hashed IP + user agent for uniqueness (rotated
 * daily). Aggregates are sent to MarQira API for analytics.
 *
 * @package Marqira_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Marqira_Visitor_Tracker
 */
class Marqira_Visitor_Tracker {

	/**
	 * Option key for today's visitor data (rotates daily).
	 */
	const OPTION_TODAY = 'marqira_visitors_today';

	/**
	 * Option key for yesterday's complete data (ready to send).
	 */
	const OPTION_YESTERDAY = 'marqira_visitors_yesterday';

	/**
	 * Option key for the last rotated date (Y-m-d).
	 */
	const OPTION_LAST_ROTATION = 'marqira_visitors_last_rotation';

	/**
	 * Initialize visitor tracking.
	 *
	 * Hooks into `init` to track the current pageview, and `shutdown` to
	 * handle daily rotation.
	 */
	public static function init() {
		// Track this pageview (runs on every frontend request).
		add_action( 'template_redirect', array( __CLASS__, 'track_pageview' ) );

		// Daily rotation check (runs once per request, cheap check).
		add_action( 'shutdown', array( __CLASS__, 'maybe_rotate_daily' ), 5 );
	}

	/**
	 * Track the current pageview (privacy-safe, no PII stored).
	 *
	 * Uses a daily-rotated hash of IP + user agent to count unique visitors.
	 * Only increments counters — does NOT store individual visit records.
	 */
	public static function track_pageview() {
		// Skip admin, cron, and logged-in users (optional — you can track all).
		if ( is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}

		// Skip if visitor tracking is explicitly disabled.
		if ( defined( 'MARQIRA_DISABLE_VISITOR_TRACKING' ) && MARQIRA_DISABLE_VISITOR_TRACKING ) {
			return;
		}

		$today = gmdate( 'Y-m-d' );

		// Get today's data (array with 'date', 'pageviews', 'visitors' => [hash=>1, ...]).
		$data = get_option( self::OPTION_TODAY, array(
			'date'      => $today,
			'pageviews' => 0,
			'visitors'  => array(), // hash => 1 map for uniqueness.
		) );

		// Ensure structure (in case option was corrupted).
		if ( ! isset( $data['date'] ) || $data['date'] !== $today ) {
			$data = array(
				'date'      => $today,
				'pageviews' => 0,
				'visitors'  => array(),
			);
		}

		// Increment pageviews.
		$data['pageviews'] = (int) $data['pageviews'] + 1;

		// Track unique visitor (daily hash of IP + user agent, not reversible).
		$visitor_hash = self::get_visitor_hash( $today );
		if ( ! isset( $data['visitors'][ $visitor_hash ] ) ) {
			$data['visitors'][ $visitor_hash ] = 1;
		}

		// Save updated counters.
		update_option( self::OPTION_TODAY, $data, false );
	}

	/**
	 * Get a daily-rotated hash for the current visitor (privacy-safe).
	 *
	 * Uses IP + user agent + date + site-specific salt. The hash rotates daily
	 * and cannot be reversed to identify the visitor. This provides a
	 * reasonable "unique visitors" count without storing personal data.
	 *
	 * @param string $date The date (Y-m-d) to include in the hash (daily rotation).
	 * @return string      A short hash representing this visitor today.
	 */
	private static function get_visitor_hash( $date ) {
		$ip         = self::get_client_ip();
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$salt       = wp_salt( 'nonce' ); // site-specific, changes on key rotation.

		// Daily-rotated hash (sha256 truncated to 16 chars for storage efficiency).
		$raw = $ip . '|' . $user_agent . '|' . $date . '|' . $salt;
		return substr( hash( 'sha256', $raw ), 0, 16 );
	}

	/**
	 * Get the client IP address (best-effort, respects proxies).
	 *
	 * @return string The detected client IP, or '0.0.0.0' if unavailable.
	 */
	private static function get_client_ip() {
		$ip = '0.0.0.0';

		// Try standard headers (CloudFlare, proxies, load balancers).
		$headers = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare.
			'HTTP_X_REAL_IP',
			'HTTP_X_FORWARDED_FOR',
			'REMOTE_ADDR',
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$candidate = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				// X-Forwarded-For may be comma-separated; take the first.
				if ( false !== strpos( $candidate, ',' ) ) {
					$candidate = trim( explode( ',', $candidate )[0] );
				}
				// Basic IP validation.
				if ( filter_var( $candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					$ip = $candidate;
					break;
				} elseif ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					// Accept private/reserved IPs if nothing else (local dev).
					$ip = $candidate;
					break;
				}
			}
		}

		return $ip;
	}

	/**
	 * Rotate daily data (move today → yesterday, reset today).
	 *
	 * Runs once per day (checked on shutdown). Yesterday's complete data is
	 * held until sent by the next heartbeat, then cleared.
	 */
	public static function maybe_rotate_daily() {
		$today          = gmdate( 'Y-m-d' );
		$last_rotation  = get_option( self::OPTION_LAST_ROTATION, '' );

		// Only rotate once per day.
		if ( $last_rotation === $today ) {
			return;
		}

		// Move today → yesterday (for heartbeat to send).
		$today_data = get_option( self::OPTION_TODAY, array() );
		if ( ! empty( $today_data ) && isset( $today_data['date'] ) ) {
			// Finalize yesterday's data (count unique visitors, drop the hash map).
			$yesterday_final = array(
				'date'            => $today_data['date'],
				'unique_visitors' => count( $today_data['visitors'] ),
				'pageviews'       => (int) $today_data['pageviews'],
			);
			update_option( self::OPTION_YESTERDAY, $yesterday_final, false );
		}

		// Reset today.
		update_option( self::OPTION_TODAY, array(
			'date'      => $today,
			'pageviews' => 0,
			'visitors'  => array(),
		), false );

		// Mark rotation complete.
		update_option( self::OPTION_LAST_ROTATION, $today, false );
	}

	/**
	 * Get yesterday's complete visitor data (for heartbeat).
	 *
	 * Returns an array with 'date', 'unique_visitors', 'pageviews', or null
	 * if no data is available (e.g. fresh install, already sent).
	 *
	 * @return array|null
	 */
	public static function get_yesterday_metrics() {
		$yesterday = get_option( self::OPTION_YESTERDAY, null );

		if ( empty( $yesterday ) || ! isset( $yesterday['date'], $yesterday['unique_visitors'], $yesterday['pageviews'] ) ) {
			return null;
		}

		return $yesterday;
	}

	/**
	 * Clear yesterday's data (called after successful heartbeat send).
	 */
	public static function clear_yesterday_metrics() {
		delete_option( self::OPTION_YESTERDAY );
	}

	/**
	 * Get current stats for admin display (optional helper).
	 *
	 * @return array
	 */
	public static function get_current_stats() {
		$today = get_option( self::OPTION_TODAY, array(
			'date'      => gmdate( 'Y-m-d' ),
			'pageviews' => 0,
			'visitors'  => array(),
		) );

		return array(
			'date'            => $today['date'],
			'unique_visitors' => count( $today['visitors'] ),
			'pageviews'       => (int) $today['pageviews'],
		);
	}
}
