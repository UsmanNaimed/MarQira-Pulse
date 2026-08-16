<?php
/**
 * Plugin Name: MarQira Connector
 * Plugin URI:  https://marqira.com
 * Description: Connects your WordPress site to MarQira for centralized monitoring and automation. Restricts Application Password authentication to approved MarQira infrastructure IPs.
 * Version:     1.0.0
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
define( 'MARQIRA_CONNECTOR_VERSION',     '1.0.0' );
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

	// Admin UI — only in the WordPress admin context.
	if ( is_admin() ) {
		new Marqira_Admin();
	}
}
add_action( 'init', 'marqira_connector_init' );

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
}
register_deactivation_hook( __FILE__, 'marqira_connector_deactivate' );
