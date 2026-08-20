<?php
/**
 * Heartbeat sender for MarQira Connector.
 *
 * Sends authenticated heartbeats to the MarQira API via wp-cron.
 *
 * @package Marqira_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

/**
 * Class Marqira_Heartbeat
 */
class Marqira_Heartbeat {

        /**
         * Cron hook name.
         */
        const CRON_HOOK = 'marqira_send_heartbeat';

        /**
         * Custom cron interval name (registered via the cron_schedules filter).
         */
        const CRON_INTERVAL = 'marqira_heartbeat_interval';

        /**
         * Recurring heartbeat cadence, in minutes.
         *
         * ---------------------------------------------------------------------
         * ENFORCED PRODUCTION CADENCE: 3 minutes.
         * ---------------------------------------------------------------------
         * This is the single source of truth for how often the site reports in.
         * It is enforced by TWO independent mechanisms so a beat lands roughly
         * every 3 minutes "by any means", regardless of WP-Cron health:
         *
         *   1. The recurring WP-Cron event (primary path).
         *   2. A traffic-triggered watchdog (see maybe_run_watchdog()) that fires
         *      a beat on any front-end or admin request once this interval has
         *      elapsed since the last attempt — covering sites where WP-Cron is
         *      stalled, disabled (DISABLE_WP_CRON) or simply starved of traffic.
         *
         * The interval registration, scheduling and watchdog cadence all derive
         * from this constant, so changing it here changes every mechanism at once.
         *
         * NOTE: the backend online/offline thresholds (20 / 30 minutes) are
         * deliberately left unchanged; a 3-minute cadence stays well under them so
         * a single missed beat never flips a healthy site offline.
         */
        const HEARTBEAT_INTERVAL_MINUTES = 3;

        /**
         * Maximum scheduling jitter, in seconds.
         *
         * A small random offset spreads load across many customer sites without
         * pushing the observed cadence far from HEARTBEAT_INTERVAL_MINUTES. Kept
         * small relative to the interval so the enforced 3-minute cadence is
         * preserved. Only the WP-Cron scheduling path uses jitter; the watchdog
         * fires as soon as the interval has elapsed.
         */
        const HEARTBEAT_JITTER_SECONDS = 15;

        /**
         * Option storing the UNIX timestamp of the last heartbeat ATTEMPT.
         *
         * Persisted as a real option (not a transient) so it survives object-cache
         * flushes and is available on every request for the watchdog's cadence
         * check. Updated at the start of every send_heartbeat() call — from the
         * cron event, the enrollment beat, the manual button AND the watchdog — so
         * whichever mechanism fires a beat resets the 3-minute countdown for all of
         * them. Gating on the ATTEMPT (not just success) means a failing endpoint is
         * retried on cadence rather than on every single request.
         */
        const LAST_ATTEMPT_OPTION = 'marqira_heartbeat_last_attempt';

        /**
         * Option storing the UNIX timestamp of the last heartbeat actually
         * DISPATCHED to the network (as opposed to LAST_ATTEMPT_OPTION, which is
         * the cadence countdown claimed up-front by the watchdog).
         *
         * This is the de-duplication marker. When an idle site finally receives a
         * request, TWO mechanisms can wake in the same cycle and each try to send a
         * beat: the traffic watchdog (deferred to `shutdown`) and the recurring
         * WP-Cron event (run in the wp-cron.php loopback that WordPress spawns from
         * that same request). Without a dispatch-level guard both go out roughly a
         * second apart — the duplicate "pairs" seen in production logs. Every
         * automatic send is now gated on this timestamp so only the first beat in a
         * dedup window is dispatched; the second is skipped. Manual/enrollment beats
         * pass $force = true and are never skipped.
         */
        const LAST_SENT_OPTION = 'marqira_heartbeat_last_sent';

        /**
         * Short-lived lock preventing concurrent requests from firing duplicate
         * watchdog heartbeats (a "stampede" under traffic). TTL is intentionally
         * short so a crashed request can never wedge the watchdog for long.
         */
        const WATCHDOG_LOCK_KEY = 'marqira_heartbeat_watchdog_lock';

        /**
         * Watchdog lock lifetime, in seconds. Comfortably longer than a heartbeat
         * request (timeout 30s) but far shorter than the 3-minute interval.
         */
        const WATCHDOG_LOCK_TTL = 60;

