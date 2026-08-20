<?php
/**
 * Plugin Name: MarQira Recovery Guard
 * Description: Resilient, dependency-free fatal-error guard for the MarQira Connector. Runs as a must-use plugin so it loads BEFORE regular plugins and can perform an emergency rollback of a MarQira-managed change even when the main connector (or the plugin/theme it just changed) can no longer bootstrap.
 * Version: 1.0.0
 * Author: MarQira
 *
 * WHY A MUST-USE PLUGIN?
 * ----------------------
 * A regular plugin cannot help recover a site when the site is fatally broken,
 * because a fatal in an *earlier* plugin (or in core/theme bootstrap) can stop
 * WordPress before the connector loads. Must-use plugins load first and always,
 * so this guard is already resident and its shutdown handler is registered
 * before the risky code runs.
 *
 * WHAT IT DOES
 * ------------
 * Right before the connector performs a risky managed action it writes a
 * "recovery sentinel" (option `marqira_recovery_sentinel`) describing exactly
 * what is about to change. If the request then dies with a PHP fatal error,
 * this guard's shutdown handler fires, reads that sentinel, and — ONLY for the
 * specific plugin(s) named in the sentinel — deactivates the offending plugin
 * so the very next request can boot WordPress again. It records what it did in
 * `marqira_recovery_guard_events` for the connector/dashboard to report.
 *
 * WHAT IT DELIBERATELY DOES NOT DO (documented limitations)
 * ---------------------------------------------------------
 * - It never touches plugins/themes that are not named in the sentinel.
 * - It cannot recover from a fatal that occurs *before* mu-plugins load
 *   (e.g. a corrupt wp-config.php, a broken WordPress core file, or a fatal in
 *   a database/object-cache drop-in). Those are outside any PHP-level guard.
 * - It only deactivates plugins here (the safest, data-preserving action that
 *   can be done without loading the full plugin API). File-level version
 *   restores are handled by the main connector's recovery class when WP is
 *   healthy enough to run it; this guard is the last resort.
 * - It never restores or modifies database content.
 *
 * @package Marqira_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'MARQIRA_GUARD_LOADED' ) ) {
	return;
}
define( 'MARQIRA_GUARD_LOADED', '1.0.0' );

if ( ! class_exists( 'Marqira_Guard' ) ) {

	class Marqira_Guard {

		const SENTINEL_OPTION = 'marqira_recovery_sentinel';
		const EVENTS_OPTION   = 'marqira_recovery_guard_events';
		const ACTIVE_PLUGINS  = 'active_plugins';
		const MAX_EVENTS      = 25;

		/**
		 * Register the shutdown handler as early as possible.
		 */
		public static function boot() {
			register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
		}

		/**
		 * Fired at the very end of every request. If the request died with a
		 * fatal error AND a MarQira action was in progress, perform an emergency
		 * deactivation of the specific offending plugin(s).
		 */
		public static function on_shutdown() {
			$error = error_get_last();
			if ( ! self::is_fatal( $error ) ) {
				return;
			}

			// Only act if the connector marked a risky action as in progress.
			$sentinel = self::get_option_raw( self::SENTINEL_OPTION );
			if ( empty( $sentinel ) || ! is_array( $sentinel ) ) {
				return;
			}

			// Loop protection: never act on the same sentinel twice.
			if ( ! empty( $sentinel['guard_handled'] ) ) {
				return;
			}

			$targets = isset( $sentinel['targets'] ) && is_array( $sentinel['targets'] ) ? $sentinel['targets'] : array();
			$plugins = isset( $targets['plugins'] ) && is_array( $targets['plugins'] ) ? $targets['plugins'] : array();

			$deactivated = array();
			if ( ! empty( $plugins ) ) {
				$deactivated = self::emergency_deactivate( $plugins );
			}

			// Record the event and mark the sentinel as handled so we do not loop.
			self::record_event(
				array(
					'at'          => time(),
					'action_id'   => isset( $sentinel['action_id'] ) ? $sentinel['action_id'] : '',
					'type'        => isset( $sentinel['type'] ) ? $sentinel['type'] : '',
					'fatal'       => isset( $error['message'] ) ? substr( (string) $error['message'], 0, 500 ) : '',
					'file'        => isset( $error['file'] ) ? (string) $error['file'] : '',
					'line'        => isset( $error['line'] ) ? (int) $error['line'] : 0,
					'deactivated' => $deactivated,
				)
			);

			$sentinel['guard_handled'] = time();
			self::update_option_raw( self::SENTINEL_OPTION, $sentinel );
		}

		/**
		 * Is $error a hard fatal we should react to?
		 *
		 * @param array|null $error error_get_last() result.
		 * @return bool
		 */
		private static function is_fatal( $error ) {
			if ( empty( $error ) || ! isset( $error['type'] ) ) {
				return false;
			}
			$fatal_types = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
			return (bool) ( $error['type'] & $fatal_types );
		}

		/**
		 * Deactivate ONLY the named plugins by rewriting the active_plugins
		 * option directly. We avoid the plugin API because the environment is
		 * already fatal and may not be safe to bootstrap further.
		 *
		 * @param array $plugins Plugin basenames to remove from active_plugins.
		 * @return array The basenames actually removed.
		 */
		private static function emergency_deactivate( $plugins ) {
			$active = self::get_option_raw( self::ACTIVE_PLUGINS );
			if ( ! is_array( $active ) ) {
				return array();
			}
			$removed = array();
			$next    = array();
			foreach ( $active as $entry ) {
				if ( in_array( $entry, $plugins, true ) ) {
					$removed[] = $entry;
					continue; // Drop it.
				}
				$next[] = $entry;
			}
			if ( ! empty( $removed ) ) {
				self::update_option_raw( self::ACTIVE_PLUGINS, array_values( $next ) );
			}
			return $removed;
		}

		private static function record_event( $event ) {
			$events = self::get_option_raw( self::EVENTS_OPTION );
			if ( ! is_array( $events ) ) {
				$events = array();
			}
			$events[] = $event;
			if ( count( $events ) > self::MAX_EVENTS ) {
				$events = array_slice( $events, -self::MAX_EVENTS );
			}
			self::update_option_raw( self::EVENTS_OPTION, $events );
		}

		/**
		 * Read an option. Prefer the normal API when it is available; otherwise
		 * fall back to a direct DB read so we still work mid-fatal.
		 *
		 * @param string $name Option name.
		 * @return mixed
		 */
		private static function get_option_raw( $name ) {
			if ( function_exists( 'get_option' ) ) {
				$val = get_option( $name, null );
				if ( null !== $val ) {
					return $val;
				}
			}
			global $wpdb;
			if ( isset( $wpdb ) && is_object( $wpdb ) && ! empty( $wpdb->options ) ) {
				$row = $wpdb->get_var(
					$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name )
				);
				if ( null !== $row ) {
					$un = @unserialize( $row );
					return false === $un && 'b:0;' !== $row ? $row : $un;
				}
			}
			return null;
		}

		/**
		 * Write an option. Prefer the normal API; fall back to a direct DB write.
		 *
		 * @param string $name  Option name.
		 * @param mixed  $value Value.
		 * @return void
		 */
		private static function update_option_raw( $name, $value ) {
			if ( function_exists( 'update_option' ) ) {
				update_option( $name, $value, false );
				return;
			}
			global $wpdb;
			if ( isset( $wpdb ) && is_object( $wpdb ) && ! empty( $wpdb->options ) ) {
				$serialized = maybe_serialize( $value );
				$exists     = $wpdb->get_var(
					$wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name )
				);
				if ( null === $exists ) {
					$wpdb->insert(
						$wpdb->options,
						array(
							'option_name'  => $name,
							'option_value' => $serialized,
							'autoload'     => 'no',
						)
					);
				} else {
					$wpdb->update(
						$wpdb->options,
						array( 'option_value' => $serialized ),
						array( 'option_name' => $name )
					);
				}
			}
		}
	}

	Marqira_Guard::boot();
}
