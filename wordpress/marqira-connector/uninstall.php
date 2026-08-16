<?php
/**
 * Uninstall handler for MarQira Connector.
 *
 * Removes all plugin data when the plugin is deleted from WordPress.
 * Removes:
 *   - Plugin settings option (marqira_connector_settings)
 *   - Security log table ({prefix}marqira_log)
 *
 * Does NOT remove:
 *   - WordPress Application Passwords
 *   - Any WordPress core or user data
 *
 * @package Marqira_Connector
 */

// Exit if not called by WordPress uninstall process.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

if ( is_multisite() ) {
	// Clean up each site in the network.
	$site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );

		delete_option( 'marqira_connector_settings' );

		$table = $wpdb->prefix . 'marqira_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		restore_current_blog();
	}
} else {
	delete_option( 'marqira_connector_settings' );

	$table = $wpdb->prefix . 'marqira_log';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}
