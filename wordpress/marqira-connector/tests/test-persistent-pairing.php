<?php
/**
 * Tests for durable pairing that survives plugin deletion / reinstallation.
 *
 * The MarQira pairing credentials (option `marqira_site_credentials`) are a
 * DURABLE connection identity. They must survive the ordinary WordPress
 * lifecycle:
 *
 *     Deactivate → Delete plugin → Reinstall plugin → Still connected
 *
 * with NO new enrollment code and NO duplicate site in the dashboard. The
 * credentials are removed only by exactly two explicit actions:
 *   1. "Disconnect from MarQira Pulse" inside WordPress (Marqira_Enrollment::disconnect()).
 *   2. Revocation from the MarQira dashboard (heartbeat 403 → handle_revocation()).
 *
 * This suite verifies:
 *   - the uninstaller keeps the durable credentials but removes disposable state;
 *   - after reinstall the site is still enrolled with the SAME identity;
 *   - the heartbeat schedule restores automatically with no reconnection;
 *   - explicit Disconnect DOES clear credentials and the cron;
 *   - dashboard revocation DOES clear credentials and the cron.
 *
 * Run via: php tests/run.php
 *
 * @package Marqira_Connector
 */

require_once __DIR__ . '/bootstrap.php';

// ---------------------------------------------------------------------------
// WordPress stubs (cron + transport) — same shape as the heartbeat cron test.
// ---------------------------------------------------------------------------
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

$GLOBALS['__mq_cron']           = array();
$GLOBALS['__mq_schedule_calls'] = 0;
$GLOBALS['__mq_actions']        = array();
$GLOBALS['__mq_http_mode']      = 'ok'; // 'ok' | 'revoked'

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = array() ) {
		return isset( $GLOBALS['__mq_cron'][ $hook ] ) ? $GLOBALS['__mq_cron'][ $hook ] : false;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
		if ( isset( $GLOBALS['__mq_cron'][ $hook ] ) ) {
			return false;
		}
		$GLOBALS['__mq_cron'][ $hook ] = $timestamp;
		$GLOBALS['__mq_schedule_calls']++;
		return true;
	}
}
if ( ! function_exists( 'wp_unschedule_event' ) ) {
	function wp_unschedule_event( $timestamp, $hook, $args = array() ) {
		unset( $GLOBALS['__mq_cron'][ $hook ] );
		return true;
	}
}
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook, $args = array() ) {
		unset( $GLOBALS['__mq_cron'][ $hook ] );
		return true;
	}
}
if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min = 0, $max = 0 ) {
		return mt_rand( $min, $max );
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['__mq_actions'][ $hook ][] = $callback;
		return true;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook ) {
		if ( empty( $GLOBALS['__mq_actions'][ $hook ] ) ) {
			return;
		}
		foreach ( $GLOBALS['__mq_actions'][ $hook ] as $callback ) {
			call_user_func( $callback );
		}
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url() {
		return 'https://example.com';
	}
}
if ( ! function_exists( 'site_url' ) ) {
	function site_url() {
		return 'https://example.com';
	}
}
if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() {
		return false;
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return ( $thing instanceof WP_Error );
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function get_error_message() {
			return 'stub error';
		}
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	/**
	 * Mode-driven transport: 'ok' returns 200, 'revoked' returns the API's
	 * 403 site_revoked signal so we can exercise self-disconnect.
	 */
	function wp_remote_post( $url, $args = array() ) {
		if ( 'revoked' === $GLOBALS['__mq_http_mode'] ) {
			return array(
				'response' => array( 'code' => 403 ),
				'body'     => '{"error":"site_revoked","site_revoked":true}',
			);
		}
		return array( 'response' => array( 'code' => 200 ), 'body' => 'ok' );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return isset( $response['body'] ) ? $response['body'] : '';
	}
}
if ( ! class_exists( 'Marqira_Diagnostics' ) ) {
	class Marqira_Diagnostics {
		public static function get_all() {
			return array(
				'wp_version'      => '6.5',
				'php_version'     => PHP_VERSION,
				'plugin_version'  => '1.2.0',
				'server_addr'     => '203.0.113.10',
				'server_hostname' => 'web01',
				'server_software' => 'nginx',
				'is_multisite'    => false,
			);
		}
	}
}

