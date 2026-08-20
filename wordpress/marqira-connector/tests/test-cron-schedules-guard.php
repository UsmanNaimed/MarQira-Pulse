<?php
/**
 * Regression test for the WordPress 7.1 core-upgrade crash.
 *
 * The plugin registers the `cron_schedules` filter at file scope. Its callback
 * depends on Marqira_Heartbeat / Marqira_Data_Collector, which are loaded by
 * marqira_connector_load_includes(). Before the fix that loader only ran on the
 * `init` action, so if WordPress applied the `cron_schedules` filter *before*
 * `init` — as it does when rescheduling an overdue cron event during
 * core-upgrade finalization / the first request after an upgrade — the callback
 * hit "Fatal error: Class \"Marqira_Heartbeat\" not found" and took the site
 * down until the plugin was deactivated and reactivated.
 *
 * This test loads the real plugin bootstrap file, then applies the
 * `cron_schedules` filter WITHOUT firing `plugins_loaded` or `init` first, and
 * asserts that:
 *   - no fatal occurs;
 *   - the custom schedules are still added (never silently dropped);
 *   - existing core schedules are preserved (backward compatible).
 *
 * If the fix is ever reverted, applying the filter here fatals and run.php marks
 * this test failed.
 *
 * Run via: php tests/run.php
 *
 * @package Marqira_Connector
 */

// ---------------------------------------------------------------------------
// Minimal WordPress environment — deliberately does NOT include bootstrap.php
// so we control exactly which classes are (not) loaded and never pre-define
// the plugin classes.
// ---------------------------------------------------------------------------
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

// A tiny but faithful hook registry.
$GLOBALS['__hooks'] = array();

function add_action( $hook, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['__hooks'][ $hook ][] = $cb;
	return true;
}
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['__hooks'][ $hook ][] = $cb;
	return true;
}
function apply_filters( $hook, $value ) {
	if ( ! empty( $GLOBALS['__hooks'][ $hook ] ) ) {
		foreach ( $GLOBALS['__hooks'][ $hook ] as $cb ) {
			$value = call_user_func( $cb, $value );
		}
	}
	return $value;
}
function do_action( $hook ) {
	if ( ! empty( $GLOBALS['__hooks'][ $hook ] ) ) {
		foreach ( $GLOBALS['__hooks'][ $hook ] as $cb ) {
			call_user_func( $cb );
		}
	}
}

function register_activation_hook( $file, $cb ) {}
function register_deactivation_hook( $file, $cb ) {}
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'http://example.test/wp-content/plugins/marqira-connector/'; }
function is_admin() { return false; }
function get_option( $name, $default = false ) { return $default; }
function __( $text, $domain = 'default' ) { return $text; }

// ---------------------------------------------------------------------------
// Tiny assertion helpers (mirrors bootstrap.php so run.php counts marks).
// ---------------------------------------------------------------------------
$GLOBALS['__mq_pass'] = 0;
$GLOBALS['__mq_fail'] = 0;

function mq_ok( $cond, $label ) {
	if ( $cond ) {
		$GLOBALS['__mq_pass']++;
		echo "  \xE2\x9C\x93 {$label}\n";
	} else {
		$GLOBALS['__mq_fail']++;
		echo "  \xE2\x9C\x97 FAIL: {$label}\n";
	}
}

echo "cron_schedules pre-init guard (WordPress 7.1 upgrade-crash regression)\n";

// ---------------------------------------------------------------------------
// Load the REAL plugin bootstrap file. This registers the plugin's hooks but
// fires nothing — reproducing the exact state WordPress is in when it evaluates
// cron schedules before `init` during the upgrade window.
// ---------------------------------------------------------------------------
require dirname( __DIR__ ) . '/marqira-connector.php';

mq_ok( ! class_exists( 'Marqira_Heartbeat', false ), 'precondition: Heartbeat class NOT loaded (init/plugins_loaded have not fired)' );
mq_ok( isset( $GLOBALS['__hooks']['cron_schedules'] ), 'cron_schedules filter is registered at file scope' );

// ---------------------------------------------------------------------------
// Reproduce the crash trigger. Pre-fix this fataled with:
//   Fatal error: Class "Marqira_Heartbeat" not found
// ---------------------------------------------------------------------------
$schedules = apply_filters( 'cron_schedules', array() );

mq_ok( is_array( $schedules ), 'applying cron_schedules before init returns an array (no fatal)' );
mq_ok( isset( $schedules['marqira_heartbeat_interval'] ), 'heartbeat custom interval is added even pre-init' );
mq_ok( isset( $schedules['marqira_data_collection_interval'] ), 'data-collection custom interval is added even pre-init' );
mq_ok(
	isset( $schedules['marqira_heartbeat_interval']['interval'] )
		&& 180 === $schedules['marqira_heartbeat_interval']['interval'],
	'heartbeat interval value is correct (3 minutes = 180s)'
);
mq_ok(
	isset( $schedules['marqira_data_collection_interval']['interval'] )
		&& 21600 === $schedules['marqira_data_collection_interval']['interval'],
	'data-collection interval value is correct (6 hours = 21600s)'
);

// The callback should have self-loaded the dependency classes as a real fix
// (not merely skipped them), so the schedules are actually registered.
mq_ok( class_exists( 'Marqira_Heartbeat', false ), 'callback self-loaded the Marqira_Heartbeat dependency' );
mq_ok( class_exists( 'Marqira_Data_Collector', false ), 'callback self-loaded the Marqira_Data_Collector dependency' );

// ---------------------------------------------------------------------------
// Backward compatibility: existing core schedules must be preserved.
// ---------------------------------------------------------------------------
$existing = array( 'hourly' => array( 'interval' => 3600, 'display' => 'Once Hourly' ) );
$merged   = apply_filters( 'cron_schedules', $existing );
mq_ok(
	isset( $merged['hourly'] ) && isset( $merged['marqira_heartbeat_interval'] ),
	'existing core schedules preserved alongside custom ones (backward compatible)'
);

// ---------------------------------------------------------------------------
// Defensive: a malformed (non-array) value must not fatal.
// ---------------------------------------------------------------------------
$from_bad = apply_filters( 'cron_schedules', null );
mq_ok( is_array( $from_bad ) && isset( $from_bad['marqira_heartbeat_interval'] ), 'non-array input handled defensively (no fatal, schedules still added)' );

// ---------------------------------------------------------------------------
// The normal path (plugins_loaded before any filter) must also work and must
// not double-register or error.
// ---------------------------------------------------------------------------
do_action( 'plugins_loaded' );
$after_pl = apply_filters( 'cron_schedules', array() );
mq_ok( isset( $after_pl['marqira_heartbeat_interval'] ), 'schedules present after normal plugins_loaded bootstrap too' );

echo "\n  {$GLOBALS['__mq_pass']} passed, {$GLOBALS['__mq_fail']} failed\n";
