<?php
/**
 * Tests for Marqira_Health_Check — the non-destructive site health probe used
 * by the critical-error protection & automatic recovery system.
 *
 * These exercise the pure classification logic (probe() verdict from HTTP code
 * / body / transport error) and the overall run() verdict, with wp_remote_get
 * and the URL helpers stubbed so no real network I/O happens.
 *
 * @package Marqira_Connector
 */

require __DIR__ . '/bootstrap.php';

// ---------------------------------------------------------------------------
// HTTP + URL stubs. wp_remote_get returns whatever the current test injects
// into $GLOBALS['__mq_http'] (a fixed response used for every probe).
// ---------------------------------------------------------------------------
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $msg;
		public function __construct( $code = '', $message = '' ) {
			$this->msg = $message;
		}
		public function get_error_message() {
			return $this->msg;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '/' ) {
		return 'https://example.test' . $path;
	}
}
if ( ! function_exists( 'wp_login_url' ) ) {
	function wp_login_url() {
		return 'https://example.test/wp-login.php';
	}
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) {
		return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
	}
}
if ( ! function_exists( 'wp_installing' ) ) {
	function wp_installing() {
		return ! empty( $GLOBALS['__mq_installing'] );
	}
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) {
		return isset( $GLOBALS['__mq_http'] ) ? $GLOBALS['__mq_http'] : array( 'code' => 200, 'body' => 'ok' );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $r ) {
		return is_array( $r ) && isset( $r['code'] ) ? $r['code'] : 0;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $r ) {
		return is_array( $r ) && isset( $r['body'] ) ? $r['body'] : '';
	}
}

require_once dirname( __DIR__ ) . '/includes/class-marqira-health-check.php';

/** Invoke a private static method on Marqira_Health_Check. */
function mq_hc_private( $method, array $args = array() ) {
	$ref = new ReflectionMethod( 'Marqira_Health_Check', $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( null, $args );
}

// ---------------------------------------------------------------------------
// probe() classification
// ---------------------------------------------------------------------------

// 500 -> DOWN (WordPress critical-error response code).
$GLOBALS['__mq_http'] = array( 'code' => 500, 'body' => '' );
$r = mq_hc_private( 'probe', array( 'https://example.test/', 10, 'Frontend' ) );
mq_ok( 'down' === $r['status'], 'HTTP 500 is classified as DOWN' );

// 503 -> DOWN (any 5xx).
$GLOBALS['__mq_http'] = array( 'code' => 503, 'body' => '' );
$r = mq_hc_private( 'probe', array( 'https://example.test/', 10, 'Frontend' ) );
mq_ok( 'down' === $r['status'], 'HTTP 503 is classified as DOWN' );

// 200 with the WP critical-error signature in the body -> DOWN.
$GLOBALS['__mq_http'] = array( 'code' => 200, 'body' => '<h1>There has been a critical error on this website.</h1>' );
$r = mq_hc_private( 'probe', array( 'https://example.test/', 10, 'Frontend' ) );
mq_ok( 'down' === $r['status'], 'fatal-error signature in a 200 body is DOWN' );

// 200 with a raw PHP fatal in the body -> DOWN.
$GLOBALS['__mq_http'] = array( 'code' => 200, 'body' => 'Fatal error: Uncaught Error: Call to undefined function foo()' );
$r = mq_hc_private( 'probe', array( 'https://example.test/', 10, 'Frontend' ) );
mq_ok( 'down' === $r['status'], 'raw PHP fatal signature is DOWN' );

// 200 clean -> UP.
$GLOBALS['__mq_http'] = array( 'code' => 200, 'body' => '<html>fine</html>' );
$r = mq_hc_private( 'probe', array( 'https://example.test/', 10, 'Frontend' ) );
mq_ok( 'up' === $r['status'], 'clean HTTP 200 is UP' );

// 403 without a fatal -> UP (PHP+WP still executed).
$GLOBALS['__mq_http'] = array( 'code' => 403, 'body' => 'Forbidden' );
$r = mq_hc_private( 'probe', array( 'https://example.test/', 10, 'REST' ) );
mq_ok( 'up' === $r['status'], 'HTTP 403 (no fatal) is UP — proves PHP executed' );

// Transport error (WP_Error) -> INCONCLUSIVE, never DOWN.
$GLOBALS['__mq_http'] = new WP_Error( 'http_request_failed', 'cURL error 28: timeout' );
$r = mq_hc_private( 'probe', array( 'https://example.test/', 10, 'Frontend' ) );
mq_ok( 'inconclusive' === $r['status'], 'a transport error is INCONCLUSIVE (never DOWN)' );

// Empty URL -> INCONCLUSIVE.
$r = mq_hc_private( 'probe', array( '', 10, 'REST' ) );
mq_ok( 'inconclusive' === $r['status'], 'an empty URL is INCONCLUSIVE' );

// ---------------------------------------------------------------------------
// run() overall verdict
// ---------------------------------------------------------------------------

// All probes clean -> healthy.
$GLOBALS['__mq_installing'] = false;
$GLOBALS['__mq_http']       = array( 'code' => 200, 'body' => 'ok' );
$report = Marqira_Health_Check::run();
mq_ok( true === $report['healthy'], 'run() reports healthy when every probe is UP' );
mq_ok( 'ok' === $report['summary'], 'healthy run summary is "ok"' );

// One hard DOWN -> unhealthy + is_critical true.
$GLOBALS['__mq_http'] = array( 'code' => 500, 'body' => '' );
$report = Marqira_Health_Check::run();
mq_ok( false === $report['healthy'], 'run() reports unhealthy when a probe is DOWN' );
mq_ok( true === Marqira_Health_Check::is_critical(), 'is_critical() is true when a probe is DOWN' );

// Transport errors only -> still healthy (inconclusive never fails verdict).
$GLOBALS['__mq_http'] = new WP_Error( 'http_request_failed', 'network down' );
$report = Marqira_Health_Check::run();
mq_ok( true === $report['healthy'], 'inconclusive-only probes do NOT mark the site critical' );

// wp_installing() true -> bootstrap DOWN -> unhealthy.
$GLOBALS['__mq_installing'] = true;
$GLOBALS['__mq_http']       = array( 'code' => 200, 'body' => 'ok' );
$report = Marqira_Health_Check::run();
mq_ok( false === $report['healthy'], 'run() is unhealthy while WordPress is mid-install/upgrade' );
$GLOBALS['__mq_installing'] = false;

// rest_ping_url resolves to the health-ping route.
mq_ok(
	false !== strpos( Marqira_Health_Check::rest_ping_url(), 'marqira/v1/health-ping' ),
	'rest_ping_url() targets the marqira/v1/health-ping route'
);

echo "\n";
echo 'test-health-check.php: ' . $GLOBALS['__mq_pass'] . " passed, " . $GLOBALS['__mq_fail'] . " failed\n";
