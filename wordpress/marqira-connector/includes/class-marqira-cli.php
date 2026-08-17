<?php
/**
 * WP-CLI commands for MarQira Connector.
 *
 * @package Marqira_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Marqira_CLI
 *
 * WP-CLI command interface for MarQira operations.
 */
class Marqira_CLI {

	/**
	 * Register WP-CLI commands.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		WP_CLI::add_command( 'marqira collect-data', array( __CLASS__, 'collect_data' ) );
		WP_CLI::add_command( 'marqira schedule-status', array( __CLASS__, 'schedule_status' ) );
	}

	/**
	 * Manually trigger data collection and shipping.
	 *
	 * Collects all WordPress user and post snapshots and ships them to the
	 * MarQira API. This is the same operation that runs automatically on
	 * the scheduled cron.
	 *
	 * ## EXAMPLES
	 *
	 *     wp marqira collect-data
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public static function collect_data( $args, $assoc_args ) {
		if ( ! Marqira_Enrollment::is_enrolled() ) {
			WP_CLI::error( 'Site is not enrolled with MarQira. Please enroll first.' );
			return;
		}

		WP_CLI::log( 'Starting data collection...' );

		$result = Marqira_Data_Collector::collect_and_ship_all();

		if ( ! $result['success'] ) {
			WP_CLI::error( sprintf( 'Data collection failed: %s', $result['error'] ?? 'Unknown error' ) );
			return;
		}

		WP_CLI::success( sprintf(
			'Data collection complete. Users: %d collected, %s shipped. Posts: %d collected, %s shipped.',
			$result['users_collected'],
			$result['users_shipped'] ? 'successfully' : 'failed to ship',
			$result['posts_collected'],
			$result['posts_shipped'] ? 'successfully' : 'failed to ship'
		) );
	}

	/**
	 * Show the status of scheduled MarQira tasks.
	 *
	 * Displays information about the heartbeat and data collection cron
	 * schedules, including next run times.
	 *
	 * ## EXAMPLES
	 *
	 *     wp marqira schedule-status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public static function schedule_status( $args, $assoc_args ) {
		WP_CLI::log( '=== MarQira Schedule Status ===' );
		WP_CLI::log( '' );

		// Enrollment status
		$enrolled = Marqira_Enrollment::is_enrolled();
		WP_CLI::log( sprintf( 'Enrollment: %s', $enrolled ? WP_CLI::colorize( '%gEnrolled%n' ) : WP_CLI::colorize( '%rNot Enrolled%n' ) ) );
		WP_CLI::log( '' );

		// Heartbeat schedule
		$heartbeat_next = wp_next_scheduled( Marqira_Heartbeat::CRON_HOOK );
		WP_CLI::log( 'Heartbeat Schedule:' );
		if ( $heartbeat_next ) {
			WP_CLI::log( sprintf( '  Status: %s', WP_CLI::colorize( '%gScheduled%n' ) ) );
			WP_CLI::log( sprintf( '  Next run: %s', date( 'Y-m-d H:i:s', $heartbeat_next ) ) );
			WP_CLI::log( sprintf( '  Interval: Every %d minutes', Marqira_Heartbeat::HEARTBEAT_INTERVAL_MINUTES ) );
		} else {
			WP_CLI::log( sprintf( '  Status: %s', WP_CLI::colorize( '%yNot Scheduled%n' ) ) );
		}
		WP_CLI::log( '' );

		// Data collection schedule
		$collection_next = wp_next_scheduled( Marqira_Data_Collector::CRON_HOOK );
		WP_CLI::log( 'Data Collection Schedule:' );
		if ( $collection_next ) {
			WP_CLI::log( sprintf( '  Status: %s', WP_CLI::colorize( '%gScheduled%n' ) ) );
			WP_CLI::log( sprintf( '  Next run: %s', date( 'Y-m-d H:i:s', $collection_next ) ) );
			WP_CLI::log( sprintf( '  Interval: Every %d hours', Marqira_Data_Collector::COLLECTION_INTERVAL_HOURS ) );
		} else {
			WP_CLI::log( sprintf( '  Status: %s', WP_CLI::colorize( '%yNot Scheduled%n' ) ) );
			if ( $enrolled ) {
				WP_CLI::warning( 'Data collection is not scheduled but the site is enrolled. This may auto-heal on next page load.' );
			}
		}
		WP_CLI::log( '' );

		// Server time
		WP_CLI::log( sprintf( 'Server time: %s', date( 'Y-m-d H:i:s' ) ) );
	}
}

