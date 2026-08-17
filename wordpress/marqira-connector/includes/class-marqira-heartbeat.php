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
         * TEMPORARY TEST VALUE: 2 minutes.
         * ---------------------------------------------------------------------
         * This is intentionally short so several recurring heartbeats can be
         * observed quickly in production while verifying the cron fix. Change this
         * single constant back to 10 to restore the intended production cadence —
         * it is the only edit required (the interval registration and scheduling
         * both derive from it).
         *
         * NOTE: the backend online/offline thresholds (20 / 30 minutes) are
         * deliberately NOT changed for this temporary test cadence.
         */
        const HEARTBEAT_INTERVAL_MINUTES = 2;

        /**
         * Maximum scheduling jitter, in seconds.
         *
         * A small random offset spreads load across many customer sites without
         * pushing the observed cadence far from HEARTBEAT_INTERVAL_MINUTES. Kept
         * small (relative to the interval) so the 2-minute test cadence stays easy
         * to observe.
         */
        const HEARTBEAT_JITTER_SECONDS = 15;

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
         * Send a heartbeat to the MarQira API.
         *
         * Hooked to wp-cron.
         */
        public static function send_heartbeat() {
                // Check if enrolled
                if ( ! Marqira_Enrollment::is_enrolled() ) {
                        return;
                }

                $credentials = Marqira_Enrollment::get_credentials();
                if ( empty( $credentials ) ) {
                        return;
                }

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
                        return;
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
                        return;
                }

                $status_code = wp_remote_retrieve_response_code( $response );

                if ( $status_code === 200 ) {
                        // Success — update last sent timestamp
                        set_transient( 'marqira_last_heartbeat_sent', time(), HOUR_IN_SECONDS );

                        Marqira_Logger::log(
                                'heartbeat_sent',
                                'Heartbeat sent successfully.',
                                'info'
                        );

                        // Phase 7: act on any server-issued commands (e.g. a dashboard
                        // "update this site now" request delivered in this response).
                        if ( class_exists( 'Marqira_Remote_Update' ) ) {
                                $decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
                                Marqira_Remote_Update::handle_response( $decoded );
                        }
                } elseif ( 403 === (int) $status_code && self::is_revocation_response( $response ) ) {
                        // The API has revoked this site's connector credentials (the site
                        // was disconnected/removed from the dashboard). Self-disconnect:
                        // clear the stored credentials and stop the recurring heartbeat so
                        // the site goes quiet immediately instead of repeatedly hammering
                        // the API with rejected beats. Reconnecting requires a fresh
                        // enrollment code — exactly the intended behavior after revocation.
                        self::handle_revocation();
                } else {
                        $body_text = (string) wp_remote_retrieve_body( $response );
                        if ( strlen( $body_text ) > 200 ) {
                                $body_text = substr( $body_text, 0, 200 ) . '…';
                        }
                        Marqira_Logger::log(
                                'heartbeat_failed',
                                sprintf( 'Heartbeat failed with status %d: %s', $status_code, $body_text ),
                                'error'
                        );
                }
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

                return $data;
        }

        /**
         * Collect the count of pending WordPress updates by type.
         *
         * Returns an array with:
         *   - core    (bool) whether a newer WordPress core version is available
         *   - plugins (int)  number of installed plugins with an update available
         *   - themes  (int)  number of installed themes with an update available
         *
         * Runs inside wp-admin update helpers, which are loaded on demand. Any
         * failure degrades gracefully to "nothing pending" rather than breaking
         * the heartbeat.
         *
         * @return array{core:bool,plugins:int,themes:int}
         */
        private static function collect_update_inventory() {
                $inventory = array(
                        'core'    => false,
                        'plugins' => 0,
                        'themes'  => 0,
                );

                if ( ! function_exists( 'get_core_updates' )
                        || ! function_exists( 'get_plugin_updates' )
                        || ! function_exists( 'get_theme_updates' ) ) {
                        require_once ABSPATH . 'wp-admin/includes/update.php';
                }

                try {
                        // WordPress core.
                        $core_updates = get_core_updates();
                        if ( is_array( $core_updates ) ) {
                                foreach ( $core_updates as $update ) {
                                        if ( isset( $update->response ) && 'upgrade' === $update->response ) {
                                                $inventory['core'] = true;
                                                break;
                                        }
                                }
                        }

                        // Plugins.
                        $plugin_updates = get_plugin_updates();
                        if ( is_array( $plugin_updates ) ) {
                                $inventory['plugins'] = count( $plugin_updates );
                        }

                        // Themes.
                        $theme_updates = get_theme_updates();
                        if ( is_array( $theme_updates ) ) {
                                $inventory['themes'] = count( $theme_updates );
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
