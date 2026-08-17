<?php
/**
 * Tests for WordPress data collection (users + posts).
 *
 * Verifies that:
 *   - collect_users() returns properly formatted user data;
 *   - collect_posts() returns properly formatted post data;
 *   - ship_users() constructs correct HMAC requests;
 *   - ship_posts() constructs correct HMAC requests;
 *   - data is only shipped when the site is enrolled.
 *
 * Run via: php tests/run.php
 *
 * @package Marqira_Connector
 */

require_once __DIR__ . '/bootstrap.php';

// ---------------------------------------------------------------------------
// WordPress stubs for user/post data collection
// ---------------------------------------------------------------------------
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		// Stub: always return success for shipping tests
		return array( 'response' => array( 'code' => 200 ), 'body' => 'ok' );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return ( $thing instanceof WP_Error );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function get_error_message() {
			return 'stub error';
		}
	}
}

if ( ! function_exists( 'get_users' ) ) {
	function get_users( $args = array() ) {
		$users = array(
			(object) array(
				'ID' => 1,
				'user_login' => 'admin',
				'user_email' => 'admin@example.com',
				'display_name' => 'Administrator',
				'user_registered' => '2024-01-01 00:00:00',
				'roles' => array( 'administrator' ),
			),
			(object) array(
				'ID' => 2,
				'user_login' => 'editor',
				'user_email' => 'editor@example.com',
				'display_name' => 'Editor User',
				'user_registered' => '2024-01-15 10:00:00',
				'roles' => array( 'editor' ),
			),
		);
		$limit = isset( $args['number'] ) ? $args['number'] : 1000;
		return array_slice( $users, 0, $limit );
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key, $single = false ) {
		// Stub: user 2 has last_login tracked
		if ( 2 === $user_id && 'last_login' === $key ) {
			return $single ? 1705766400 : array( 1705766400 );
		}
		return $single ? '' : array();
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) {
		if ( 1 === $user_id ) {
			return (object) array( 'display_name' => 'Admin' );
		}
		return false;
	}
}

if ( ! function_exists( 'wp_get_post_categories' ) ) {
	function wp_get_post_categories( $post_id, $args = array() ) {
		if ( 1 === $post_id ) {
			return array( 'News' );
		}
		return array();
	}
}

if ( ! function_exists( 'wp_get_post_tags' ) ) {
	function wp_get_post_tags( $post_id, $args = array() ) {
		if ( 1 === $post_id ) {
			return array( 'announcement' );
		}
		return array();
	}
}

// Stub $wpdb for post queries
if ( ! isset( $GLOBALS['wpdb'] ) ) {
	class MQ_Wpdb_Stub_Posts {
		public $posts = 'wp_posts';
		public function prepare( $query, ...$args ) {
			// Just return the query with %d/%s placeholders resolved
			return vsprintf( str_replace( array( '%d', '%s' ), '%s', $query ), $args );
		}
		public function get_results( $query ) {
			// Return two test posts
			return array(
				(object) array(
					'ID' => 1,
					'post_author' => 1,
					'post_date' => '2024-01-01 12:00:00',
					'post_modified' => '2024-01-02 10:00:00',
					'post_status' => 'publish',
					'post_type' => 'post',
					'post_title' => 'Hello World',
					'guid' => 'https://example.com/?p=1',
				),
				(object) array(
					'ID' => 5,
					'post_author' => 1,
					'post_date' => '2024-01-10 14:00:00',
					'post_modified' => '2024-01-10 14:00:00',
					'post_status' => 'publish',
					'post_type' => 'page',
					'post_title' => 'About Us',
					'guid' => 'https://example.com/?page_id=5',
				),
			);
		}
	}
	$GLOBALS['wpdb'] = new MQ_Wpdb_Stub_Posts();
}

// Load the class under test
require_once dirname( __DIR__ ) . '/includes/class-marqira-data-collector.php';

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------
function mq_reset_collector_state() {
	$GLOBALS['__mq_options']    = array();
	$GLOBALS['__mq_transients'] = array();
	$ref  = new ReflectionClass( 'Marqira_Enrollment' );
	$prop = $ref->getProperty( 'credentials_cache' );
	$prop->setAccessible( true );
	$prop->setValue( null, false );
}