// Minimal $wpdb stub so the uninstaller can DROP the log table.
if ( ! class_exists( 'MQ_Wpdb_Stub' ) ) {
	class MQ_Wpdb_Stub {
		public $prefix       = 'wp_';
		public $queries      = array();
		public function query( $sql ) {
			$this->queries[] = $sql;
			return true;
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-marqira-heartbeat.php';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Full reset: wipes the entire in-memory WordPress state (fresh install).
 */
function mq_reset_all() {
	$GLOBALS['__mq_cron']           = array();
	$GLOBALS['__mq_schedule_calls'] = 0;
	$GLOBALS['__mq_actions']        = array();
	$GLOBALS['__mq_options']        = array();
	$GLOBALS['__mq_transients']     = array();
	$GLOBALS['__mq_http_mode']      = 'ok';
	mq_reset_request_cache();
}

/**
 * Reset only the per-request credential cache — as if a brand-new PHP request
 * (or a freshly reinstalled plugin) had started. Persisted options/credentials
 * intentionally survive, exactly like the WordPress database does.
 */
function mq_reset_request_cache() {
	$ref  = new ReflectionClass( 'Marqira_Enrollment' );
	$prop = $ref->getProperty( 'credentials_cache' );
	$prop->setAccessible( true );
	$prop->setValue( null, false );
}

/**
 * Store valid encrypted credentials so the site reads as "enrolled".
 */
function mq_enroll_test_site() {
	$creds     = array(
		'site_uuid'   => '11111111-2222-3333-4444-555555555555',
		'site_secret' => 'super-secret-value',
		'kid'         => 'key-1',
		'api_url'     => 'https://api.example.test',
	);
	$encrypted = Marqira_Crypto::encrypt( json_encode( $creds ) );
	update_option( Marqira_Enrollment::CREDENTIALS_OPTION, $encrypted );
	mq_reset_request_cache();
}

/**
 * Run the real uninstaller (Plugins → Delete) against the in-memory state.
 */
function mq_run_uninstaller() {
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', 'marqira-connector/marqira-connector.php' );
	}
	$GLOBALS['wpdb'] = new MQ_Wpdb_Stub();
	require dirname( __DIR__ ) . '/uninstall.php';
}

// ===========================================================================
// Scenario A: Deactivate → Delete → Reinstall → Still connected.
// ===========================================================================
mq_reset_all();
mq_enroll_test_site();
Marqira_Heartbeat::ensure_scheduled();
$identity_before = get_option( Marqira_Enrollment::CREDENTIALS_OPTION );
mq_ok( Marqira_Enrollment::is_enrolled(), 'A: site is enrolled before deletion' );
mq_ok( false !== wp_next_scheduled( Marqira_Heartbeat::CRON_HOOK ), 'A: heartbeat cron scheduled before deletion' );

// Step 1 — Deactivate the plugin (deactivation hook clears the schedule).
Marqira_Heartbeat::unregister_cron();
mq_ok( false === wp_next_scheduled( Marqira_Heartbeat::CRON_HOOK ), 'A: deactivation clears the heartbeat cron' );
mq_ok( Marqira_Enrollment::is_enrolled(), 'A: deactivation does NOT remove pairing credentials' );

// Also seed disposable state so we can prove the uninstaller removes it.
update_option( 'marqira_connector_settings', array( 'foo' => 'bar' ) );
set_transient( 'marqira_last_heartbeat_sent', time(), HOUR_IN_SECONDS );

// Step 2 — Delete the plugin (WordPress fires uninstall.php).
mq_run_uninstaller();
mq_reset_request_cache(); // deletion tears down the PHP process/caches.

mq_ok( Marqira_Enrollment::is_enrolled(), 'A: durable credentials SURVIVE plugin deletion (uninstall.php)' );
$identity_after = get_option( Marqira_Enrollment::CREDENTIALS_OPTION );
mq_ok( $identity_before === $identity_after, 'A: the surviving credential is byte-identical (same site identity)' );
mq_ok( false === get_option( 'marqira_connector_settings', false ), 'A: disposable settings option WAS removed by uninstall' );
mq_ok( false === get_transient( 'marqira_last_heartbeat_sent' ), 'A: disposable heartbeat transient WAS removed by uninstall' );
mq_ok( false === wp_next_scheduled( Marqira_Heartbeat::CRON_HOOK ), 'A: cron remains cleared immediately after deletion' );

// Step 3 — Reinstall + activate the plugin. Activation + the per-request
// init() self-heal must restore the heartbeat with no reconnection.
Marqira_Heartbeat::register_cron(); // activation hook
Marqira_Heartbeat::init();          // runs on every request
mq_ok( Marqira_Enrollment::is_enrolled(), 'A: still enrolled after reinstall (no new enrollment code needed)' );
mq_ok( false !== wp_next_scheduled( Marqira_Heartbeat::CRON_HOOK ), 'A: heartbeat schedule restored automatically after reinstall' );

$creds_after = Marqira_Enrollment::get_credentials();
mq_ok(
	is_array( $creds_after ) && '11111111-2222-3333-4444-555555555555' === $creds_after['site_uuid'],
	'A: reinstalled connector reuses the SAME site UUID (no duplicate dashboard site)'
);

// ===========================================================================
// Scenario B: explicit "Disconnect from MarQira Pulse" clears everything.
// ===========================================================================
mq_reset_all();
mq_enroll_test_site();
Marqira_Heartbeat::ensure_scheduled();
mq_ok( Marqira_Enrollment::is_enrolled(), 'B: enrolled before Disconnect' );
mq_ok( false !== wp_next_scheduled( Marqira_Heartbeat::CRON_HOOK ), 'B: cron scheduled before Disconnect' );

Marqira_Enrollment::disconnect();
mq_reset_request_cache();
mq_ok( ! Marqira_Enrollment::is_enrolled(), 'B: Disconnect removes the pairing credentials' );
mq_ok( empty( get_option( Marqira_Enrollment::CREDENTIALS_OPTION, '' ) ), 'B: credentials option is empty after Disconnect' );
mq_ok( false === wp_next_scheduled( Marqira_Heartbeat::CRON_HOOK ), 'B: Disconnect also tears down the heartbeat cron' );

// ===========================================================================
// Scenario C: dashboard revocation (heartbeat 403) self-disconnects.
// ===========================================================================
mq_reset_all();
mq_enroll_test_site();
Marqira_Heartbeat::init(); // schedules + binds the cron callback
mq_ok( Marqira_Enrollment::is_enrolled(), 'C: enrolled before revocation' );
mq_ok( false !== wp_next_scheduled( Marqira_Heartbeat::CRON_HOOK ), 'C: cron scheduled before revocation' );

$GLOBALS['__mq_http_mode'] = 'revoked'; // dashboard removed/revoked the site
Marqira_Heartbeat::send_heartbeat();
mq_reset_request_cache();
mq_ok( ! Marqira_Enrollment::is_enrolled(), 'C: revocation (403) clears the pairing credentials' );
mq_ok( false === wp_next_scheduled( Marqira_Heartbeat::CRON_HOOK ), 'C: revocation tears down the heartbeat cron' );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
echo "test-persistent-pairing.php: {$GLOBALS['__mq_pass']} passed, {$GLOBALS['__mq_fail']} failed\n";
