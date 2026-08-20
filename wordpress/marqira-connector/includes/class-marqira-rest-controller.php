<?php
/**
 * REST controller for MarQira Connector control-plane push.
 *
 * Registers the `marqira/v1` REST namespace. Its purpose is to let the MarQira
 * control plane deliver an "update this site now" command the instant an
 * operator clicks the button — instead of waiting for the next WP-Cron
 * heartbeat, which on a low-traffic site may not fire for many minutes (or at
 * all until someone visits the site).
 *
 * Flow:
 *   1. POST /wp-json/marqira/v1/execute-update  (HMAC-signed by the API)
 *        → verify signature, dedup by command_id, persist a one-shot job,
 *          answer 202 "queued" immediately, then kick off background execution.
 *   2. Background execution starts within ~1s via a non-blocking loopback to
 *        /wp-json/marqira/v1/run-job (protected by a single-use token) AND a
 *        scheduled single cron event as a belt-and-braces fallback. Whichever
 *        fires first runs the upgrade; command_id dedup guarantees it runs once.
 *
 * This never relies solely on the visitor-driven WP-Cron: the loopback request
 * spawns a fresh PHP worker regardless of site traffic. If loopback is blocked
 * by the host, the scheduled cron event (and, ultimately, the heartbeat) still
 * deliver the command — so the update is never silently lost.
 *
 * @package Marqira_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Marqira_Rest_Controller
 */
class Marqira_Rest_Controller {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'marqira/v1';

	/**
	 * Stable logical path the execute-update signature covers (must match the
	 * API signer exactly — permalink/subdirectory independent).
	 */
	const EXECUTE_SIGN_PATH = '/marqira/v1/execute-update';

	/**
	 * Transient holding the single queued background job.
	 */
	const JOB_TRANSIENT = 'marqira_pending_job';

	/**
	 * Cron hook used as the fallback immediate-execution trigger.
	 */
	const CRON_HOOK = 'marqira_execute_remote_update';

