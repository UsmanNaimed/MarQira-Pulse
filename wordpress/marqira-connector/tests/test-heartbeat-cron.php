<?php
/**
 * Tests for the heartbeat cron scheduling / self-healing logic.
 *
 * Verifies that:
 *   - an enrolled site schedules exactly one recurring heartbeat event;
 *   - an un-enrolled site schedules nothing;
 *   - repeated scheduling never creates a duplicate event;
 *   - a missing event is recreated automatically on plugin load (self-heal);
 *   - deactivation clears the scheduled hook;
 *   - init() wires the cron callback to the scheduled hook;
 *   - firing the hook actually sends a heartbeat.
 *
 * Run via: php tests/run.php
 *
 * @package Marqira_Connector
 */

require_once __DIR__ . '/bootstrap.php';

// ---------------------------------------------------------------------------
// Extra WordPress stubs needed only by the heartbeat class.
// ---------------------------------------------------------------------------
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

// In-memory cron event store: hook => timestamp.
$GLOBALS['__mq_cron']            = array();
$GLOBALS['__mq_schedule_calls']  = 0;
$GLOBALS['__mq_actions']         = array();

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = array() ) {
		return isset( $GLOBALS['__mq_cron'][ $hook ] ) ? $GLOBALS['__mq_cron'][ $hook ] : false;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
		// Mirror WP: refuse to double-book the same hook.
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
if ( ! function_exists( 'has_action' ) ) {
	function has_action( $hook, $callback = false ) {
		if ( empty( $GLOBALS['__mq_actions'][ $hook ] ) ) {
			return false;
		}
		if ( false === $callback ) {
			return true;
		}
		return in_array( $callback, $GLOBALS['__mq_actions'][ $hook ], true );
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

// Stubs for the send_heartbeat() transmission path.
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
	function wp_remote_post( $url, $args = array() ) {
		$GLOBALS['__mq_last_post_url'] = $url;
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

// Diagnostics stub (heartbeat metadata collection depends on it).
if ( ! class_exists( 'Marqira_Diagnostics' ) ) {
	class Marqira_Diagnostics {
		public static function get_all() {
			return array(
				'wp_version'      => '6.5',
				'php_version'     => PHP_VERSION,
				'plugin_version'  => '1.1.2',
				'server_addr'     => '203.0.113.10',
				'server_hostname' => 'web01',
				'server_software' => 'nginx',
				'is_multisite'    => false,
			);
		}
	}
}

// Load the class under test (not loaded by bootstrap.php).
require_once dirname( __DIR__ ) . '/includes/class-marqira-heartbeat.php';

// ---------------------------------------------------------------------------
// Test helpers
// ---------------------------------------------------------------------------

/**
 * Reset the enrollment per-request credentials cache + cron/option state so
 * each scenario starts clean.
 */
function mq_reset_state() {
	$GLOBALS['__mq_cron']           = array();
	$GLOBALS['__mq_schedule_calls'] = 0;
	$GLOBALS['__mq_actions']        = array();
	$GLOBALS['__mq_options']        = array();
	$GLOBALS['__mq_transients']     = array();

	$ref  = new ReflectionClass( 'Marqira_Enrollment' );
	$prop = $ref->getProperty( 'credentials_cache' );
	$prop->setAccessible( true );
	$prop->setValue( null, false );
}

/**
 * Store valid encrypted credentials so the site reads as "enrolled".
 */
function mq_enroll_test_site() {
	$creds = array(
		'site_uuid'   => '11111111-2222-3333-4444-555555555555',
		'site_secret' => 'super-secret-value',
		'kid'         => 'key-1',
		'api_url'     => 'https://api.example.test',
	);
	$encrypted = Marqira_Crypto::encrypt( json_encode( $creds ) );
	update_option( Marqira_Enrollment::CREDENTIALS_OPTION, $encrypted );

	// Invalidate the per-request cache so the new value is read back.
	$ref  = new ReflectionClass( 'Marqira_Enrollment' );
	$prop = $ref->getProperty( 'credentials_cache' );
	$prop->setAccessible( true );
	$prop->setValue( null, false );
}

// ---------------------------------------------------------------------------
// 1. Un-enrolled site schedules nothing.
// ---------------------------------------------------------------------------
mq_reset_state();
mq_ok( ! Marqira_Enrollment::is_enrolled(), 'fresh install reads as not enrolled' );
Marqira_Heartbeat::maybe_schedule();
mq_ok( false === wp_next_scheduled( Marqira_Heartbeat::CRON_HOOK ), 'no cron scheduled while un-enrolled' );

// ---------------------------------------------------------------------------
// 2. Enrolled site schedules exactly one recurring event.
// ---------------------------------------------------------------------------
mq_reset_state();
mq_enroll_test_site();
mq_ok( Marqira_Enrollment::is_enrolled(), 'site reads as enrolled after storing credentials' );
Marqira_Heartbeat::maybe_schedule();
mq_ok( false !== wp_next_scheduled( Marqira_Heartbeat::CRON_HOOK ), 'heartbeat cron scheduled after enrollment' );
mq_ok( 1 === $GLOBALS['__mq_schedule_calls'], 'exactly one schedule call was made' );

// ---------------------------------------------------------------------------
// 3. Repeated scheduling never duplicates the event.
// ---------------------------------------------------------------------------
Marqira_Heartbeat::ensure_scheduled();
Marqira_Heartbeat::maybe_schedule();
Marqira_Heartbeat::register_cron();
mq_ok( 1 === $GLOBALS['__mq_schedule_calls'], 'repeated ensure/maybe/register calls do not create duplicates' );

// ---------------------------------------------------------------------------
// 4. Self-heal: a missing event is recreated on plugin load.
// ---------------------------------------------------------------------------
mq_reset_state();
mq_enroll_test_site();
// Simulate the production bug: enrolled, but the recurring event is absent
// (e.g. after a plugin upgrade that never fired the activation hook).
mq_ok( false === wp_next_scheduled( Marqira_Heartbeat::CRON_HOOK ), 'precondition: enrolled but no cron event' );
Marqira_Heartbeat::init(); // this is what runs on every request via the `init` action
mq_ok( false !== wp_next_scheduled( Marqira_Heartbeat::CRON_HOOK ), 'init() self-heals the missing cron event' );

// ---------------------------------------------------------------------------
// 5. init() wires the cron callback to the scheduled hook.
// ---------------------------------------------------------------------------
mq_ok(
	has_action( Marqira_Heartbeat::CRON_HOOK, array( 'Marqira_Heartbeat', 'send_heartbeat' ) ),
	'init() binds send_heartbeat() to the cron hook'
);

// ---------------------------------------------------------------------------
// 6. Deactivation clears the scheduled hook.
// ---------------------------------------------------------------------------
mq_reset_state();
mq_enroll_test_site();
Marqira_Heartbeat::ensure_scheduled();
mq_ok( false !== wp_next_scheduled( Marqira_Heartbeat::CRON_HOOK ), 'precondition: event scheduled before deactivation' );
Marqira_Heartbeat::unregister_cron();
mq_ok( false === wp_next_scheduled( Marqira_Heartbeat::CRON_HOOK ), 'unregister_cron() clears the scheduled event' );

// ---------------------------------------------------------------------------
// 7. Firing the hook actually sends a heartbeat (end-to-end callback).
// ---------------------------------------------------------------------------
mq_reset_state();
mq_enroll_test_site();
Marqira_Heartbeat::init(); // registers the action + schedules
do_action( Marqira_Heartbeat::CRON_HOOK );
mq_ok(
	false !== get_transient( 'marqira_last_heartbeat_sent' ),
	'firing the cron hook sends a heartbeat (last-sent transient set)'
);

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
echo "test-heartbeat-cron.php: {$GLOBALS['__mq_pass']} passed, {$GLOBALS['__mq_fail']} failed\n";
