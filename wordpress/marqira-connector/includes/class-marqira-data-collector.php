<?php
/**
 * Data collector for WordPress user and post snapshots.
 *
 * Periodically captures WordPress user and post data and ships it to the
 * MarQira API for monitoring and analytics. Data is collected in batches
 * and sent via HMAC-authenticated POST requests.
 *
 * @package Marqira_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

/**
 * Class Marqira_Data_Collector
 */
class Marqira_Data_Collector {

        /**
         * Cron hook name for scheduled data collection.
         */
        const CRON_HOOK = 'marqira_collect_data';

        /**
         * Custom cron interval name (registered via the cron_schedules filter).
         */
        const CRON_INTERVAL = 'marqira_data_collection_interval';

        /**
         * Data collection cadence, in hours.
         *
         * Collects user and post snapshots every 6 hours by default.
         * Users can override this via a filter.
         */
        const COLLECTION_INTERVAL_HOURS = 6;

        /**
         * Initialize data collection system.
         *
         * Runs on every request (hooked to `init`). Wires the cron callback
         * and self-heals the schedule if the site is enrolled but the event
         * is missing. This allows already-installed sites to auto-recover
         * after a plugin upgrade without manual intervention.
         */
        public static function init() {
                add_action( self::CRON_HOOK, array( __CLASS__, 'collect_and_ship_all' ) );

                // Self-heal the recurring schedule on normal plugin load.
                self::maybe_schedule();
        }

        /**
         * Register the data collection cron event (called on activation).
         *
         * Delegates to maybe_schedule() so activation only schedules when
         * the site is already enrolled.
         *
         * @return void
         */
        public static function register_cron() {
                self::maybe_schedule();
        }

        /**
         * Schedule the recurring data collection event only when enrolled.
         *
         * Safe to call on every request: it is idempotent and never creates
         * a duplicate event.
         *
         * @return void
         */
        public static function maybe_schedule() {
                if ( ! Marqira_Enrollment::is_enrolled() ) {
                        return;
                }

                if ( wp_next_scheduled( self::CRON_HOOK ) ) {
                        return; // Already scheduled.
                }

                // Make sure our custom interval is actually registered before we try
                // to schedule against it. If the cron_schedules filter did not run for
                // any reason, wp_schedule_event() would silently fail (this is what
                // produced the misleading "every 0 hours" log entries).
                $schedules = wp_get_schedules();
                if ( empty( $schedules[ self::CRON_INTERVAL ]['interval'] ) ) {
                        // Register it on the fly as a fallback.
                        add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_interval' ) );
                        $schedules = wp_get_schedules();
                }

                $interval_seconds = isset( $schedules[ self::CRON_INTERVAL ]['interval'] )
                        ? (int) $schedules[ self::CRON_INTERVAL ]['interval']
                        : 0;

                if ( $interval_seconds <= 0 ) {
                        Marqira_Logger::log(
                                'data_collection_schedule_failed',
                                'Could not schedule data collection: the recurring interval is not registered. Data can still be sent manually via "Collect Data Now".',
                                'warning'
                        );
                        return;
                }

                // Schedule with a small jitter to spread load across sites.
                $jitter    = wp_rand( 0, 300 ); // 0-5 minutes
                $first_run = time() + $jitter;

                $scheduled = wp_schedule_event( $first_run, self::CRON_INTERVAL, self::CRON_HOOK );

                // wp_schedule_event() returns false on failure (WP 5.1+). Verify the
                // event actually exists before claiming success.
                if ( false === $scheduled || ! wp_next_scheduled( self::CRON_HOOK ) ) {
                        Marqira_Logger::log(
                                'data_collection_schedule_failed',
                                'WordPress refused to schedule the recurring data collection event. Data can still be sent manually via "Collect Data Now".',
                                'warning'
                        );
                        return;
                }

                Marqira_Logger::log(
                        'data_collection_scheduled',
                        sprintf(
                                'Scheduled data collection every %d hours (first run in %d seconds).',
                                (int) round( $interval_seconds / HOUR_IN_SECONDS ),
                                $jitter
                        ),
                        'info'
                );
        }

        /**
         * Unregister the data collection cron event (called on deactivation).
         *
         * @return void
         */
        public static function unregister_cron() {
                $timestamp = wp_next_scheduled( self::CRON_HOOK );
                if ( $timestamp ) {
                        wp_unschedule_event( $timestamp, self::CRON_HOOK );
                        Marqira_Logger::log(
                                'data_collection_unscheduled',
                                'Unscheduled data collection cron event.',
                                'info'
                        );
                }
        }

        /**
         * Add custom cron interval for data collection.
         *
         * @param array $schedules Existing schedules.
         * @return array
         */
        public static function add_cron_interval( $schedules ) {
                $interval_seconds = self::COLLECTION_INTERVAL_HOURS * HOUR_IN_SECONDS;

                $schedules[ self::CRON_INTERVAL ] = array(
                        'interval' => $interval_seconds,
                        'display'  => sprintf( 'Every %d hours', self::COLLECTION_INTERVAL_HOURS ),
                );

                return $schedules;
        }