        /**
         * Initialize heartbeat system.
         *
         * Runs on every request (hooked to `init`). Besides wiring the cron
         * callback to the scheduled hook, it self-heals the schedule: if the site
         * is enrolled but the recurring event is missing, it is recreated
         * automatically. This is what lets already-installed sites recover after a
         * plugin *upgrade* — which does NOT fire register_activation_hook() — with
         * no reconnection and no manual WP-Cron configuration.
         */
        public static function init() {
                add_action( self::CRON_HOOK, array( __CLASS__, 'send_heartbeat' ) );

                // Self-heal the recurring schedule on normal plugin load.
                self::maybe_schedule();

                // Hard-enforce the cadence: a traffic-triggered watchdog fires a
                // heartbeat on any request once the interval has elapsed, even when
                // WP-Cron is stalled, disabled or starved of traffic. init() itself
                // runs on the `init` hook (priority 10), so we register the watchdog
                // late (priority 99) to evaluate it after the rest of init settles.
                add_action( 'init', array( __CLASS__, 'maybe_run_watchdog' ), 99 );
        }

        /**
         * Traffic-triggered watchdog: fire a heartbeat when one is overdue.
         *
         * This is the "by any means" enforcement layer. On every front-end and
         * admin request it checks whether HEARTBEAT_INTERVAL_MINUTES has elapsed
         * since the last attempt and, if so, sends a beat — guaranteeing the site
         * reports in roughly every 3 minutes for any site that receives traffic,
         * independent of WP-Cron reliability.
         *
         * Safeguards:
         *   - Skips WP-Cron requests (the scheduled event already handles those).
         *   - Skips unenrolled sites (nothing to report).
         *   - Gated by a persistent last-attempt timestamp so it fires on cadence,
         *     not on every request.
         *   - Guarded by a short-lived lock so concurrent requests under traffic
         *     never fire duplicate beats.
         *   - The actual network send is deferred to `shutdown` and flushed after
         *     the response (fastcgi_finish_request) so page speed is unaffected.
         *
         * @return void
         */
        public static function maybe_run_watchdog() {
                // The scheduled cron event fires the beat during cron runs; don't
                // double up (and never let a beat block cron processing).
                if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
                        return;
                }

                if ( ! Marqira_Enrollment::is_enrolled() ) {
                        return;
                }

                if ( ! self::is_heartbeat_due() ) {
                        return;
                }

                // Prevent a stampede: only one concurrent request may own the beat.
                if ( ! self::acquire_watchdog_lock() ) {
                        return;
                }

                // Claim this interval immediately so any racing request that slips
                // past the lock still sees the beat as "not due".
                update_option( self::LAST_ATTEMPT_OPTION, time(), true );

