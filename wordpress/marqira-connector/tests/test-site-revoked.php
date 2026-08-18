<?php
/**
 * Tests for connector self-disconnect when the API revokes the site.
 *
 * When a site is disconnected/removed from the dashboard, the API answers the
 * next heartbeat with HTTP 403 and a JSON body of the form
 * {"error":"site_revoked","site_revoked":true}. The connector must then:
 *   - stop the recurring heartbeat cron event;
 *   - clear its stored credentials (so is_enrolled() becomes false);
 *   - NOT reschedule itself on the next plugin load (init self-heal).
 *
 * A plain 403 that is NOT a revocation signal must be treated as an ordinary
 * failure and must NOT wipe credentials (so a transient WAF/permission blip
 * never silently disconnects a healthy site).
 *
 * Run via: php tests/run.php
 *
 * @package Marqira_Connector
 */

require_once __DIR__ . '/bootstrap.php';

// ---------------------------------------------------------------------------
// Extra WordPress stubs needed by the heartbeat class.
// ---------------------------------------------------------------------------
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
        define( 'MINUTE_IN_SECONDS', 60 );
}

// In-memory cron event store: hook => timestamp.
$GLOBALS['__mq_cron']    = array();
$GLOBALS['__mq_actions'] = array();

// Controls what the mocked transport returns for the next heartbeat.
// One of: 'revoked_both', 'revoked_flag', 'revoked_error', 'forbidden_plain', 'ok'.
$GLOBALS['__mq_next_response'] = 'ok';

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
         * Mock transport whose response is driven by $GLOBALS['__mq_next_response']
         * so each scenario can exercise a specific API reply.
         */
        function wp_remote_post( $url, $args = array() ) {
                switch ( $GLOBALS['__mq_next_response'] ) {
                        case 'revoked_both':
                                return array(
                                        'response' => array( 'code' => 403 ),
                                        'body'     => '{"error":"site_revoked","site_revoked":true,"message":"This site has been disconnected."}',
                                );
                        case 'revoked_flag':
                                return array(
                                        'response' => array( 'code' => 403 ),
                                        'body'     => '{"site_revoked":true}',
                                );
                        case 'revoked_error':
                                return array(
                                        'response' => array( 'code' => 403 ),
                                        'body'     => '{"error":"site_revoked"}',
                                );
                        case 'forbidden_plain':
                                return array(
                                        'response' => array( 'code' => 403 ),
                                        'body'     => '{"error":"forbidden","message":"Temporary WAF block."}',
                                );
                        case 'ok':
                        default:
                                return array( 'response' => array( 'code' => 200 ), 'body' => 'ok' );
                }
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
// Update-inventory stubs so collect_metadata() does not require a WP install.
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
if ( ! class_exists( 'Marqira_Diagnostics' ) ) {
        class Marqira_Diagnostics {
                public static function get_all() {
                        return array(
                                'wp_version'      => '6.5',
                                'php_version'     => PHP_VERSION,
                                'plugin_version'  => '1.2.0',
                                'server_hostname' => 'web01',
                                'server_software' => 'nginx',
                                'is_multisite'    => false,
                        );
                }
        }
}

// Load the class under test.
require_once dirname( __DIR__ ) . '/includes/class-marqira-heartbeat.php';

echo "Marqira_Heartbeat (site revocation self-disconnect)\n";

/**
 * Reset cron/option/transient state and the enrollment credentials cache.
 */
function mq_reset_state() {
        $GLOBALS['__mq_cron']       = array();
        $GLOBALS['__mq_actions']    = array();
        $GLOBALS['__mq_options']    = array();
        $GLOBALS['__mq_transients'] = array();

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

        $ref  = new ReflectionClass( 'Marqira_Enrollment' );
        $prop = $ref->getProperty( 'credentials_cache' );
        $prop->setAccessible( true );
        $prop->setValue( null, false );
}

// ---------------------------------------------------------------------------
// 1. A 403 site_revoked response (both flag + error) triggers self-disconnect.
// ---------------------------------------------------------------------------
mq_reset_state();
mq_enroll_test_site();
Marqira_Heartbeat::ensure_scheduled();
mq_ok( false !== wp_next_scheduled( Marqira_Heartbeat::CRON_HOOK ), 'precondition: enrolled + heartbeat scheduled' );
mq_ok( Marqira_Enrollment::is_enrolled(), 'precondition: site reads as enrolled' );

$GLOBALS['__mq_next_response'] = 'revoked_both';
Marqira_Heartbeat::send_heartbeat();

mq_ok( ! Marqira_Enrollment::is_enrolled(), 'credentials cleared after site_revoked response' );
mq_ok( false === wp_next_scheduled( Marqira_Heartbeat::CRON_HOOK ), 'recurring heartbeat cron unscheduled after revocation' );
mq_ok( false === get_transient( 'marqira_last_heartbeat_sent' ), 'no success transient recorded for a revoked heartbeat' );

// ---------------------------------------------------------------------------
// 2. Revocation detected from the site_revoked flag alone.
// ---------------------------------------------------------------------------
mq_reset_state();
mq_enroll_test_site();
Marqira_Heartbeat::ensure_scheduled();
$GLOBALS['__mq_next_response'] = 'revoked_flag';
Marqira_Heartbeat::send_heartbeat();
mq_ok( ! Marqira_Enrollment::is_enrolled(), 'site_revoked flag alone triggers disconnect' );
mq_ok( false === wp_next_scheduled( Marqira_Heartbeat::CRON_HOOK ), 'cron cleared when only the flag is present' );

// ---------------------------------------------------------------------------
// 3. Revocation detected from the error string alone.
// ---------------------------------------------------------------------------
mq_reset_state();
mq_enroll_test_site();
Marqira_Heartbeat::ensure_scheduled();
$GLOBALS['__mq_next_response'] = 'revoked_error';
Marqira_Heartbeat::send_heartbeat();
mq_ok( ! Marqira_Enrollment::is_enrolled(), 'error:"site_revoked" alone triggers disconnect' );

// ---------------------------------------------------------------------------
// 4. After revocation, the init() self-heal must NOT re-schedule the cron
//    (the site is no longer enrolled).
// ---------------------------------------------------------------------------
mq_reset_state();
mq_enroll_test_site();
Marqira_Heartbeat::ensure_scheduled();
$GLOBALS['__mq_next_response'] = 'revoked_both';
Marqira_Heartbeat::send_heartbeat();
Marqira_Heartbeat::init(); // runs maybe_schedule() on every request
mq_ok( false === wp_next_scheduled( Marqira_Heartbeat::CRON_HOOK ), 'init() does not resurrect the cron after revocation' );

// ---------------------------------------------------------------------------
// 5. A plain 403 that is NOT a revocation signal must NOT disconnect the site.
// ---------------------------------------------------------------------------
mq_reset_state();
mq_enroll_test_site();
Marqira_Heartbeat::ensure_scheduled();
$GLOBALS['__mq_next_response'] = 'forbidden_plain';
Marqira_Heartbeat::send_heartbeat();
mq_ok( Marqira_Enrollment::is_enrolled(), 'plain 403 (non-revocation) keeps credentials intact' );
mq_ok( false !== wp_next_scheduled( Marqira_Heartbeat::CRON_HOOK ), 'plain 403 leaves the heartbeat schedule intact' );

// ---------------------------------------------------------------------------
// 6. A normal 200 heartbeat still succeeds and keeps the site enrolled.
// ---------------------------------------------------------------------------
mq_reset_state();
mq_enroll_test_site();
Marqira_Heartbeat::ensure_scheduled();
$GLOBALS['__mq_next_response'] = 'ok';
$_SERVER['SERVER_ADDR']        = '198.51.100.7';
Marqira_Heartbeat::send_heartbeat();
mq_ok( Marqira_Enrollment::is_enrolled(), 'successful heartbeat keeps the site enrolled' );
mq_ok( false !== get_transient( 'marqira_last_heartbeat_sent' ), 'successful heartbeat records the last-sent transient' );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
echo "test-site-revoked.php: {$GLOBALS['__mq_pass']} passed, {$GLOBALS['__mq_fail']} failed\n";