        /**
         * Collect WordPress user snapshots.
         *
         * @param int $limit Maximum number of users to collect (default 1000).
         * @return array Array of user data ready for API submission.
         */
        public static function collect_users( $limit = 1000 ) {
                if ( ! function_exists( 'get_users' ) ) {
                        return array();
                }

                $users = get_users( array(
                        'number' => $limit,
                        'orderby' => 'ID',
                        'order' => 'ASC',
                ) );

                $collected = array();

                foreach ( $users as $user ) {
                        // Basic user data — never send password hashes or sensitive meta.
                        $user_data = array(
                                'wp_user_id' => $user->ID,
                                'user_login' => $user->user_login,
                                'user_email' => $user->user_email, // May be redacted in future for privacy
                                'display_name' => $user->display_name,
                                'user_registered' => $user->user_registered,
                                'roles' => $user->roles,
                        );

                        // Attempt to get last login time if a plugin tracks it
                        // (many login-tracking plugins store in user meta).
                        $last_login = get_user_meta( $user->ID, 'last_login', true );
                        if ( $last_login ) {
                                $user_data['last_login_at'] = gmdate( 'Y-m-d H:i:s', (int) $last_login );
                        }

                        $collected[] = $user_data;
                }

                return $collected;
        }

        /**
         * Collect WordPress post/page snapshots.
         *
         * @param int $limit Maximum number of posts to collect (default 1000).
         * @return array Array of post data ready for API submission.
         */
        public static function collect_posts( $limit = 1000 ) {
                global $wpdb;

                if ( ! $wpdb ) {
                        return array();
                }

                // Query posts and pages (not auto-drafts, revisions, or trash).
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $posts = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT ID, post_author, post_date, post_modified, post_status, post_type, post_title, guid
                                FROM {$wpdb->posts}
                                WHERE post_type IN ('post', 'page')
                                AND post_status NOT IN ('auto-draft', 'inherit', 'trash')
                                ORDER BY ID ASC
                                LIMIT %d",
                                $limit
                        )
                );

                if ( ! $posts ) {
                        return array();
                }

                $collected = array();

                foreach ( $posts as $post ) {
                        $author = get_userdata( $post->post_author );

                        // Resolve a useful URL for the dashboard. Published posts
                        // get their real public permalink (pretty URL when the
                        // site uses permalinks); drafts/scheduled/private posts
                        // have no public URL yet, so fall back to the internal
                        // ?p=ID form which resolves in wp-admin/preview.
                        if ( 'publish' === $post->post_status ) {
                                $permalink = get_permalink( $post->ID );
                        } else {
                                $permalink = add_query_arg( 'p', (int) $post->ID, home_url( '/' ) );
                        }

                        $post_data = array(
                                'wp_post_id' => (int) $post->ID,
                                'post_type' => $post->post_type,
                                'post_status' => $post->post_status,
                                'post_title' => $post->post_title,
                                'post_date' => $post->post_date,
                                'post_modified' => $post->post_modified,
                                'post_author_id' => (int) $post->post_author,
                                'post_author_name' => $author ? $author->display_name : null,
                                'guid' => $post->guid,
                                'permalink' => $permalink ? $permalink : null,
                        );

                        // Optionally collect categories/tags as metadata (lightweight).
                        $categories = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
                        $tags = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );

                        if ( $categories || $tags ) {
                                $post_data['metadata'] = array(
                                        'categories' => $categories,
                                        'tags' => $tags,
                                );
                        }

                        $collected[] = $post_data;
                }

                return $collected;
        }

        /**
         * Ship user snapshots to the MarQira API.
         *
         * @param array $users Array of user data from collect_users().
         * @return bool True on success, false on failure.
         */
        public static function ship_users( $users ) {
                if ( empty( $users ) ) {
                        return false;
                }

                $credentials = Marqira_Enrollment::get_credentials();
                if ( ! $credentials ) {
                        return false;
                }

                $api_url = isset( $credentials['api_url'] ) ? $credentials['api_url'] : 'https://api.marqira.com';
                $endpoint = rtrim( $api_url, '/' ) . '/api/v1/sites/users';

                $payload = array(
                        'snapshot_at' => gmdate( 'Y-m-d H:i:s' ),
                        'users' => $users,
                );

                $path = '/api/v1/sites/users';
                $body = wp_json_encode( $payload );
                $headers = Marqira_Hmac_Client::generate_headers( 'POST', $path, array(), $body, $credentials );

                if ( empty( $headers ) ) {
                        Marqira_Logger::log(
                                'data_collection_users_failed',
                                'Failed to generate HMAC headers for user data shipping.',
                                'error'
                        );
                        return false;
                }

                $response = wp_remote_post(
                        $endpoint,
                        array(
                                'timeout' => 30,
                                'headers' => $headers,
                                'body'    => $body,
                        )
                );

                if ( is_wp_error( $response ) ) {
                        Marqira_Logger::log(
                                'data_collection_users_failed',
                                sprintf( 'User data shipping failed: %s', $response->get_error_message() ),
                                'error'
                        );
                        return false;
                }

                $status_code = wp_remote_retrieve_response_code( $response );
                if ( 200 === (int) $status_code ) {
                        Marqira_Logger::log(
                                'data_collection_users_sent',
                                sprintf( 'Shipped %d user snapshots successfully.', count( $users ) ),
                                'info'
                        );
                        return true;
                }

                Marqira_Logger::log(
                        'data_collection_users_failed',
                        sprintf( 'User data shipping failed with status %d', $status_code ),
                        'error'
                );
                return false;
        }

        /**
         * Ship post snapshots to the MarQira API.
         *
         * @param array $posts Array of post data from collect_posts().
         * @return bool True on success, false on failure.
         */
        public static function ship_posts( $posts ) {
                if ( empty( $posts ) ) {
                        return false;
                }

                $credentials = Marqira_Enrollment::get_credentials();
                if ( ! $credentials ) {
                        return false;
                }

                $api_url = isset( $credentials['api_url'] ) ? $credentials['api_url'] : 'https://api.marqira.com';
                $endpoint = rtrim( $api_url, '/' ) . '/api/v1/sites/posts';

                $payload = array(
                        'snapshot_at' => gmdate( 'Y-m-d H:i:s' ),
                        'posts' => $posts,
                );

                $path = '/api/v1/sites/posts';
                $body = wp_json_encode( $payload );
                $headers = Marqira_Hmac_Client::generate_headers( 'POST', $path, array(), $body, $credentials );

                if ( empty( $headers ) ) {
                        Marqira_Logger::log(
                                'data_collection_posts_failed',
                                'Failed to generate HMAC headers for post data shipping.',
                                'error'
                        );
                        return false;
                }

                $response = wp_remote_post(
                        $endpoint,
                        array(
                                'timeout' => 30,
                                'headers' => $headers,
                                'body'    => $body,
                        )
                );

                if ( is_wp_error( $response ) ) {
                        Marqira_Logger::log(
                                'data_collection_posts_failed',
                                sprintf( 'Post data shipping failed: %s', $response->get_error_message() ),
                                'error'
                        );
                        return false;
                }

                $status_code = wp_remote_retrieve_response_code( $response );
                if ( 200 === (int) $status_code ) {
                        Marqira_Logger::log(
                                'data_collection_posts_sent',
                                sprintf( 'Shipped %d post snapshots successfully.', count( $posts ) ),
                                'info'
                        );
                        return true;
                }

                Marqira_Logger::log(
                        'data_collection_posts_failed',
                        sprintf( 'Post data shipping failed with status %d', $status_code ),
                        'error'
                );
                return false;
        }

        /**
         * Collect and ship all data (users + posts).
         *
         * This is the main entry point for periodic data collection.
         * Called by WP-Cron or manual trigger.
         *
         * @return array Result summary with counts.
         */
        public static function collect_and_ship_all() {
                if ( ! Marqira_Enrollment::is_enrolled() ) {
                        return array(
                                'success' => false,
                                'error' => 'Site not enrolled',
                        );
                }

                $users = self::collect_users();
                $posts = self::collect_posts();

                $users_shipped = false;
                $posts_shipped = false;

                if ( ! empty( $users ) ) {
                        $users_shipped = self::ship_users( $users );
                }

                if ( ! empty( $posts ) ) {
                        $posts_shipped = self::ship_posts( $posts );
                }

                $users_count = count( $users );
                $posts_count = count( $posts );

                // Always log a run summary so every collection run (scheduled or manual)
                // leaves a trace in Recent Activity, even when nothing was collected.
                $all_ok = ( 0 === $users_count || $users_shipped ) && ( 0 === $posts_count || $posts_shipped );
                Marqira_Logger::log(
                        $all_ok ? 'data_collection_run' : 'data_collection_run_failed',
                        sprintf(
                                'Data collection run: %d users (%s), %d posts (%s).',
                                $users_count,
                                ( 0 === $users_count ) ? 'none to send' : ( $users_shipped ? 'sent' : 'send FAILED' ),
                                $posts_count,
                                ( 0 === $posts_count ) ? 'none to send' : ( $posts_shipped ? 'sent' : 'send FAILED' )
                        ),
                        $all_ok ? 'info' : 'warning'
                );

                return array(
                        'success' => true,
                        'users_collected' => $users_count,
                        'users_shipped' => $users_shipped,
                        'posts_collected' => $posts_count,
                        'posts_shipped' => $posts_shipped,
                );
        }
}