                // Defer the network call until the response has been delivered.
                add_action( 'shutdown', array( __CLASS__, 'run_watchdog_heartbeat' ), 0 );
        }

        /**
         * Whether a heartbeat is currently overdue per the enforced cadence.
         *
         * Uses the persistent last-ATTEMPT timestamp so a failing endpoint is
         * retried on cadence (every interval) rather than on every request.
         *
         * @return bool
         */
        private static function is_heartbeat_due() {
                $last = (int) get_option( self::LAST_ATTEMPT_OPTION, 0 );

                // Never attempted (or option missing) — a beat is due now.
                if ( $last <= 0 ) {
                        return true;
                }

                $interval = self::HEARTBEAT_INTERVAL_MINUTES * MINUTE_IN_SECONDS;

                return ( time() - $last ) >= $interval;
        }

        /**
         * Acquire the short-lived watchdog lock.
         *
         * Uses a transient as a best-effort mutex. Combined with immediately
         * stamping LAST_ATTEMPT_OPTION in maybe_run_watchdog(), this reduces the
         * duplicate-beat race to a negligible window even on busy sites.
         *
         * @return bool True if the lock was acquired, false if already held.
         */
        private static function acquire_watchdog_lock() {
                if ( false !== get_transient( self::WATCHDOG_LOCK_KEY ) ) {
                        return false;
                }

                set_transient( self::WATCHDOG_LOCK_KEY, time(), self::WATCHDOG_LOCK_TTL );
                return true;
        }

        /**
         * Release the watchdog lock.
         *
         * @return void
         */
        private static function release_watchdog_lock() {
                delete_transient( self::WATCHDOG_LOCK_KEY );
        }

        /**
         * Send the deferred watchdog heartbeat after the response is delivered.
         *
         * Hooked to `shutdown`. Flushes the response to the browser first (when the
         * SAPI supports it) so the outbound heartbeat request never adds latency to
         * the page the visitor or admin is loading.
         *
         * Calls send_heartbeat() as an automatic beat ($force = false), so if the
         * recurring WP-Cron loopback already dispatched a beat in this same wake
         * cycle the dispatch-level dedup guard skips this one (and vice versa).
         *
         * @return void
         */
        public static function run_watchdog_heartbeat() {
                // Deliver the page first; the beat happens in the background.
                if ( function_exists( 'fastcgi_finish_request' ) ) {
                        @fastcgi_finish_request(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                }

                self::send_heartbeat();
                self::release_watchdog_lock();
        }

        /**
         * Register the heartbeat cron event (called on activation).
         *
         * Delegates to maybe_schedule() so activation only schedules when the site
         * is already enrolled. A freshly activated but unenrolled site has nothing
         * to report; enrollment (and the init self-heal) schedule the event at the
         * right time.
         *
         * @return void
         */
        public static function register_cron() {
                self::maybe_schedule();
        }

        /**
         * Schedule the recurring heartbeat event only when the site is enrolled.
         *
         * Safe to call on every request: it is idempotent and never creates a
         * duplicate event.
         *
         * @return void
         */
        public static function maybe_schedule() {
                if ( ! Marqira_Enrollment::is_enrolled() ) {
                        return;
                }

                self::ensure_scheduled();
        }

        /**
         * Ensure exactly one recurring heartbeat event is scheduled.
         *
         * Uses wp_next_scheduled() as a guard so repeated calls across plugin
         * loads, activations, upgrades and repeated enrollment can never accumulate
         * duplicate cron entries.
         *
         * @return bool True if an event is scheduled (already or newly), false on failure.
         */
        public static function ensure_scheduled() {
                // Already scheduled — never create a duplicate.
                if ( wp_next_scheduled( self::CRON_HOOK ) ) {
                        return true;
                }

                // Schedule one interval out + a small random jitter to spread load
                // across many customer sites. The cadence (see HEARTBEAT_INTERVAL_MINUTES)
                // stays under the backend's 20-minute "online" / 30-minute "offline"
                // thresholds, so a single missed beat never flips a healthy site offline.
                $jitter    = wp_rand( 0, self::HEARTBEAT_JITTER_SECONDS );
                $next_time = time() + ( self::HEARTBEAT_INTERVAL_MINUTES * MINUTE_IN_SECONDS ) + $jitter;

                $scheduled = wp_schedule_event( $next_time, self::CRON_INTERVAL, self::CRON_HOOK );

                if ( false === $scheduled ) {
                        Marqira_Logger::log(
                                'heartbeat_schedule_failed',
                                'Could not schedule the recurring heartbeat cron event.',
                                'error'
                        );
                        return false;
                }

                return true;
        }

        /**
         * Unregister the heartbeat cron event (called on deactivation).
         */
        public static function unregister_cron() {
                $timestamp = wp_next_scheduled( self::CRON_HOOK );
                if ( $timestamp ) {
                        wp_unschedule_event( $timestamp, self::CRON_HOOK );
                }
                wp_clear_scheduled_hook( self::CRON_HOOK );
        }

        /**
         * Register the custom cron interval.
         *
         * The interval length is derived from HEARTBEAT_INTERVAL_MINUTES so there is
         * a single source of truth for the cadence.
         *
         * @param array $schedules Existing schedules.
         * @return array
         */
        public static function add_cron_interval( $schedules ) {
                if ( ! is_array( $schedules ) ) {
                        $schedules = array();
                }

                if ( ! isset( $schedules[ self::CRON_INTERVAL ] ) ) {
                        $schedules[ self::CRON_INTERVAL ] = array(
                                'interval' => self::HEARTBEAT_INTERVAL_MINUTES * MINUTE_IN_SECONDS,
                                'display'  => sprintf(
                                        /* translators: %d: number of minutes between heartbeats. */
                                        __( 'Every %d Minutes (MarQira Heartbeat)', 'marqira-connector' ),
                                        self::HEARTBEAT_INTERVAL_MINUTES
                                ),
                        );
                }
                return $schedules;
        }

        /**
         * Length of the de-duplication window, in seconds.
         *
         * Set to half the heartbeat interval so it is wide enough to absorb the
         * near-simultaneous duplicate beats (cron loopback + watchdog fire within a
         * second of each other) yet always narrower than a full cadence gap, so a
         * legitimately-scheduled on-cadence beat is never suppressed.
         *
         * @return int
         */
        private static function dedup_window_seconds() {
                return (int) floor( self::HEARTBEAT_INTERVAL_MINUTES * MINUTE_IN_SECONDS / 2 );
        }

        /**
         * Send a heartbeat to the MarQira API.
         *
         * Fired from four places: the recurring WP-Cron event, the enrollment beat,
         * the manual "Send Heartbeat Now" admin button and the traffic watchdog.
         * Callers that don't need the outcome (e.g. the cron hook) can ignore the
         * return value.
         *
         * De-duplication: automatic beats (cron event + watchdog) are gated on
         * LAST_SENT_OPTION. If a beat was dispatched to the network within the dedup
         * window (half the interval — see dedup_window_seconds()), a second automatic
         * beat in the same window is skipped instead of sent. This collapses the
         * duplicate "pairs" seen in production (where an idle site's cron loopback and
         * traffic watchdog both fired within ~1 second) down to a single beat, without
         * ever blocking an on-cadence beat. Manual button and enrollment beats pass
         * $force = true and are never skipped.
         *
         * @param bool $force When true, bypass the dedup guard (user-initiated beats:
         *                     enrollment and the manual "Send Heartbeat Now" button).
         *                     Defaults to false for the automatic cron/watchdog paths.
         * @return array{success:bool,message:string,status_code:int} Result of the
         *         attempt. status_code is 0 when the request never reached the API
         *         (not enrolled, missing credentials/headers, a transport error, or the
         *         beat was skipped by the dedup guard).
         */
        public static function send_heartbeat( $force = false ) {
                // Check if enrolled
                if ( ! Marqira_Enrollment::is_enrolled() ) {
                        return self::result( false, __( 'Site is not connected to MarQira.', 'marqira-connector' ), 0 );
                }

                $credentials = Marqira_Enrollment::get_credentials();
                if ( empty( $credentials ) ) {
                        return self::result( false, __( 'No MarQira credentials are stored for this site.', 'marqira-connector' ), 0 );
                }

                // Dispatch-level de-duplication. When an idle site finally receives a
                // request, both the WP-Cron loopback event and the traffic watchdog can
                // wake in the same cycle and each try to dispatch a beat. Skip the second
                // one if an automatic beat already went out within the dedup window.
                if ( ! $force ) {
                        $last_sent = (int) get_option( self::LAST_SENT_OPTION, 0 );
                        if ( $last_sent > 0 && ( time() - $last_sent ) < self::dedup_window_seconds() ) {
                                return self::result(
                                        true,
                                        __( 'Heartbeat skipped: a recent beat already reported in.', 'marqira-connector' ),
                                        0
                                );
                        }
                }

                // Stamp the attempt immediately so every enforcement mechanism (cron,
                // enrollment, manual button, watchdog) shares one 3-minute countdown.
                update_option( self::LAST_ATTEMPT_OPTION, time(), true );

                // Stamp the dispatch marker BEFORE the network call so a concurrent
                // request in the same wake cycle sees it and dedups against it. This is
                // the timestamp the guard above reads.
                update_option( self::LAST_SENT_OPTION, time(), true );

                // Collect site metadata
                $heartbeat_data = self::collect_metadata();

                // API endpoint
                $api_url = Marqira_Enrollment::get_api_url();
                $path    = '/api/v1/heartbeat';
                $url     = rtrim( $api_url, '/' ) . $path;

                // Build request
                $body    = wp_json_encode( $heartbeat_data );
                $headers = Marqira_Hmac_Client::generate_headers( 'POST', $path, array(), $body, $credentials );

                if ( empty( $headers ) ) {
                        Marqira_Logger::log(
                                'heartbeat_failed',
                                'Failed to generate HMAC headers for heartbeat.',
                                'error'
                        );
                        return self::result( false, __( 'Failed to generate the secure request signature.', 'marqira-connector' ), 0 );
                }

                // Send request
                $response = wp_remote_post(
                        $url,
                        array(
                                'timeout' => 30,
                                'headers' => $headers,
                                'body'    => $body,
                        )
                );

                if ( is_wp_error( $response ) ) {
                        Marqira_Logger::log(
                                'heartbeat_failed',
                                sprintf( 'Heartbeat request failed: %s', $response->get_error_message() ),
                                'error'
                        );
                        return self::result(
                                false,
                                sprintf(
                                        /* translators: %s: transport error message */
                                        __( 'Could not reach the MarQira API: %s', 'marqira-connector' ),
                                        $response->get_error_message()
                                ),
                                0
                        );
                }

                $status_code = (int) wp_remote_retrieve_response_code( $response );

                if ( 200 === $status_code ) {
                        // Success — update last sent timestamp
                        set_transient( 'marqira_last_heartbeat_sent', time(), HOUR_IN_SECONDS );

                        Marqira_Logger::log(
                                'heartbeat_sent',
                                'Heartbeat sent successfully.',
                                'info'
                        );

                        // Phase 8: clear yesterday's visitor metrics after successful send.
                        if ( class_exists( 'Marqira_Visitor_Tracker' ) ) {
                                Marqira_Visitor_Tracker::clear_yesterday_metrics();
                        }

                        // Phase 7: act on any server-issued commands (e.g. a dashboard
                        // "update this site now" request delivered in this response).
                        if ( class_exists( 'Marqira_Remote_Update' ) ) {
                                $decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
                                Marqira_Remote_Update::handle_response( $decoded );
                        }

                        return self::result( true, __( 'Heartbeat sent successfully.', 'marqira-connector' ), 200 );
                } elseif ( 403 === $status_code && self::is_revocation_response( $response ) ) {
                        // The API has revoked this site's connector credentials (the site
                        // was disconnected/removed from the dashboard). Self-disconnect:
                        // clear the stored credentials and stop the recurring heartbeat so
                        // the site goes quiet immediately instead of repeatedly hammering
                        // the API with rejected beats. Reconnecting requires a fresh
                        // enrollment code — exactly the intended behavior after revocation.
                        self::handle_revocation();

                        return self::result(
                                false,
                                __( 'This site has been revoked in the MarQira dashboard. It has been disconnected; reconnect with a new enrollment token.', 'marqira-connector' ),
                                403
                        );
                }

                $body_text = (string) wp_remote_retrieve_body( $response );
                if ( strlen( $body_text ) > 200 ) {
                        $body_text = substr( $body_text, 0, 200 ) . '…';
                }
                Marqira_Logger::log(
                        'heartbeat_failed',
                        sprintf( 'Heartbeat failed with status %d: %s', $status_code, $body_text ),
                        'error'
                );

                return self::result(
                        false,
                        sprintf(
                                /* translators: %d: HTTP status code */
                                __( 'Heartbeat rejected by the MarQira API (HTTP %d). See Recent Activity for details.', 'marqira-connector' ),
                                $status_code
                        ),
                        $status_code
                );
        }

        /**
         * Send an explicit connector lifecycle status signal to the API.
         *
         * Called synchronously from the plugin's activation hook ('online') and
         * deactivation hook ('offline'). On deactivation this runs while the
         * plugin code is still loaded, so the site is reported offline the moment
         * the connector is switched off — the dashboard no longer has to wait for
         * the passive heartbeat-timeout watchdog to notice the silence.
         *
         * This is a best-effort, short-timeout call: if it cannot reach the API
         * it fails silently (the watchdog remains the backstop).
         *
         * @param string $state  'online' or 'offline'.
         * @param string $reason Short machine reason, e.g. 'connector_deactivated'.
         * @return bool True when the signal reached the API with HTTP 200.
         */
        public static function send_status_signal( $state, $reason = '' ) {
                $state = ( 'online' === $state ) ? 'online' : 'offline';

                if ( ! class_exists( 'Marqira_Enrollment' ) || ! Marqira_Enrollment::is_enrolled() ) {
                        return false;
                }

                $credentials = Marqira_Enrollment::get_credentials();
                if ( empty( $credentials ) ) {
                        return false;
                }

                $api_url = Marqira_Enrollment::get_api_url();
                $path    = '/api/v1/site-status';
                $url     = rtrim( $api_url, '/' ) . $path;

                $payload = array(
                        'state'  => $state,
                        'reason' => $reason ? (string) $reason : ( 'offline' === $state ? 'connector_deactivated' : 'connector_activated' ),
                );

                $body    = wp_json_encode( $payload );
                $headers = Marqira_Hmac_Client::generate_headers( 'POST', $path, array(), $body, $credentials );

                if ( empty( $headers ) ) {
                        return false;
                }

                // Short timeout: deactivation must not hang the admin request.
                $response = wp_remote_post(
                        $url,
                        array(
                                'timeout'  => 8,
                                'blocking' => true,
                                'headers'  => $headers,
                                'body'     => $body,
                        )
                );

                if ( is_wp_error( $response ) ) {
                        if ( class_exists( 'Marqira_Logger' ) ) {
                                Marqira_Logger::log(
                                        'status_signal_failed',
                                        sprintf( 'Failed to send %s status signal: %s', $state, $response->get_error_message() ),
                                        'warning'
                                );
                        }
                        return false;
                }

                return 200 === (int) wp_remote_retrieve_response_code( $response );
        }

        /**
         * Build the standard send_heartbeat() result array.
         *
         * @param bool   $success     Whether the beat reached the API with HTTP 200.
         * @param string $message     Human-readable, translatable outcome message.
         * @param int    $status_code HTTP status code (0 when no response was received).
         * @return array{success:bool,message:string,status_code:int}
         */
        private static function result( $success, $message, $status_code ) {
                return array(
                        'success'     => (bool) $success,
                        'message'     => (string) $message,
                        'status_code' => (int) $status_code,
                );
        }

        /**
         * Determine whether a heartbeat response indicates this site's connector
         * credentials have been revoked by the API.
         *
         * The API signals revocation with HTTP 403 and a JSON body of the form
         * {"error":"site_revoked","site_revoked":true,...}. The decoded body is
         * checked defensively — either flag alone is sufficient — so a minor
         * payload change on the API side never silently breaks self-disconnect.
         *
         * @param array|WP_Error $response The wp_remote_post response.
         * @return bool True when the response is a site-revoked signal.
         */
        private static function is_revocation_response( $response ) {
                $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

                if ( ! is_array( $body ) ) {
                        return false;
                }

                if ( isset( $body['site_revoked'] ) && true === (bool) $body['site_revoked'] ) {
                        return true;
                }

                return isset( $body['error'] ) && 'site_revoked' === $body['error'];
        }

        /**
         * Self-disconnect after the API reports this site was revoked.
         *
         * Stops the recurring heartbeat and clears the stored credentials so
         * is_enrolled() becomes false. This makes dashboard-side revocation fully
         * effective on the WordPress side with no manual action: the connector goes
         * quiet and will not send further beats. Because the init() self-heal only
         * reschedules when the site is still enrolled, clearing credentials also
         * prevents the schedule from being silently recreated on the next request.
         * Reconnecting requires enrolling again with a new code.
         *
         * @return void
         */
        private static function handle_revocation() {
                Marqira_Logger::log(
                        'site_revoked',
                        'The MarQira API reported this site as revoked. Clearing local credentials and stopping heartbeats.',
                        'warning'
                );

                // Stop the recurring schedule first, so no further beats can fire even
                // if credential clearing somehow fails.
                self::unregister_cron();

                if ( class_exists( 'Marqira_Enrollment' ) ) {
                        Marqira_Enrollment::disconnect();
                }
        }

        /**
         * Collect site metadata for heartbeat.
         *
         * @return array
         */
        private static function collect_metadata() {
                $diagnostics = Marqira_Diagnostics::get_all();

                $data = array(
                        'domain'           => parse_url( home_url(), PHP_URL_HOST ),
                        'home_url'         => home_url(),
                        'site_url'         => site_url(),
                        'wp_version'       => $diagnostics['wp_version'],
                        'php_version'      => $diagnostics['php_version'],
                        'plugin_version'   => $diagnostics['plugin_version'],
                        'server_hostname'  => $diagnostics['server_hostname'],
                        'server_software'  => $diagnostics['server_software'],
                        'is_multisite'     => $diagnostics['is_multisite'],
                );

                // Detect and validate the server IP. The immediate heartbeat (fired from
                // an admin request) and the recurring heartbeat (fired from a WP-Cron
                // loopback request) run through this same code path, so they always
                // agree on the normalized value.
                //
                // server_ip and origin_ip_candidate are OPTIONAL in the API contract
                // (nullable|ip). On some hosts (e.g. LiteSpeed) the raw
                // $_SERVER['SERVER_ADDR'] is missing, malformed, or a hostname during
                // WP-Cron — sending that verbatim previously triggered HTTP 422. We only
                // include these fields when we have a syntactically valid IP; otherwise
                // we omit them so the rest of the heartbeat still succeeds.
                $server_ip = self::detect_server_ip();

                if ( false !== $server_ip ) {
                        $data['server_ip'] = $server_ip;

                        // Origin-IP candidate best guess remains the detected server IP
                        // (preserves the existing Phase 4 origin model — the API stores it
                        // with 'medium' confidence and source 'heartbeat_candidate').
                        $data['origin_ip_candidate'] = $server_ip;
                }

                // Add network data if multisite
                if ( is_multisite() ) {
                        $data['network_data'] = array(
                                'sites_count' => get_blog_count(),
                                'network_url' => network_home_url(),
                        );
                }

                // Update inventory (§13): report how many core/plugin/theme updates
                // are pending so the dashboard can enable the right maintenance
                // buttons and flag sites that need attention. Safe to compute on every
                // heartbeat — WordPress caches the update transients.
                $data['updates'] = self::collect_update_inventory();

                // Visitor metrics (Phase 8): send yesterday's complete visitor data
                // if available. The tracker rotates daily and holds yesterday's final
                // counts until the next heartbeat picks them up.
                if ( class_exists( 'Marqira_Visitor_Tracker' ) ) {
                        $visitor_metrics = Marqira_Visitor_Tracker::get_yesterday_metrics();
                        if ( null !== $visitor_metrics ) {
                                $data['visitor_metrics'] = $visitor_metrics;
                        }
                }

                return $data;
        }

        /**
         * Collect the pending-update inventory for this site.
         *
         * Returns counts (for backwards compatibility) PLUS a detailed `items`
         * breakdown so the dashboard's per-site "Updates" tab can show exactly what
         * needs updating — the WordPress core version transition and every installed
         * plugin/theme with its current version and, when an update is pending, the
         * version it will move to:
         *
         *   - core    (bool) whether a newer WordPress core version is available
         *   - plugins (int)  number of installed plugins with an update available
         *   - themes  (int)  number of installed themes with an update available
         *   - items   (array):
         *       - core    array{current:string,new:?string}|null
         *       - plugins list<array{name:string,slug:string,current:string,new:?string}>
         *       - themes  list<array{name:string,stylesheet:string,current:string,new:?string,active:bool}>
         *
         * `new` is null for an item that is already up to date, so the dashboard can
         * render both "needs updating" and "up to date" rows exactly like the design.
         *
         * Runs inside wp-admin update/plugin/theme helpers, loaded on demand. Any
         * failure degrades gracefully to "nothing pending" rather than breaking the
         * heartbeat.
         *
         * @return array{core:bool,plugins:int,themes:int,items:array}
         */
        private static function collect_update_inventory() {
                $inventory = array(
                        'core'    => false,
                        'plugins' => 0,
                        'themes'  => 0,
                        'items'   => array(
                                'core'    => null,
                                'plugins' => array(),
                                'themes'  => array(),
                        ),
                );

                if ( ! function_exists( 'get_core_updates' )
                        || ! function_exists( 'get_plugin_updates' )
                        || ! function_exists( 'get_theme_updates' ) ) {
                        require_once ABSPATH . 'wp-admin/includes/update.php';
                }
                if ( ! function_exists( 'get_plugins' ) ) {
                        require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }

                try {
                        // ---- WordPress core -------------------------------------------------
                        $core_current = get_bloginfo( 'version' );
                        $core_new     = null;
                        $core_updates = get_core_updates();
                        if ( is_array( $core_updates ) ) {
                                foreach ( $core_updates as $update ) {
                                        if ( isset( $update->response ) && 'upgrade' === $update->response ) {
                                                $inventory['core'] = true;
                                                if ( isset( $update->current ) ) {
                                                        $core_new = (string) $update->current;
                                                }
                                                break;
                                        }
                                }
                        }
                        $inventory['items']['core'] = array(
                                'current' => (string) $core_current,
                                'new'     => $core_new,
                        );

                        // ---- Plugins --------------------------------------------------------
                        $plugin_updates = get_plugin_updates();
                        $plugin_updates = is_array( $plugin_updates ) ? $plugin_updates : array();
                        $inventory['plugins'] = count( $plugin_updates );

                        $all_plugins = get_plugins();
                        if ( is_array( $all_plugins ) ) {
                                foreach ( $all_plugins as $file => $data ) {
                                        $new = null;
                                        if ( isset( $plugin_updates[ $file ]->update->new_version ) ) {
                                                $new = (string) $plugin_updates[ $file ]->update->new_version;
                                        }
                                        $inventory['items']['plugins'][] = array(
                                                'name'    => isset( $data['Name'] ) ? (string) $data['Name'] : (string) $file,
                                                'slug'    => (string) $file,
                                                'current' => isset( $data['Version'] ) ? (string) $data['Version'] : '',
                                                'new'     => $new,
                                        );
                                }
                        }

                        // ---- Themes ---------------------------------------------------------
                        $theme_updates = get_theme_updates();
                        $theme_updates = is_array( $theme_updates ) ? $theme_updates : array();
                        $inventory['themes'] = count( $theme_updates );

                        $active_stylesheet = function_exists( 'wp_get_theme' ) ? wp_get_theme()->get_stylesheet() : null;
                        $all_themes = function_exists( 'wp_get_themes' ) ? wp_get_themes() : array();
                        if ( is_array( $all_themes ) ) {
                                foreach ( $all_themes as $stylesheet => $theme ) {
                                        $new = null;
                                        if ( isset( $theme_updates[ $stylesheet ]->update['new_version'] ) ) {
                                                $new = (string) $theme_updates[ $stylesheet ]->update['new_version'];
                                        }
                                        $inventory['items']['themes'][] = array(
                                                'name'       => (string) $theme->get( 'Name' ),
                                                'stylesheet' => (string) $stylesheet,
                                                'current'    => (string) $theme->get( 'Version' ),
                                                'new'        => $new,
                                                'active'     => ( null !== $active_stylesheet && $stylesheet === $active_stylesheet ),
                                        );
                                }
                        }
                } catch ( \Throwable $e ) {
                        // Degrade gracefully — never let inventory collection break a beat.
                        if ( class_exists( 'Marqira_Logger' ) ) {
                                Marqira_Logger::log( 'update_inventory_failed', $e->getMessage(), 'warning' );
                        }
                }

                return $inventory;
        }

        /**
         * Detect the best available server IP for the heartbeat payload.
         *
         * Scans candidate server variables (in priority order) and returns the
         * first that canonicalizes to a syntactically valid IP address. Returns
         * false when none is available so the caller can omit the optional IP
         * fields rather than send a malformed value that the API rejects (422).
         *
         * A concise, secret-free diagnostic is logged when a value was present but
         * could not be validated, so field-level failures stay debuggable without
         * leaking payloads or credentials. Only the source variable NAME is logged,
         * never the raw value.
         *
         * @return string|false Normalized IP address, or false if none valid.
         */
        private static function detect_server_ip() {
                // Priority order: SERVER_ADDR is the standard; LOCAL_ADDR is set by some
                // stacks (e.g. IIS / certain LiteSpeed configs) when SERVER_ADDR is not.
                $candidates = array( 'SERVER_ADDR', 'LOCAL_ADDR' );
                $rejected   = array();

                foreach ( $candidates as $key ) {
                        if ( ! isset( $_SERVER[ $key ] ) ) {
                                continue;
                        }

                        $raw = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );

                        // Treat empty and the "unknown" sentinel as simply unavailable
                        // (not a diagnosable malformed value — avoids log spam every beat).
                        if ( '' === $raw || 0 === strcasecmp( $raw, 'unknown' ) ) {
                                continue;
                        }

                        $ip = Marqira_IP_Utils::sanitize_ip( $raw );
                        if ( false !== $ip ) {
                                return $ip;
                        }

                        $rejected[] = $key;
                }

                if ( ! empty( $rejected ) ) {
                        Marqira_Logger::log(
                                'heartbeat_ip_invalid',
                                sprintf(
                                        'server_ip rejected as invalid; no usable IP from server variable(s): %s. server_ip/origin_ip_candidate omitted from this heartbeat.',
                                        implode( ', ', $rejected )
                                ),
                                'warning'
                        );
                }

                return false;
        }

        /**
         * Get the timestamp of the last successful heartbeat.
         *
         * @return int|false Timestamp or false if never sent.
         */
        public static function get_last_heartbeat_sent() {
                $timestamp = get_transient( 'marqira_last_heartbeat_sent' );
                return ( false !== $timestamp ) ? (int) $timestamp : false;
        }
}
