<?php
/**
 * Remote update command handler for MarQira Connector.
 *
 * When the MarQira API queues an "update this site now" command from the
 * dashboard, it is delivered to this site inside the heartbeat response as a
 * `commands` array. This class parses those commands, runs the WordPress
 * plugin upgrader for the MarQira Connector, and reports the outcome back to
 * the API via the HMAC-authenticated ack endpoint.
 *
 * This is what makes per-site "push the update to a single website" possible.
 * It requires connector v1.2.2+ on the site (older versions simply never look
 * at the `commands` key, so the command is a harmless no-op there).
 *
 * @package Marqira_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

/**
 * Class Marqira_Remote_Update
 */
class Marqira_Remote_Update {

        /**
         * Ack endpoint path on the MarQira API.
         */
        const ACK_PATH = '/api/v1/update-command/ack';

        /**
         * Transient used as a short-lived lock so two overlapping heartbeats can
         * never launch concurrent upgrades of the same plugin.
         */
        const LOCK_TRANSIENT = 'marqira_remote_update_lock';

        /**
         * Handle the commands block from a successful heartbeat response.
         *
         * Safe to call with any decoded body — it only acts when a well-formed
         * `update_plugin` command is present.
         *
         * @param array $body Decoded heartbeat response body.
         * @return void
         */
        public static function handle_response( $body ) {
                if ( ! is_array( $body ) || empty( $body['commands'] ) || ! is_array( $body['commands'] ) ) {
                        return;
                }

                foreach ( $body['commands'] as $command ) {
                        if ( ! is_array( $command ) ) {
                                continue;
                        }

                        $type = isset( $command['type'] ) ? (string) $command['type'] : '';

                        if ( 'update_plugin' === $type ) {
                                $target = isset( $command['target_version'] ) ? (string) $command['target_version'] : '';
                                self::run_plugin_update( $target );
                                // Only one maintenance command per heartbeat.
                                return;
                        }

                        if ( 'update_core' === $type ) {
                                self::run_core_update();
                                return;
                        }

                        if ( 'update_all_plugins' === $type ) {
                                self::run_all_plugins_update();
                                return;
                        }
                }
        }

        /**
         * Upgrade WordPress core to the latest available version.
         *
         * @return void
         */
        public static function run_core_update() {
                if ( get_transient( self::LOCK_TRANSIENT ) ) {
                        Marqira_Logger::log(
                                'remote_update_skipped',
                                'A remote update is already in progress; skipping duplicate command.',
                                'info'
                        );
                        return;
                }

                set_transient( self::LOCK_TRANSIENT, time(), 15 * MINUTE_IN_SECONDS );

                Marqira_Logger::log( 'remote_core_update_started', 'Remote WordPress core update command received.', 'info' );
                self::send_ack( 'in_progress', 'WordPress core update started on the site.', null );

                $result = self::perform_core_upgrade();

                delete_transient( self::LOCK_TRANSIENT );

                if ( is_wp_error( $result ) ) {
                        Marqira_Logger::log( 'remote_core_update_failed', sprintf( 'Core update failed: %s', $result->get_error_message() ), 'error' );
                        self::send_ack( 'failed', $result->get_error_message(), null );
                        return;
                }

                global $wp_version;
                $installed = isset( $wp_version ) ? (string) $wp_version : '';
                Marqira_Logger::log( 'remote_core_update_completed', sprintf( 'WordPress core updated. Version: %s.', $installed ), 'info' );
                self::send_ack( 'completed', 'WordPress core updated successfully' . ( '' !== $installed ? ' to ' . $installed : '' ) . '.', null );
        }

        /**
         * Bulk-update every plugin that has an available update.
         *
         * @return void
         */
        public static function run_all_plugins_update() {
                if ( get_transient( self::LOCK_TRANSIENT ) ) {
                        Marqira_Logger::log(
                                'remote_update_skipped',
                                'A remote update is already in progress; skipping duplicate command.',
                                'info'
                        );
                        return;
                }

                set_transient( self::LOCK_TRANSIENT, time(), 15 * MINUTE_IN_SECONDS );

                Marqira_Logger::log( 'remote_plugins_update_started', 'Remote all-plugins update command received.', 'info' );
                self::send_ack( 'in_progress', 'Plugin updates started on the site.', null );

                $result = self::perform_all_plugins_upgrade();

                delete_transient( self::LOCK_TRANSIENT );

                if ( is_wp_error( $result ) ) {
                        Marqira_Logger::log( 'remote_plugins_update_failed', sprintf( 'Plugin updates failed: %s', $result->get_error_message() ), 'error' );
                        self::send_ack( 'failed', $result->get_error_message(), null );
                        return;
                }

                Marqira_Logger::log( 'remote_plugins_update_completed', sprintf( '%s', $result ), 'info' );
                self::send_ack( 'completed', (string) $result, null );
        }

