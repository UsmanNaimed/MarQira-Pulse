<?php
/**
 * Bounded security-event logger for MarQira Connector.
 *
 * Stores log entries in a custom, size-capped database table
 * ({prefix}marqira_log). Never logs passwords, tokens, secrets, or PII.
 *
 * Table is created on activation (Marqira_Logger::install_table()) and
 * dropped on uninstall (Marqira_Logger::uninstall_table()).
 *
 * @package Marqira_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Marqira_Logger
 */
class Marqira_Logger {

	/**
	 * Return the full (prefixed) log table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'marqira_log';
	}

	/**
	 * Create or upgrade the log table using dbDelta.
	 *
	 * Safe to call on every activation — dbDelta is idempotent.
	 *
	 * @return void
	 */
	public static function install_table() {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime            NOT NULL,
			level      varchar(10)         NOT NULL DEFAULT 'info',
			event      varchar(100)        NOT NULL DEFAULT '',
			message    text                NOT NULL,
			ip_address varchar(45)         NOT NULL DEFAULT '',
			ip_source  varchar(50)         NOT NULL DEFAULT '',
			username   varchar(200)        NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY idx_created_at (created_at),
			KEY idx_level (level)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Drop the log table. Called from uninstall.php only.
	 *
	 * @return void
	 */
	public static function uninstall_table() {
		global $wpdb;

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Core log method. Inserts one row and prunes the table to the cap.
	 *
	 * Never logs passwords, tokens, secrets, Authorization headers,
	 * HMAC signatures, or encryption keys.
	 *
	 * @param string $event     Short machine-readable event key.
	 * @param string $message   Human-readable description.
	 * @param string $level     'info', 'warning', or 'error'.
	 * @param string $ip        Client IP address (optional).
	 * @param string $source    IP detection source (optional).
	 * @param string $username  WordPress username (optional, not an email).
	 * @return void
	 */
	public static function log(
		$event,
		$message,
		$level     = 'info',
		$ip        = '',
		$source    = '',
		$username  = ''
	) {
		global $wpdb;

		$table = self::table_name();

		$level    = self::sanitize_field( $level );
		$level    = in_array( $level, array( 'info', 'warning', 'error' ), true ) ? $level : 'info';
		$event    = self::sanitize_field( $event );
		$message  = self::sanitize_field( $message );
		$ip       = self::sanitize_field( $ip );
		$source   = self::sanitize_field( $source );
		$username = self::sanitize_field( $username );

		// Suppress DB errors so a missing table never breaks a request.
		$prev_suppress = $wpdb->suppress_errors( true );

		$wpdb->insert(
			$table,
			array(
				'created_at' => current_time( 'mysql', true ), // UTC
				'level'      => $level,
				'event'      => $event,
				'message'    => $message,
				'ip_address' => $ip,
				'ip_source'  => $source,
				'username'   => $username,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		self::prune_to_cap();

		$wpdb->suppress_errors( $prev_suppress );
	}

	/**
	 * Return the most recent log entries for display in the Diagnostics tab.
	 *
	 * @param int $limit Maximum number of rows to return (1–200).
	 * @return array  Array of associative arrays, newest first.
	 */
	public static function get_recent( $limit = 20 ) {
		global $wpdb;

		$limit = max( 1, min( 200, (int) $limit ) );
		$table = self::table_name();

		$prev_suppress = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, created_at, level, event, message, ip_address, ip_source, username FROM {$table} ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		$wpdb->suppress_errors( $prev_suppress );

		return is_array( $rows ) ? $rows : array();
	}

	// -------------------------------------------------------------------------
	// Convenience helpers — one per security-relevant event type.
	// -------------------------------------------------------------------------

	/**
	 * Log a successful (allowed) Application Password authentication.
	 *
	 * @param string $ip       Detected client IP.
	 * @param string $source   IP detection source.
	 * @param string $username WordPress username.
	 * @return void
	 */
	public static function log_app_password_allowed( $ip, $source, $username ) {
		self::log(
			'app_password_allowed',
			sprintf(
				'Application Password ALLOWED for user "%s" from %s (source: %s).',
				$username,
				$ip,
				$source
			),
			'info',
			$ip,
			$source,
			$username
		);
	}

	/**
	 * Log a denied Application Password authentication attempt.
	 *
	 * @param string $ip       Detected client IP.
	 * @param string $source   IP detection source.
	 * @param string $username WordPress username.
	 * @return void
	 */
	public static function log_app_password_denied( $ip, $source, $username ) {
		self::log(
			'app_password_denied',
			sprintf(
				'Application Password DENIED for user "%s" from %s (source: %s) — IP not in allowed list.',
				$username,
				$ip,
				$source
			),
			'warning',
			$ip,
			$source,
			$username
		);
	}

	/**
	 * Log a denied REST API request (when REST restriction is enabled).
	 *
	 * @param string $ip     Detected client IP.
	 * @param string $source IP detection source.
	 * @param string $route  REST route that was blocked.
	 * @return void
	 */
	public static function log_rest_denied( $ip, $source, $route ) {
		self::log(
			'rest_denied',
			sprintf(
				'REST API DENIED from %s (source: %s) — route: %s.',
				$ip,
				$source,
				$route
			),
			'warning',
			$ip,
			$source,
			''
		);
	}

	/**
	 * Log plugin activation.
	 *
	 * @return void
	 */
	public static function log_activation() {
		self::log(
			'plugin_activated',
			'MarQira Connector activated (version ' . MARQIRA_CONNECTOR_VERSION . ').',
			'info'
		);
	}

	/**
	 * Log plugin deactivation.
	 *
	 * @return void
	 */
	public static function log_deactivation() {
		self::log(
			'plugin_deactivated',
			'MarQira Connector deactivated (version ' . MARQIRA_CONNECTOR_VERSION . ').',
			'info'
		);
	}

	/**
	 * Log a settings save by an admin user.
	 *
	 * @return void
	 */
	public static function log_settings_saved() {
		$user     = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$username = ( $user instanceof WP_User ) ? $user->user_login : '';
		self::log(
			'settings_saved',
			'Plugin settings updated.',
			'info',
			'',
			'',
			$username
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Trim the table down to the configured row cap.
	 *
	 * Deletes the oldest rows when the total count exceeds
	 * MARQIRA_CONNECTOR_LOG_CAP. Runs after every insert.
	 *
	 * @return void
	 */
	private static function prune_to_cap() {
		global $wpdb;

		$cap = defined( 'MARQIRA_CONNECTOR_LOG_CAP' ) ? (int) MARQIRA_CONNECTOR_LOG_CAP : 500;
		if ( $cap < 10 ) {
			$cap = 10; // Safety floor.
		}

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		if ( $count <= $cap ) {
			return;
		}

		$excess = $count - $cap;

		// DELETE ... ORDER BY id ASC LIMIT n is standard MySQL/MariaDB syntax.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$table} ORDER BY id ASC LIMIT %d",
				$excess
			)
		);
	}

	/**
	 * Sanitize a single field value for safe storage and display.
	 *
	 * Strips newlines (log-injection prevention) and truncates very long values.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function sanitize_field( $value ) {
		$value = (string) $value;
		$value = str_replace( array( "\r", "\n", "\t" ), ' ', $value );
		$value = trim( $value );
		// Hard-cap individual field lengths to stay within column widths.
		if ( strlen( $value ) > 500 ) {
			$value = substr( $value, 0, 500 );
		}
		return $value;
	}
}
