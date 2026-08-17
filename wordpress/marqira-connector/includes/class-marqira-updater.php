<?php
/**
 * MarQira Pulse Plugin Updater
 *
 * Checks the MarQira update server for new plugin versions and integrates
 * with WordPress's built-in update mechanism.
 *
 * @package Marqira_Connector
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Marqira_Updater
 *
 * Handles plugin update checks against the MarQira private update server.
 */
class Marqira_Updater {

	/**
	 * Plugin slug (folder/file.php format).
	 *
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * Plugin basename.
	 *
	 * @var string
	 */
	private $plugin_basename;

	/**
	 * Current plugin version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Update server URL.
	 *
	 * @var string
	 */
	private $update_url;

	/**
	 * Cache key for update transient.
	 *
	 * @var string
	 */
	private $cache_key;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file Full path to the plugin file.
	 * @param string $version     Current plugin version.
	 * @param string $update_url  Update server URL.
	 */
	public function __construct( $plugin_file, $version, $update_url ) {
		$this->plugin_basename = plugin_basename( $plugin_file );
		$this->plugin_slug     = dirname( $this->plugin_basename );
		$this->version         = $version;
		$this->update_url      = trailingslashit( $update_url );
		$this->cache_key       = 'marqira_update_check';
	}

	/**
	 * Initialize update hooks.
	 */
	public function init() {
		// Hook into WordPress update checks.
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		
		// Clear cache when updates are checked manually.
		add_action( 'load-update-core.php', array( $this, 'clear_cache' ) );
		add_action( 'load-plugins.php', array( $this, 'clear_cache' ) );
	}

	/**
	 * Check for plugin updates.
	 *
	 * @param object $transient Update transient.
	 * @return object Modified transient.
	 */
	public function check_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		// Get cached update info or fetch fresh.
		$remote = $this->get_update_info();

		if ( ! $remote || ! isset( $remote->update_available ) ) {
			return $transient;
		}

		// If update is available, add it to the transient.
		if ( $remote->update_available && version_compare( $this->version, $remote->version, '<' ) ) {
			$transient->response[ $this->plugin_basename ] = (object) array(
				'slug'        => $this->plugin_slug,
				'plugin'      => $this->plugin_basename,
				'new_version' => $remote->version,
				'url'         => 'https://marqira.com',
				'package'     => $remote->download_url,
				'icons'       => array(),
				'banners'     => array(),
				'tested'      => $remote->tested_up_to ?? '',
				'requires_php' => $remote->requires_php ?? '7.4',
			);
		}

		return $transient;
	}

	/**
	 * Provide plugin information for "View Details" link.
	 *
	 * @param false|object|array $result The result object or array.
	 * @param string             $action The type of information being requested.
	 * @param object             $args   Plugin API arguments.
	 * @return false|object
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( $this->plugin_slug !== $args->slug ) {
			return $result;
		}

		// Get plugin info from update server.
		$remote = $this->get_plugin_info();

		if ( ! $remote ) {
			return $result;
		}

		return (object) array(
			'name'          => $remote->name ?? 'MarQira Connector',
			'slug'          => $remote->slug ?? $this->plugin_slug,
			'version'       => $remote->version ?? $this->version,
			'author'        => $remote->author ?? 'MarQira',
			'homepage'      => $remote->homepage ?? 'https://marqira.com',
			'download_link' => $remote->download_link ?? '',
			'requires'      => $remote->requires ?? '5.6',
			'requires_php'  => $remote->requires_php ?? '7.4',
			'tested'        => $remote->tested ?? '',
			'last_updated'  => $remote->last_updated ?? '',
			'sections'      => array(
				'changelog' => $remote->sections->changelog ?? 'No changelog available.',
			),
		);
	}

	/**
	 * Get update information from the remote server.
	 *
	 * @return object|false Update info or false on failure.
	 */
	private function get_update_info() {
		// Check cache first (12-hour TTL).
		$cached = get_transient( $this->cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		// Fetch from update server.
		$remote = $this->api_request( 'update-check', array( 'version' => $this->version ) );

		if ( $remote ) {
			set_transient( $this->cache_key, $remote, 12 * HOUR_IN_SECONDS );
		}

		return $remote;
	}

	/**
	 * Get plugin information from the remote server.
	 *
	 * @return object|false Plugin info or false on failure.
	 */
	private function get_plugin_info() {
		$cache_key = $this->cache_key . '_info';
		$cached    = get_transient( $cache_key );
		
		if ( false !== $cached ) {
			return $cached;
		}

		$remote = $this->api_request( 'info', array( 'version' => $this->version ) );

		if ( $remote ) {
			set_transient( $cache_key, $remote, 12 * HOUR_IN_SECONDS );
		}

		return $remote;
	}

	/**
	 * Make an API request to the update server.
	 *
	 * @param string $endpoint API endpoint (update-check or info).
	 * @param array  $params   Query parameters.
	 * @return object|false Response object or false on failure.
	 */
	private function api_request( $endpoint, $params = array() ) {
		$url = add_query_arg( $params, $this->update_url . $endpoint );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body );

		return is_object( $data ) ? $data : false;
	}

	/**
	 * Clear cached update information.
	 */
	public function clear_cache() {
		delete_transient( $this->cache_key );
		delete_transient( $this->cache_key . '_info' );
	}
}
