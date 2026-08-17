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

		return array(
			'success' => true,
			'users_collected' => count( $users ),
			'users_shipped' => $users_shipped,
			'posts_collected' => count( $posts ),
			'posts_shipped' => $posts_shipped,
		);
	}
}