        /**
         * Run the WordPress plugin upgrader for this connector.
         *
         * @param string $target_version Version the API wants this site to run.
         * @return void
         */
        public static function run_plugin_update( $target_version ) {
                // Guard: never run concurrent upgrades.
                if ( get_transient( self::LOCK_TRANSIENT ) ) {
                        Marqira_Logger::log(
                                'remote_update_skipped',
                                'A remote update is already in progress; skipping duplicate command.',
                                'info'
                        );
                        return;
                }

                // If we are already at (or beyond) the requested version there is
                // nothing to do — acknowledge as completed so the dashboard resolves.
                if ( '' !== $target_version
                        && version_compare( MARQIRA_CONNECTOR_VERSION, $target_version, '>=' ) ) {
                        self::send_ack( 'completed', 'Already running version ' . MARQIRA_CONNECTOR_VERSION . '.', MARQIRA_CONNECTOR_VERSION );
                        return;
                }

                set_transient( self::LOCK_TRANSIENT, time(), 5 * MINUTE_IN_SECONDS );

                Marqira_Logger::log(
                        'remote_update_started',
                        sprintf( 'Remote update command received (target %s). Starting plugin upgrade.', $target_version ),
                        'info'
                );

                // Tell the API we have begun so the dashboard can show progress.
                self::send_ack( 'in_progress', 'Update started on the site.', null );

                $result = self::perform_upgrade();

                delete_transient( self::LOCK_TRANSIENT );

                if ( is_wp_error( $result ) ) {
                        Marqira_Logger::log(
                                'remote_update_failed',
                                sprintf( 'Remote update failed: %s', $result->get_error_message() ),
                                'error'
                        );
                        self::send_ack( 'failed', $result->get_error_message(), MARQIRA_CONNECTOR_VERSION );
                        return;
                }

                // Read the freshly installed version from the plugin header so the ack
                // reports the true installed version (not the in-memory old constant).
                $new_version = self::read_installed_version();

                Marqira_Logger::log(
                        'remote_update_completed',
                        sprintf( 'Remote update completed. Installed version: %s.', $new_version ),
                        'info'
                );

                self::send_ack( 'completed', 'Plugin updated successfully.', $new_version );
        }

        /**
         * Perform the actual plugin upgrade via the WordPress upgrader.
         *
         * @return true|WP_Error True on success, WP_Error on failure.
         */
        private static function perform_upgrade() {
                // Load the upgrade machinery — not present during a normal wp-cron run.
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/misc.php';
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

                if ( ! class_exists( 'Plugin_Upgrader' ) || ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
                        return new WP_Error( 'upgrader_unavailable', 'WordPress upgrader classes are unavailable.' );
                }

                $plugin_basename = plugin_basename( MARQIRA_CONNECTOR_PLUGIN_FILE );

                // Force a fresh update check so the update transient carries our new
                // package URL. The connector's own updater answers the update-check
                // against the MarQira update server; clearing its cache guarantees the
                // very latest active release is used rather than a stale 12h cache.
                delete_transient( 'marqira_update_check' );
                delete_transient( 'marqira_update_check_info' );
                delete_site_transient( 'update_plugins' );
                wp_update_plugins();

                $updates = get_site_transient( 'update_plugins' );
                if ( ! isset( $updates->response[ $plugin_basename ] ) ) {
                        return new WP_Error(
                                'no_package',
                                'No update package is available from the MarQira update server for this plugin.'
                        );
                }

                $skin     = new Automatic_Upgrader_Skin();
                $upgrader = new Plugin_Upgrader( $skin );

                $result = $upgrader->upgrade( $plugin_basename );

                if ( is_wp_error( $result ) ) {
                        return $result;
                }

                if ( false === $result || null === $result ) {
                        $messages = method_exists( $skin, 'get_upgrade_messages' ) ? $skin->get_upgrade_messages() : array();
                        $detail   = ! empty( $messages ) ? implode( ' ', (array) $messages ) : 'The upgrade did not complete.';
                        return new WP_Error( 'upgrade_failed', $detail );
                }

                // Keep the plugin active after the files are swapped.
                if ( function_exists( 'is_plugin_active' ) && ! is_plugin_active( $plugin_basename ) ) {
                        activate_plugin( $plugin_basename );
                }

                return true;
        }

