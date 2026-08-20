<?php
/**
 * Plugin Name: MarQira Pulse
 * Plugin URI:  https://marqira.com
 * Description: Connects your WordPress site to MarQira Pulse for centralized monitoring, uptime alerting and secure automation. Keeps the connection alive across plugin updates and restricts Application Password authentication to approved MarQira infrastructure IPs.
 * Version:     1.2.12
 * Requires at least: 5.6
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * Author:      MarQira
 * Author URI:  https://marqira.com
 * License:     Proprietary
 * Text Domain: marqira-connector
 *
 * @package Marqira_Connector
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

/**
 * Plugin constants.
 */
define( 'MARQIRA_CONNECTOR_VERSION',     '1.2.12' );
define( 'MARQIRA_CONNECTOR_PLUGIN_FILE', __FILE__ );
define( 'MARQIRA_CONNECTOR_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'MARQIRA_CONNECTOR_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

/**
 * Maximum rows kept in the security log table.
 * Override in wp-config.php: define( 'MARQIRA_CONNECTOR_LOG_CAP', 1000 );
 */
if ( ! defined( 'MARQIRA_CONNECTOR_LOG_CAP' ) ) {
        define( 'MARQIRA_CONNECTOR_LOG_CAP', 500 );
}

/**
 * Load all plugin include files.
 *
 * @return void
 */
function marqira_connector_load_includes() {
        $includes = array(
                'includes/class-marqira-ip-utils.php',
                'includes/class-marqira-cloudflare.php',
                'includes/class-marqira-logger.php',
                'includes/class-marqira-diagnostics.php',
                'includes/class-marqira-app-password-guard.php',
                'includes/class-marqira-rest-guard.php',
                // Phase 4 — Enrollment + HMAC + Heartbeat
                'includes/class-marqira-crypto.php',
                'includes/class-marqira-enrollment.php',
                'includes/class-marqira-hmac-client.php',
                // Immediate updates — inbound signature verification + control-plane REST push
                'includes/class-marqira-hmac-server.php',
                'includes/class-marqira-rest-controller.php',
                // Full user management — signed WordPress user CRUD endpoints
                'includes/class-marqira-users.php',
                // Critical-error protection & automatic recovery
                'includes/class-marqira-health-check.php',
                'includes/class-marqira-recovery.php',
                'includes/class-marqira-config-fetcher.php',
                'includes/class-marqira-heartbeat.php',
                // Phase 7 — Remote "update this site now" command channel
                'includes/class-marqira-remote-update.php',
                // Increment 5 — WordPress data collection
                'includes/class-marqira-data-collector.php',
                // Phase 7 — Plugin auto-updates
                'includes/class-marqira-updater.php',
                // Phase 8 — Visitor analytics
                'includes/class-marqira-visitor-tracker.php',
                // WP-CLI commands
                'includes/class-marqira-cli.php',
        );

        foreach ( $includes as $include ) {
                $path = MARQIRA_CONNECTOR_PLUGIN_DIR . $include;
                if ( file_exists( $path ) ) {
                        require_once $path;
                }
        }

        if ( is_admin() ) {
                require_once MARQIRA_CONNECTOR_PLUGIN_DIR . 'admin/class-marqira-admin.php';
        }
}

/**
 * Load the include files as early as possible in the request lifecycle.
 *
 * The plugin registers hooks (notably the `cron_schedules` filter, below) at
 * file scope. Those callbacks depend on classes such as Marqira_Heartbeat,
 * which were previously only loaded on the `init` action. WordPress can apply
 * the `cron_schedules` filter *before* `init` runs — for example while
 * rescheduling overdue cron events during core-upgrade finalization or the
 * first request after an upgrade. When that happened the class was not yet
 * loaded and the site died with "Fatal error: Class \"Marqira_Heartbeat\"
 * not found".
 *
 * Loading the includes on `plugins_loaded` (which fires before `init`) makes
 * every class available before any of our hooks can fire. The loader is
 * idempotent — it uses require_once — so it is always safe to call again.
 */
add_action( 'plugins_loaded', 'marqira_connector_load_includes' );

/**
 * Install (or refresh) the MarQira Recovery Guard must-use plugin.
 *
 * The guard is a dependency-free fatal-error handler that must load BEFORE
 * regular plugins, so it lives in wp-content/mu-plugins/. We copy the bundled
 * copy from this plugin into the mu-plugins directory whenever it is missing or
 * out of date. This is safe and idempotent, and it self-heals if the file is
 * removed. Failure to install is non-fatal — the connector still works, only
 * the last-resort recovery layer is unavailable (logged, not thrown).
 *
 * @return void
 */
function marqira_connector_install_guard() {
        $source = MARQIRA_CONNECTOR_PLUGIN_DIR . 'mu-plugins/marqira-guard.php';
        if ( ! file_exists( $source ) ) {
                return;
        }

        $mu_dir = defined( 'WPMU_PLUGIN_DIR' )
                ? WPMU_PLUGIN_DIR
                : ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/mu-plugins' : ABSPATH . 'wp-content/mu-plugins' );
        $target = $mu_dir . '/marqira-guard.php';

        // Only rewrite when missing or changed, to avoid needless disk writes.
        $need_copy = true;
        if ( file_exists( $target ) ) {
                $need_copy = ( @md5_file( $target ) !== @md5_file( $source ) );
        }
        if ( ! $need_copy ) {
                return;
        }

        if ( ! is_dir( $mu_dir ) ) {
                if ( function_exists( 'wp_mkdir_p' ) ) {
                        wp_mkdir_p( $mu_dir );
                } else {
                        @mkdir( $mu_dir, 0755, true );
                }
        }

        if ( is_dir( $mu_dir ) && is_writable( $mu_dir ) ) {
                @copy( $source, $target );
        } elseif ( class_exists( 'Marqira_Logger' ) ) {
                Marqira_Logger::log(
                        'recovery_guard_install_skipped',
                        'Could not install the recovery guard: mu-plugins directory is not writable.',
                        'warning'
                );
        }
}
add_action( 'admin_init', 'marqira_connector_install_guard' );

/**
 * Initialize the plugin.
 *
 * @return void
 */
function marqira_connector_init() {
        // Idempotent (require_once) — safe even though plugins_loaded already ran.
        marqira_connector_load_includes();

        // Register the Application Password guard on every request.
        if ( class_exists( 'Marqira_App_Password_Guard' ) ) {
                new Marqira_App_Password_Guard();
        }

        // Register the optional REST API guard on every request.
        if ( class_exists( 'Marqira_Rest_Guard' ) ) {
                new Marqira_Rest_Guard();
        }

        // Initialize heartbeat system (Phase 4).
        if ( class_exists( 'Marqira_Heartbeat' ) ) {
                Marqira_Heartbeat::init();
        }

        // Register the control-plane REST push endpoints (immediate updates).
        if ( class_exists( 'Marqira_Rest_Controller' ) ) {
                Marqira_Rest_Controller::init();
        }

        // Register the signed WordPress user-management REST endpoints (Phase C).
        if ( class_exists( 'Marqira_Users' ) ) {
                Marqira_Users::init();
        }

        // Initialize data collection system (Increment 5).
        if ( class_exists( 'Marqira_Data_Collector' ) ) {
                Marqira_Data_Collector::init();
        }

        // Initialize visitor tracking system (Phase 8).
        if ( class_exists( 'Marqira_Visitor_Tracker' ) ) {
                Marqira_Visitor_Tracker::init();
        }

        // Initialize plugin auto-update system (Phase 7).
        if ( class_exists( 'Marqira_Updater' ) ) {
                $update_server_url = defined( 'MARQIRA_UPDATE_SERVER_URL' )
                        ? MARQIRA_UPDATE_SERVER_URL
                        : 'https://api.marqira.com/api/v1/plugin/';
                
                $updater = new Marqira_Updater(
                        MARQIRA_CONNECTOR_PLUGIN_FILE,
                        MARQIRA_CONNECTOR_VERSION,
                        $update_server_url
                );
                $updater->init();
        }

        // Register WP-CLI commands.
        if ( class_exists( 'Marqira_CLI' ) ) {
                Marqira_CLI::register();
        }

        // Admin UI — only in the WordPress admin context.
        if ( is_admin() ) {
                new Marqira_Admin();
        }
}
add_action( 'init', 'marqira_connector_init' );

/**
 * Add custom cron intervals for heartbeats and data collection.
 *
 * @param array $schedules Existing schedules.
 * @return array
 */
function marqira_connector_cron_schedules( $schedules ) {
        // WordPress passes an array, but be defensive against a malformed value
        // supplied by another misbehaving filter earlier in the chain.
        if ( ! is_array( $schedules ) ) {
                $schedules = array();
        }

        // The `cron_schedules` filter can be applied before our `init`/`plugins_loaded`
        // bootstrap has loaded the include files (e.g. when WordPress reschedules an
        // overdue cron event during core-upgrade finalization). Ensure the classes we
        // depend on are actually loaded before calling them, rather than assuming an
        // earlier hook already did so. This is a real dependency fix — the custom
        // schedules are still added, they are never silently dropped.
        if ( ! class_exists( 'Marqira_Heartbeat' ) || ! class_exists( 'Marqira_Data_Collector' ) ) {
                marqira_connector_load_includes();
        }

        if ( class_exists( 'Marqira_Heartbeat' ) ) {
                $schedules = Marqira_Heartbeat::add_cron_interval( $schedules );
        }

        if ( class_exists( 'Marqira_Data_Collector' ) ) {
                $schedules = Marqira_Data_Collector::add_cron_interval( $schedules );
        }

        return $schedules;
}
add_filter( 'cron_schedules', 'marqira_connector_cron_schedules' );

/**
 * Return the default plugin settings.
 *
 * @return array
 */
function marqira_connector_default_settings() {
        return array(
                'protection_enabled'       => true,
                'rest_restriction_enabled' => false,
                'allowed_ips'              => array( '187.77.136.105' ),
        );
}

/**
 * Retrieve the plugin settings merged with defaults.
 *
 * @return array
 */
function marqira_connector_get_settings() {
        $defaults = marqira_connector_default_settings();
        $settings = get_option( 'marqira_connector_settings', array() );

        if ( ! is_array( $settings ) ) {
                $settings = array();
        }

        $settings = array_merge( $defaults, $settings );

        if ( ! is_array( $settings['allowed_ips'] ) ) {
                $settings['allowed_ips'] = $defaults['allowed_ips'];
        }

        $settings['protection_enabled']       = (bool) $settings['protection_enabled'];
        $settings['rest_restriction_enabled'] = ! empty( $settings['rest_restriction_enabled'] );

        return $settings;
}

/**
 * Activation hook callback.
 *
 * Creates the log table and seeds default settings.
 *
 * @return void
 */
function marqira_connector_activate() {
        marqira_connector_load_includes();

        // Seed default settings only if they do not already exist.
        if ( false === get_option( 'marqira_connector_settings', false ) ) {
                add_option( 'marqira_connector_settings', marqira_connector_default_settings() );
        }

        // Create (or upgrade) the bounded security-log table.
        if ( class_exists( 'Marqira_Logger' ) ) {
                Marqira_Logger::install_table();
                Marqira_Logger::log_activation();
        }

        // Install the resilient recovery guard (must-use plugin).
        marqira_connector_install_guard();

        // Register heartbeat cron (Phase 4).
        if ( class_exists( 'Marqira_Heartbeat' ) ) {
                Marqira_Heartbeat::register_cron();
        }

        // Register data collection cron (Increment 5).
        if ( class_exists( 'Marqira_Data_Collector' ) ) {
                Marqira_Data_Collector::register_cron();
        }

        // Immediately tell the control plane the connector is live again so the
        // dashboard flips to "online" without waiting for the first cron beat.
        if ( class_exists( 'Marqira_Heartbeat' ) ) {
                Marqira_Heartbeat::send_status_signal( 'online', 'connector_activated' );
        }
}
register_activation_hook( __FILE__, 'marqira_connector_activate' );

/**
 * Deactivation hook callback.
 *
 * @return void
 */
function marqira_connector_deactivate() {
        marqira_connector_load_includes();

        // Send an explicit "offline" signal WHILE the plugin is still loaded, so
        // the dashboard shows the site offline the instant the connector is
        // switched off — rather than continuing to appear online until the
        // heartbeat-timeout watchdog eventually notices the silence.
        if ( class_exists( 'Marqira_Heartbeat' ) ) {
                Marqira_Heartbeat::send_status_signal( 'offline', 'connector_deactivated' );
        }

        if ( class_exists( 'Marqira_Logger' ) ) {
                Marqira_Logger::log_deactivation();
        }

        // Unregister heartbeat cron (Phase 4).
        if ( class_exists( 'Marqira_Heartbeat' ) ) {
                Marqira_Heartbeat::unregister_cron();
        }

        // Unregister data collection cron (Increment 5).
        if ( class_exists( 'Marqira_Data_Collector' ) ) {
                Marqira_Data_Collector::unregister_cron();
        }
}
register_deactivation_hook( __FILE__, 'marqira_connector_deactivate' );
