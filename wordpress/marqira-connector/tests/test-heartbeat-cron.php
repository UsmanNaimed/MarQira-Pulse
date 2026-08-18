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
        /**
         * Mock transport that mirrors the real API's heartbeat validation so we can
         * reproduce the HTTP 422 regression. The API declares server_ip and
         * origin_ip_candidate as `nullable|ip`, so a present-but-invalid value must
         * fail with 422, while an omitted value is accepted.
         */
        function wp_remote_post( $url, $args = array() ) {
                $GLOBALS['__mq_last_post_url']  = $url;
                $body                           = isset( $args['body'] ) ? json_decode( $args['body'], true ) : array();
                $GLOBALS['__mq_last_post_body'] = is_array( $body ) ? $body : array();

                foreach ( array( 'server_ip', 'origin_ip_candidate' ) as $ip_field ) {
                        if ( array_key_exists( $ip_field, $GLOBALS['__mq_last_post_body'] ) ) {
                                $val = $GLOBALS['__mq_last_post_body'][ $ip_field ];
                                if ( ! is_string( $val ) || false === filter_var( $val, FILTER_VALIDATE_IP ) ) {
                                        return array(
                                                'response' => array( 'code' => 422 ),
                                                'body'     => '{"error":"Validation failed"}',
                                        );
                                }
                        }
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

// Update-inventory stubs. collect_metadata() calls collect_update_inventory(),
// which require_once's wp-admin/includes/update.php unless these three functions
// already exist. Defining them here keeps the standalone harness self-contained
// (no WordPress install) and lets the full heartbeat send path run in tests.
if ( ! function_exists( 'get_core_updates' ) ) {
        function get_core_updates() {
                return array();
        }
}
if ( ! function_exists( 'get_plugin_updates' ) ) {
        function get_plugin_updates() {
                return array();
        }
}
if ( ! function_exists( 'get_theme_updates' ) ) {
        function get_theme_updates() {
                return array();
        }
}

// wp_doing_cron() stub — the watchdog checks it to avoid double-firing in cron.
if ( ! function_exists( 'wp_doing_cron' ) ) {
        function wp_doing_cron() {
                return false;
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
        $GLOBALS['__mq_last_post_body'] = null;

        // Deterministic server environment per test.
        unset( $_SERVER['SERVER_ADDR'], $_SERVER['LOCAL_ADDR'] );

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
//    (No server IP available here — see test 9 for the omission behavior.)
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
// 8. Cron event is scheduled at the enforced 3-minute interval.
// ---------------------------------------------------------------------------
mq_ok( 3 === Marqira_Heartbeat::HEARTBEAT_INTERVAL_MINUTES, 'enforced cadence constant is 3 minutes' );

$schedules = Marqira_Heartbeat::add_cron_interval( array() );
mq_ok(
        isset( $schedules[ Marqira_Heartbeat::CRON_INTERVAL ]['interval'] )
                && 180 === $schedules[ Marqira_Heartbeat::CRON_INTERVAL ]['interval'],
        'registered cron interval is 180 seconds (3 minutes)'
);

mq_reset_state();
mq_enroll_test_site();
$before = time();
Marqira_Heartbeat::ensure_scheduled();
$scheduled_at = wp_next_scheduled( Marqira_Heartbeat::CRON_HOOK );
mq_ok(
        $scheduled_at >= ( $before + 180 )
                && $scheduled_at <= ( $before + 180 + Marqira_Heartbeat::HEARTBEAT_JITTER_SECONDS + 1 ),
        'next run is ~3 minutes out (interval + bounded jitter)'
);

// ---------------------------------------------------------------------------
// 9. REGRESSION (HTTP 422): in a WP-Cron/LiteSpeed context where SERVER_ADDR
//    is the "unknown" sentinel, the IP fields are omitted and the heartbeat is
//    accepted (200) instead of failing validation (422).
// ---------------------------------------------------------------------------
mq_reset_state();
mq_enroll_test_site();
$_SERVER['SERVER_ADDR'] = 'unknown'; // what LiteSpeed WP-Cron produced in production
Marqira_Heartbeat::send_heartbeat();
$cron_body = $GLOBALS['__mq_last_post_body'];
mq_ok( is_array( $cron_body ) && ! array_key_exists( 'server_ip', $cron_body ), 'invalid server_ip is omitted from the payload' );
mq_ok( is_array( $cron_body ) && ! array_key_exists( 'origin_ip_candidate', $cron_body ), 'invalid origin_ip_candidate is omitted from the payload' );
mq_ok( false !== get_transient( 'marqira_last_heartbeat_sent' ), 'heartbeat with omitted IPs is accepted (no 422)' );

// A malformed IP:port value must also be handled (omitted or normalized), never
// sent raw. Here a garbage value cannot be normalized, so it is omitted.
mq_reset_state();
mq_enroll_test_site();
$_SERVER['SERVER_ADDR'] = 'web01.litespeed.local:8443'; // hostname:port — not an IP
Marqira_Heartbeat::send_heartbeat();
$bad_body = $GLOBALS['__mq_last_post_body'];
mq_ok( is_array( $bad_body ) && ! array_key_exists( 'server_ip', $bad_body ), 'hostname:port value is not sent as server_ip' );
mq_ok( false !== get_transient( 'marqira_last_heartbeat_sent' ), 'heartbeat still succeeds when server IP is a hostname' );

// ---------------------------------------------------------------------------
// 10. Valid SERVER_ADDR is normalized and included in the payload.
// ---------------------------------------------------------------------------
mq_reset_state();
mq_enroll_test_site();
$_SERVER['SERVER_ADDR'] = '198.51.100.7:443'; // valid IPv4 with a port
Marqira_Heartbeat::send_heartbeat();
$good_body = $GLOBALS['__mq_last_post_body'];
mq_ok( is_array( $good_body ) && isset( $good_body['server_ip'] ) && '198.51.100.7' === $good_body['server_ip'], 'valid server_ip is normalized (port stripped) and included' );
mq_ok( isset( $good_body['origin_ip_candidate'] ) && '198.51.100.7' === $good_body['origin_ip_candidate'], 'origin_ip_candidate matches the normalized server_ip' );

// ---------------------------------------------------------------------------
// 11. Immediate and scheduled heartbeats produce identical normalized IPs.
//     (Same $_SERVER -> same payload, whether called directly or via the hook.)
// ---------------------------------------------------------------------------
mq_reset_state();
mq_enroll_test_site();
$_SERVER['SERVER_ADDR'] = '203.0.113.55';
// Immediate path (as invoked by the admin enrollment handler).
Marqira_Heartbeat::send_heartbeat();
$immediate_ip = isset( $GLOBALS['__mq_last_post_body']['server_ip'] ) ? $GLOBALS['__mq_last_post_body']['server_ip'] : null;
// Scheduled path (as invoked by WP-Cron firing the hook).
$GLOBALS['__mq_last_post_body'] = null;
Marqira_Heartbeat::init();
do_action( Marqira_Heartbeat::CRON_HOOK );
$scheduled_ip = isset( $GLOBALS['__mq_last_post_body']['server_ip'] ) ? $GLOBALS['__mq_last_post_body']['server_ip'] : null;
mq_ok( '203.0.113.55' === $immediate_ip && $immediate_ip === $scheduled_ip, 'immediate and scheduled heartbeats send the same normalized server_ip' );

// ---------------------------------------------------------------------------
// 12. send_heartbeat() returns a {success,message,status_code} result.
// ---------------------------------------------------------------------------
mq_reset_state();
mq_enroll_test_site();
$_SERVER['SERVER_ADDR'] = '203.0.113.55';
$res = Marqira_Heartbeat::send_heartbeat();
mq_ok(
        is_array( $res ) && true === $res['success'] && 200 === $res['status_code'],
        'send_heartbeat() returns success + status 200 on a good beat'
);
mq_ok(
        is_array( $res ) && isset( $res['message'] ) && '' !== $res['message'],
        'send_heartbeat() result carries a human-readable message'
);

// Not enrolled -> failure result with status_code 0 (never reached the API).
mq_reset_state();
$res = Marqira_Heartbeat::send_heartbeat();
mq_ok(
        is_array( $res ) && false === $res['success'] && 0 === $res['status_code'],
        'send_heartbeat() returns a failure result when the site is not enrolled'
);

// ---------------------------------------------------------------------------
// 13. Watchdog hard-enforces the cadence independently of WP-Cron.
// ---------------------------------------------------------------------------

// (a) Fires (schedules a deferred beat) when a beat is overdue.
mq_reset_state();
mq_enroll_test_site();
Marqira_Heartbeat::maybe_run_watchdog();
mq_ok(
        has_action( 'shutdown', array( 'Marqira_Heartbeat', 'run_watchdog_heartbeat' ) ),
        'watchdog schedules a deferred beat when one is overdue'
);
mq_ok(
        (int) get_option( Marqira_Heartbeat::LAST_ATTEMPT_OPTION, 0 ) > 0,
        'watchdog immediately stamps the last-attempt timestamp (claims the interval)'
);

// (b) Does NOT fire again within the interval.
mq_reset_state();
mq_enroll_test_site();
update_option( Marqira_Heartbeat::LAST_ATTEMPT_OPTION, time() );
Marqira_Heartbeat::maybe_run_watchdog();
mq_ok(
        ! has_action( 'shutdown', array( 'Marqira_Heartbeat', 'run_watchdog_heartbeat' ) ),
        'watchdog does not fire again within the 3-minute interval'
);

// (c) Fires once the interval has elapsed.
mq_reset_state();
mq_enroll_test_site();
update_option( Marqira_Heartbeat::LAST_ATTEMPT_OPTION, time() - ( 3 * MINUTE_IN_SECONDS ) - 1 );
Marqira_Heartbeat::maybe_run_watchdog();
mq_ok(
        has_action( 'shutdown', array( 'Marqira_Heartbeat', 'run_watchdog_heartbeat' ) ),
        'watchdog fires again once the interval has elapsed'
);

// (d) Skips an unenrolled site entirely.
mq_reset_state();
Marqira_Heartbeat::maybe_run_watchdog();
mq_ok(
        ! has_action( 'shutdown', array( 'Marqira_Heartbeat', 'run_watchdog_heartbeat' ) ),
        'watchdog does nothing on an unenrolled site'
);

// (e) Respects the stampede lock held by a concurrent request.
mq_reset_state();
mq_enroll_test_site();
update_option( Marqira_Heartbeat::LAST_ATTEMPT_OPTION, time() - ( 3 * MINUTE_IN_SECONDS ) - 1 );
set_transient( Marqira_Heartbeat::WATCHDOG_LOCK_KEY, time(), Marqira_Heartbeat::WATCHDOG_LOCK_TTL );
Marqira_Heartbeat::maybe_run_watchdog();
mq_ok(
        ! has_action( 'shutdown', array( 'Marqira_Heartbeat', 'run_watchdog_heartbeat' ) ),
        'watchdog respects the stampede lock (no duplicate beat under concurrency)'
);

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
echo "test-heartbeat-cron.php: {$GLOBALS['__mq_pass']} passed, {$GLOBALS['__mq_fail']} failed\n";
