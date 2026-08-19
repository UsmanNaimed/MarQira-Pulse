<?php
/**
 * Plugin Name: MarQira Pulse
 * Plugin URI:  https://marqira.com
 * Description: Connects your WordPress site to MarQira Pulse for centralized monitoring, uptime alerting and secure automation. Keeps the connection alive across plugin updates and restricts Application Password authentication to approved MarQira infrastructure IPs.
 * Version:     1.2.8
 * Requires at least: 5.6
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
define( 'MARQIRA_CONNECTOR_VERSION',     '1.2.8' );
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
 * Initialize the plugin.
 *
 * @return void
 */
function marqira_connector_init() {
        marqira_connector_load_includes();

        // Register the Application Password guard on every request.
        new Marqira_App_Password_Guard();

        // Register the optional REST API guard on every request.
        new Marqira_Rest_Guard();

        // Initialize heartbeat system (Phase 4).
        Marqira_Heartbeat::init();

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
        $schedules = Marqira_Heartbeat::add_cron_interval( $schedules );

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

        // Register heartbeat cron (Phase 4).
        if ( class_exists( 'Marqira_Heartbeat' ) ) {
                Marqira_Heartbeat::register_cron();
        }

        // Register data collection cron (Increment 5).
        if ( class_exists( 'Marqira_Data_Collector' ) ) {
                Marqira_Data_Collector::register_cron();
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
