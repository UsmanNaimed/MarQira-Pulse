<?php
/**
 * Marqira site health checker.
 *
 * Runs a set of independent, non-destructive checks that determine whether the
 * site is in a healthy state or a critical-error state. Used by the recovery
 * system (class-marqira-recovery.php) to establish a baseline *before* a risky
 * managed action and to verify the outcome *after* it.
 *
 * The checks never throw and never modify the site. A network/transport error
 * on a loopback probe is treated as INCONCLUSIVE (not DOWN) so we never roll a
 * site back on the strength of a flaky probe alone.
 *
 * @package Marqira_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Marqira_Health_Check {

	/**
	 * Signatures that indicate WordPress' own fatal-error screen or a raw PHP
	 * fatal in the HTML body of a loopback probe.
	 */
	const FATAL_SIGNATURES = array(
		'There has been a critical error on this website',
		'There has been a critical error on your website',
		'Fatal error:',
		'Parse error:',
		'Error establishing a database connection',
	);

	/**
	 * Run the full health check.
	 *
	 * @param array $args {
	 *     Optional overrides.
	 *     @type int  $timeout  Per-probe timeout in seconds. Default 10.
	 *     @type bool $frontend Whether to probe the public frontend. Default true.
	 *     @type bool $admin    Whether to probe wp-login (admin bootstrap). Default true.
	 *     @type bool $rest     Whether to probe the Marqira REST ping. Default true.
	 * }
	 * @return array {
	 *     @type bool   $healthy  True only when no check reported DOWN.
	 *     @type array  $checks   Per-check results keyed by name.
	 *     @type string $summary  Human summary of the first failing check, or 'ok'.
	 * }
	 */
	public static function run( $args = array() ) {
		$args = array_merge(
			array(
				'timeout'  => 10,
				'frontend' => true,
				'admin'    => true,
				'rest'     => true,
			),
			is_array( $args ) ? $args : array()
		);

		$checks = array();

		// 1. WordPress bootstrap. If this code is executing, PHP parsed and WP
		// loaded far enough to run plugins. We additionally reject the state
		// where WP is mid-install/upgrade.
		$checks['wp_bootstrap'] = self::result(
			( function_exists( 'wp_installing' ) && wp_installing() ) ? 'down' : 'up',
			'WordPress core bootstrap'
		);

		// 2. Marqira REST endpoint reachable (proves the REST stack answers).
		if ( $args['rest'] ) {
			$checks['rest_endpoint'] = self::probe(
				self::rest_ping_url(),
				$args['timeout'],
				'Marqira REST endpoint'
			);
		}

		// 3. Public frontend renders without a fatal.
		if ( $args['frontend'] ) {
			$checks['frontend'] = self::probe(
				home_url( '/' ),
				$args['timeout'],
				'Public frontend'
			);
		}

		// 4. Admin bootstrap (wp-login.php loads WP + most of admin without auth).
		if ( $args['admin'] ) {
			$checks['admin'] = self::probe(
				wp_login_url(),
				$args['timeout'],
				'Admin bootstrap'
			);
		}

		// The site is "healthy" unless at least one check is a hard DOWN.
		// INCONCLUSIVE (transport error) never fails the verdict on its own.
		$healthy  = true;
		$summary  = 'ok';
		foreach ( $checks as $check ) {
			if ( 'down' === $check['status'] ) {
				$healthy = false;
				$summary = $check['label'] . ': ' . $check['detail'];
				break;
			}
		}

		return array(
			'healthy' => $healthy,
			'checks'  => $checks,
			'summary' => $summary,
		);
	}

	/**
	 * Quick boolean: is the site currently in a critical-error state?
	 *
	 * @param array $args See run().
	 * @return bool
	 */
	public static function is_critical( $args = array() ) {
		$report = self::run( $args );
		return empty( $report['healthy'] );
	}

	/**
	 * The URL of the unauthenticated Marqira REST ping route.
	 *
	 * @return string
	 */
	public static function rest_ping_url() {
		if ( function_exists( 'rest_url' ) ) {
			return rest_url( 'marqira/v1/health-ping' );
		}
		return home_url( '/wp-json/marqira/v1/health-ping' );
	}

	/**
	 * Perform a single loopback HTTP probe and classify the result.
	 *
	 * @param string $url     URL to fetch.
	 * @param int    $timeout Timeout in seconds.
	 * @param string $label   Human label for the check.
	 * @return array Check result.
	 */
	private static function probe( $url, $timeout, $label ) {
		if ( empty( $url ) ) {
			return self::result( 'inconclusive', $label, 'No URL available to probe.' );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => (int) $timeout,
				'redirection' => 3,
				'sslverify'   => false, // Loopback to self; cert host may not match.
				'headers'     => array( 'X-Marqira-Health' => '1' ),
				// Identify loopback so we never get counted as a real visitor.
				'user-agent'  => 'MarqiraConnector/HealthCheck',
			)
		);

		if ( is_wp_error( $response ) ) {
			// Transport failure — cannot conclude the site is down from this.
			return self::result( 'inconclusive', $label, $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		// A 5xx (esp. 500) is WordPress' critical-error response code.
		if ( $code >= 500 ) {
			return self::result( 'down', $label, sprintf( 'HTTP %d returned.', $code ) );
		}

		// Even on a 200, WP's recovery-mode error screen or a raw PHP fatal can
		// leak into the body. Treat those as a hard failure.
		foreach ( self::FATAL_SIGNATURES as $sig ) {
			if ( false !== stripos( $body, $sig ) ) {
				return self::result( 'down', $label, 'Fatal-error signature detected in response.' );
			}
		}

		// 2xx/3xx/4xx without a fatal signature. 4xx (e.g. 401/403 on REST) still
		// proves PHP+WP executed, so we treat < 500 as up.
		return self::result( 'up', $label, sprintf( 'HTTP %d.', $code ) );
	}

	/**
	 * Build a normalized check-result array.
	 *
	 * @param string $status up|down|inconclusive.
	 * @param string $label  Human label.
	 * @param string $detail Optional detail.
	 * @return array
	 */
	private static function result( $status, $label, $detail = '' ) {
		return array(
			'status' => $status,
			'label'  => $label,
			'detail' => $detail,
		);
	}
}