	/**
	 * Register REST routes and the background execution hook.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled_job' ), 10, 3 );
	}

	/**
	 * Register the connector control-plane routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/execute-update',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_execute_update' ),
				'permission_callback' => array( __CLASS__, 'authorize_signed' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/run-job',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_run_job' ),
				'permission_callback' => array( __CLASS__, 'authorize_run_token' ),
			)
		);
	}

	/**
	 * Permission callback: verify the inbound HMAC signature.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function authorize_signed( $request ) {
		if ( ! class_exists( 'Marqira_Hmac_Server' ) ) {
			return new WP_Error( 'marqira_unavailable', 'Verifier unavailable.', array( 'status' => 500 ) );
		}
		return Marqira_Hmac_Server::verify( $request, self::EXECUTE_SIGN_PATH );
	}

	/**
	 * Accept a signed update command and start it immediately in the background.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_execute_update( $request ) {
		$params     = $request->get_json_params();
		$params     = is_array( $params ) ? $params : array();
		$type       = isset( $params['type'] ) ? (string) $params['type'] : '';
		$target     = isset( $params['target_version'] ) ? (string) $params['target_version'] : '';
		$command_id = isset( $params['command_id'] ) ? (string) $params['command_id'] : '';

		$allowed = array( 'update_plugin', 'update_core', 'update_all_plugins', 'update_all_themes' );
		if ( ! in_array( $type, $allowed, true ) ) {
			return new WP_REST_Response(
				array(
					'accepted' => false,
					'error'    => 'unknown_command_type',
				),
				422
			);
		}

		// Idempotency: if we've already handled this command id, acknowledge
		// success without doing anything again.
		if ( '' !== $command_id && class_exists( 'Marqira_Remote_Update' )
			&& Marqira_Remote_Update::has_processed_command( $command_id ) ) {
			return new WP_REST_Response(
				array(
					'accepted'   => true,
					'duplicate'  => true,
					'state'      => 'accepted',
					'command_id' => $command_id,
				),
				200
			);
		}

		// Persist the one-shot job with a single-use token for the loopback.
		$token = bin2hex( random_bytes( 24 ) );
		$job   = array(
			'type'           => $type,
			'target_version' => $target,
			'command_id'     => $command_id,
			'token'          => $token,
			'queued_at'      => time(),
		);
		set_transient( self::JOB_TRANSIENT, $job, 10 * MINUTE_IN_SECONDS );

		// Tell the control plane, via the ack channel, that the command was
		// accepted and is queued — the dashboard flips to "Queued" at once.
		if ( class_exists( 'Marqira_Remote_Update' ) ) {
			Marqira_Remote_Update::ack_queued( $command_id );
		}

		// Kick off background execution immediately (does not block this
		// response): a non-blocking loopback worker + a scheduled cron fallback.
		self::spawn_background_worker( $token, $type, $target, $command_id );

		return new WP_REST_Response(
			array(
				'accepted'   => true,
				'state'      => 'queued',
				'command_id' => $command_id,
			),
			202
		);
	}

	/**
	 * Permission callback for the internal run-job worker: validate the single
	 * use token against the stored job (constant-time), reject otherwise.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function authorize_run_token( $request ) {
		$params = $request->get_json_params();
		$token  = is_array( $params ) && isset( $params['token'] ) ? (string) $params['token'] : '';
		if ( '' === $token ) {
			return false;
		}

		$job = get_transient( self::JOB_TRANSIENT );
		if ( ! is_array( $job ) || empty( $job['token'] ) ) {
			return false;
		}

		return hash_equals( (string) $job['token'], $token );
	}

	/**
	 * Internal worker: run the queued job. Reached only via a validated
	 * single-use token from the loopback request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_run_job( $request ) {
		$job = get_transient( self::JOB_TRANSIENT );

		// Consume the job so the token cannot be reused.
		delete_transient( self::JOB_TRANSIENT );

		if ( ! is_array( $job ) || empty( $job['type'] ) ) {
			return new WP_REST_Response( array( 'ran' => false ), 200 );
		}

		// Allow this worker to run the full upgrade without a client timeout.
		self::relax_limits();

		if ( class_exists( 'Marqira_Remote_Update' ) ) {
			Marqira_Remote_Update::execute_command(
				(string) $job['type'],
				isset( $job['target_version'] ) ? (string) $job['target_version'] : '',
				isset( $job['command_id'] ) ? (string) $job['command_id'] : ''
			);
		}

		return new WP_REST_Response( array( 'ran' => true ), 200 );
	}

	/**
	 * Cron fallback: run a job scheduled by execute-update. command_id dedup in
	 * execute_command() guarantees this is a no-op if the loopback already ran it.
	 *
	 * @param string $type       Command verb.
	 * @param string $target     Target version.
	 * @param string $command_id Command id.
	 * @return void
	 */
	public static function run_scheduled_job( $type = '', $target = '', $command_id = '' ) {
		self::relax_limits();
		// Clear the stored job (its token is no longer needed).
		delete_transient( self::JOB_TRANSIENT );

		if ( '' === $type || ! class_exists( 'Marqira_Remote_Update' ) ) {
			return;
		}

		Marqira_Remote_Update::execute_command( (string) $type, (string) $target, (string) $command_id );
	}

	/**
	 * Spawn immediate background execution: a non-blocking loopback worker plus
	 * a scheduled cron single-event fallback.
	 *
	 * @param string $token      Single-use worker token.
	 * @param string $type       Command verb.
	 * @param string $target     Target version.
	 * @param string $command_id Command id.
	 * @return void
	 */
	private static function spawn_background_worker( $token, $type, $target, $command_id ) {
		// Fallback path 1: schedule a single cron event ~now. If loopback is
		// blocked by the host, the next cron tick still runs the job.
		if ( ! wp_next_scheduled( self::CRON_HOOK, array( $type, $target, $command_id ) ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK, array( $type, $target, $command_id ) );
		}

		// Primary path: fire a non-blocking loopback that spawns a fresh worker
		// process at once, independent of site traffic.
		$url  = rest_url( self::NAMESPACE . '/run-job' );
		$body = wp_json_encode( array( 'token' => $token ) );

		wp_remote_post(
			$url,
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'headers'   => array( 'Content-Type' => 'application/json' ),
				'body'      => $body,
			)
		);

		// Secondary nudge: ask WP-Cron to spawn now too (harmless if it no-ops).
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron( time() );
		}
	}

	/**
	 * Give the background worker room to complete a real upgrade.
	 *
	 * @return void
	 */
	private static function relax_limits() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			@ignore_user_abort( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}