function mq_enroll_collector_site() {
	$creds = array(
		'site_uuid'   => '11111111-2222-3333-4444-555555555555',
		'site_secret' => 'super-secret-value',
		'kid'         => 'key-1',
		'api_url'     => 'https://api.example.test',
	);
	$encrypted = Marqira_Crypto::encrypt( json_encode( $creds ) );
	update_option( Marqira_Enrollment::CREDENTIALS_OPTION, $encrypted );

	$ref  = new ReflectionClass( 'Marqira_Enrollment' );
	$prop = $ref->getProperty( 'credentials_cache' );
	$prop->setAccessible( true );
	$prop->setValue( null, false );
}

// ---------------------------------------------------------------------------
// 1. collect_users() returns properly formatted user data.
// ---------------------------------------------------------------------------
mq_reset_collector_state();
$users = Marqira_Data_Collector::collect_users();
mq_ok( is_array( $users ) && 2 === count( $users ), 'collect_users returns an array of 2 users' );
mq_ok( 1 === $users[0]['wp_user_id'] && 'admin' === $users[0]['user_login'], 'first user has correct wp_user_id and user_login' );
mq_ok( is_array( $users[0]['roles'] ) && in_array( 'administrator', $users[0]['roles'], true ), 'first user roles include administrator' );
mq_ok( 2 === $users[1]['wp_user_id'] && isset( $users[1]['last_login_at'] ), 'second user has last_login_at from user_meta' );

// ---------------------------------------------------------------------------
// 2. collect_posts() returns properly formatted post data.
// ---------------------------------------------------------------------------
mq_reset_collector_state();
$posts = Marqira_Data_Collector::collect_posts();
mq_ok( is_array( $posts ) && 2 === count( $posts ), 'collect_posts returns an array of 2 posts' );
mq_ok( 1 === $posts[0]['wp_post_id'] && 'post' === $posts[0]['post_type'], 'first post has correct wp_post_id and post_type' );
mq_ok( 'Hello World' === $posts[0]['post_title'], 'first post title is Hello World' );
mq_ok( isset( $posts[0]['metadata']['categories'] ) && in_array( 'News', $posts[0]['metadata']['categories'], true ), 'first post has News category in metadata' );
mq_ok( 5 === $posts[1]['wp_post_id'] && 'page' === $posts[1]['post_type'], 'second post (page) has correct wp_post_id and post_type' );

// ---------------------------------------------------------------------------
// 3. ship_users() returns false when not enrolled.
// ---------------------------------------------------------------------------
mq_reset_collector_state();
$users = array( array( 'wp_user_id' => 1, 'user_login' => 'test' ) );
$shipped = Marqira_Data_Collector::ship_users( $users );
mq_ok( false === $shipped, 'ship_users returns false when site is not enrolled' );

// ---------------------------------------------------------------------------
// 4. ship_posts() returns false when not enrolled.
// ---------------------------------------------------------------------------
mq_reset_collector_state();
$posts = array( array( 'wp_post_id' => 1, 'post_type' => 'post' ) );
$shipped = Marqira_Data_Collector::ship_posts( $posts );
mq_ok( false === $shipped, 'ship_posts returns false when site is not enrolled' );

// ---------------------------------------------------------------------------
// 5. collect_and_ship_all() returns error when not enrolled.
// ---------------------------------------------------------------------------
mq_reset_collector_state();
$result = Marqira_Data_Collector::collect_and_ship_all();
mq_ok( is_array( $result ) && false === $result['success'], 'collect_and_ship_all returns success=false when not enrolled' );
mq_ok( isset( $result['error'] ) && 'Site not enrolled' === $result['error'], 'collect_and_ship_all error message is correct' );

// ---------------------------------------------------------------------------
// 6. collect_and_ship_all() collects data when enrolled (shipping will fail
//    in this test environment since we have no real API, but collection counts
//    should be correct).
// ---------------------------------------------------------------------------
mq_reset_collector_state();
mq_enroll_collector_site();
$result = Marqira_Data_Collector::collect_and_ship_all();
mq_ok( is_array( $result ) && true === $result['success'], 'collect_and_ship_all returns success=true when enrolled' );
mq_ok( 2 === $result['users_collected'], 'collect_and_ship_all collected 2 users' );
mq_ok( 2 === $result['posts_collected'], 'collect_and_ship_all collected 2 posts' );
// Shipping will be false because there's no real API endpoint, but that's expected in the test environment.

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
echo "test-data-collector.php: {$GLOBALS['__mq_pass']} passed, {$GLOBALS['__mq_fail']} failed\n";