        /**
         * Perform a WordPress core upgrade to the latest available version.
         *
         * @return true|WP_Error True on success (or already current), WP_Error on failure.
         */
        private static function perform_core_upgrade() {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/misc.php';
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                require_once ABSPATH . 'wp-admin/includes/update.php';

                if ( ! class_exists( 'Core_Upgrader' ) || ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
                        return new WP_Error( 'upgrader_unavailable', 'WordPress core upgrader classes are unavailable.' );
                }

                // Refresh the core update information.
                wp_version_check( array(), true );

                $updates = get_core_updates();
                if ( empty( $updates ) || ! is_array( $updates ) || 'latest' === ( isset( $updates[0]->response ) ? $updates[0]->response : '' ) ) {
                        // Nothing to upgrade — treat as success so the command resolves.
                        return true;
                }

                $update = $updates[0];

                $skin     = new Automatic_Upgrader_Skin();
                $upgrader = new Core_Upgrader( $skin );
                $result   = $upgrader->upgrade( $update );

                if ( is_wp_error( $result ) ) {
                        return $result;
                }

                if ( false === $result || null === $result ) {
                        $messages = method_exists( $skin, 'get_upgrade_messages' ) ? $skin->get_upgrade_messages() : array();
                        $detail   = ! empty( $messages ) ? implode( ' ', (array) $messages ) : 'The core upgrade did not complete.';
                        return new WP_Error( 'core_upgrade_failed', $detail );
                }

                return true;
        }

        /**
         * Bulk-upgrade every plugin that has an available update.
         *
         * @return string|WP_Error Human-readable summary on success, WP_Error on failure.
         */
        private static function perform_all_plugins_upgrade() {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/misc.php';
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

                if ( ! class_exists( 'Plugin_Upgrader' ) || ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
                        return new WP_Error( 'upgrader_unavailable', 'WordPress upgrader classes are unavailable.' );
                }

                // Force fresh update data so we act on the very latest availability.
                delete_transient( 'marqira_update_check' );
                delete_transient( 'marqira_update_check_info' );
                delete_site_transient( 'update_plugins' );
                wp_update_plugins();

                $updates = get_site_transient( 'update_plugins' );
                if ( empty( $updates ) || empty( $updates->response ) || ! is_array( $updates->response ) ) {
                        return 'All plugins are already up to date.';
                }

                $plugins = array_keys( $updates->response );

                $skin     = new Automatic_Upgrader_Skin();
                $upgrader = new Plugin_Upgrader( $skin );
                $results  = $upgrader->bulk_upgrade( $plugins );

                if ( is_wp_error( $results ) ) {
                        return $results;
                }

                $succeeded = 0;
                $failed    = 0;
                if ( is_array( $results ) ) {
                        foreach ( $results as $res ) {
                                if ( $res && ! is_wp_error( $res ) ) {
                                        $succeeded++;
                                } else {
                                        $failed++;
                                }
                        }
                }

                if ( $succeeded === 0 && $failed > 0 ) {
                        return new WP_Error( 'plugins_upgrade_failed', sprintf( 'All %d plugin update(s) failed.', $failed ) );
                }

                return sprintf(
                        '%d plugin update(s) applied%s.',
                        $succeeded,
                        $failed > 0 ? sprintf( ', %d failed', $failed ) : ''
                );
        }

        /**
         * Read the plugin version from the (possibly just-updated) main plugin file.
         *
         * @return string Installed version, or the in-memory constant as a fallback.
         */
        private static function read_installed_version() {
                if ( ! function_exists( 'get_plugin_data' ) ) {
                        require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }

                $data = get_plugin_data( MARQIRA_CONNECTOR_PLUGIN_FILE, false, false );

                if ( is_array( $data ) && ! empty( $data['Version'] ) ) {
                        return (string) $data['Version'];
                }

                return MARQIRA_CONNECTOR_VERSION;
        }

        /**
         * Report an update command outcome to the MarQira API.
         *
         * @param string      $status  One of in_progress|completed|failed.
         * @param string|null $message Human-readable detail.
         * @param string|null $version Installed connector version, if known.
         * @return void
         */
        private static function send_ack( $status, $message = null, $version = null ) {
                if ( ! class_exists( 'Marqira_Enrollment' ) || ! Marqira_Enrollment::is_enrolled() ) {
                        return;
                }

                $credentials = Marqira_Enrollment::get_credentials();
                if ( empty( $credentials ) ) {
                        return;
                }

                $api_url = Marqira_Enrollment::get_api_url();
                $url     = rtrim( $api_url, '/' ) . self::ACK_PATH;

                $payload = array( 'status' => $status );
                if ( null !== $message ) {
                        $payload['message'] = (string) $message;
                }
                if ( null !== $version ) {
                        $payload['version'] = (string) $version;
                }

                $body    = wp_json_encode( $payload );
                $headers = Marqira_Hmac_Client::generate_headers( 'POST', self::ACK_PATH, array(), $body, $credentials );

                if ( empty( $headers ) ) {
                        return;
                }

                $response = wp_remote_post(
                        $url,
                        array(
                                'timeout' => 20,
                                'headers' => $headers,
                                'body'    => $body,
                        )
                );

                if ( is_wp_error( $response ) ) {
                        Marqira_Logger::log(
                                'remote_update_ack_failed',
                                sprintf( 'Failed to send update ack (%s): %s', $status, $response->get_error_message() ),
                                'warning'
                        );
                }
        }
}
