<?php
/**
 * Uninstall handler for MarQira Connector.
 *
 * Runs when the plugin is deleted from WordPress (Plugins → Delete).
 *
 * IMPORTANT — durable pairing survives plugin deletion:
 * -----------------------------------------------------------------------------
 * The MarQira pairing credentials (option `marqira_site_credentials`) are a
 * DURABLE connection identity, not disposable plugin data. They intentionally
 * remain in the WordPress database when the plugin is merely deleted, so the
 * supported lifecycle
 *
 *     Deactivate → Delete plugin → Reinstall plugin → Still connected
 *
 * works with NO new enrollment code and NO duplicate site in the dashboard.
 * On reinstall the connector finds the existing credentials, re-authenticates
 * with the same site UUID/secret, and automatically restores its heartbeat
 * schedule.
 *
 * The pairing credentials are removed by exactly two explicit, intentional
 * actions — never by ordinary plugin deletion:
 *   1. The user clicks "Disconnect from MarQira Pulse" inside WordPress
 *      (Marqira_Enrollment::disconnect()).
 *   2. The site is removed/revoked from the MarQira dashboard — the next
 *      heartbeat receives HTTP 403 site_revoked and the connector
 *      self-disconnects (Marqira_Heartbeat::handle_revocation()).
 *
 * What this uninstaller DOES remove (disposable local state only):
 *   - Plugin settings option (marqira_connector_settings)
 *   - Security log table ({prefix}marqira_log)
 *   - Transient caches (last heartbeat marker, allowed-IP / Cloudflare caches)
 *   - The heartbeat cron event (recreated automatically on reinstall while the
 *     durable credentials are present)
 *
 * What this uninstaller intentionally KEEPS:
 *   - marqira_site_credentials  ← durable pairing (see above)
 *
 * What this uninstaller never touches:
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

/**
 * Remove only the disposable local state for the current site.
 *
 * Deliberately does NOT delete `marqira_site_credentials` — that durable
 * pairing must survive plugin deletion/reinstallation (see file header).
 *
 * @return void
 */
function marqira_connector_cleanup_disposable_state() {
        global $wpdb;

        delete_option( 'marqira_connector_settings' );

        // Disposable caches — safe to drop; rebuilt on demand after reinstall.
        delete_transient( 'marqira_last_heartbeat_sent' );
        delete_transient( 'marqira_allowed_ips' );
        delete_transient( 'marqira_cloudflare_ranges' );

        // Clear the heartbeat cron. While the durable credentials remain, the
        // connector re-schedules this automatically on reinstall/activation and
        // via its per-request self-heal, so removing it here is non-destructive.
        wp_clear_scheduled_hook( 'marqira_send_heartbeat' );

        $table = $wpdb->prefix . 'marqira_log';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );

        // NOTE: marqira_site_credentials is intentionally preserved here.
}

if ( is_multisite() ) {
        // Clean up each site in the network (credentials preserved per-site).
        $site_ids = get_sites( array( 'fields' => 'ids' ) );
        foreach ( $site_ids as $site_id ) {
                switch_to_blog( $site_id );
                marqira_connector_cleanup_disposable_state();
                restore_current_blog();
        }
} else {
        marqira_connector_cleanup_disposable_state();
}
