<?php
/**
 * Tests for Marqira_Users — the signed WordPress user-management REST
 * controller (Phase C). Exercises the handler logic against an in-memory
 * WordPress user store so no live WP install is needed. HMAC authorization is
 * covered separately by test-hmac-server.php, so these tests call the handlers
 * directly to focus on CRUD, role, reassignment, last-admin and idempotency
 * behaviour (§3–§9, §11, §14).
 *
 * @package Marqira_Connector
 */

require __DIR__ . '/bootstrap.php';

// ---------------------------------------------------------------------------
// Extra WP function stubs (the bootstrap already covers options/transients/__).
// ---------------------------------------------------------------------------
if ( ! function_exists( 'wp_json_encode' ) ) {
        function wp_json_encode( $data ) {
                return json_encode( $data );
        }
}
if ( ! function_exists( 'sanitize_user' ) ) {
        function sanitize_user( $u, $strict = false ) {
                return trim( (string) $u );
        }
}
if ( ! function_exists( 'sanitize_email' ) ) {
        function sanitize_email( $e ) {
                return trim( (string) $e );
        }
}
if ( ! function_exists( 'sanitize_key' ) ) {
        function sanitize_key( $k ) {
                return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) );
        }
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
        function sanitize_textarea_field( $s ) {
                return trim( (string) $s );
        }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
        function esc_url_raw( $u ) {
                return trim( (string) $u );
        }
}
if ( ! function_exists( 'is_email' ) ) {
        function is_email( $e ) {
                return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL );
        }
}
if ( ! function_exists( 'wp_generate_password' ) ) {
        function wp_generate_password( $len = 12, $special = true, $extra = false ) {
                return substr( str_repeat( 'aB3$xY7z', 8 ), 0, $len );
        }
}
if ( ! function_exists( 'is_wp_error' ) ) {
        function is_wp_error( $thing ) {
                return $thing instanceof WP_Error;
        }
}

if ( ! class_exists( 'WP_Error' ) ) {
        class WP_Error {
                public $code;
                public $message;
                public function __construct( $code = '', $message = '', $data = array() ) {
                        $this->code    = $code;
                        $this->message = $message;
                }
                public function get_error_message() {
                        return $this->message;
                }
        }
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
        class WP_REST_Response {
                public $data;
                public $status;
                public function __construct( $data = null, $status = 200 ) {
                        $this->data   = $data;
                        $this->status = $status;
                }
                public function get_data() {
                        return $this->data;
                }
                public function get_status() {
                        return $this->status;
                }
        }
}

/** Minimal WP_REST_Request double carrying a JSON body + route. */
class MQ_User_Request {
        private $params;
        private $route;
        public function __construct( array $params, $route = '/marqira/v1/users/list' ) {
                $this->params = $params;
                $this->route  = $route;
        }
        public function get_json_params() {
                return $this->params;
        }
        public function get_route() {
                return $this->route;
        }
}

// ---------------------------------------------------------------------------
// In-memory WordPress user + post store
// ---------------------------------------------------------------------------
$GLOBALS['__mq_users']     = array();
$GLOBALS['__mq_next_uid']  = 1;
$GLOBALS['__mq_user_meta'] = array();
$GLOBALS['__mq_posts']     = array(); // post_id => author_id

class WP_User {
        public $ID;
        public $user_login;
        public $user_email;
        public $display_name;
        public $user_registered;
        public $user_url;
        public $roles;
        public function __construct( array $row ) {
                $this->ID              = (int) $row['ID'];
                $this->user_login      = (string) $row['user_login'];
                $this->user_email      = (string) $row['user_email'];
                $this->display_name    = (string) $row['display_name'];
                $this->user_registered = (string) $row['user_registered'];
                $this->user_url        = (string) ( $row['user_url'] ?? '' );
                $this->roles           = array_values( (array) $row['roles'] );
        }
}

class WP_User_Query {
        private $results = array();
        private $total   = 0;
        public function __construct( $args ) {
                $rows = array_values( $GLOBALS['__mq_users'] );

                // Role filter.
                if ( ! empty( $args['role'] ) ) {
                        $rows = array_values( array_filter( $rows, function ( $r ) use ( $args ) {
                                return in_array( $args['role'], (array) $r['roles'], true );
                        } ) );
                }
                // Exclude.
                if ( ! empty( $args['exclude'] ) ) {
                        $ex   = (array) $args['exclude'];
                        $rows = array_values( array_filter( $rows, function ( $r ) use ( $ex ) {
                                return ! in_array( (int) $r['ID'], array_map( 'intval', $ex ), true );
                        } ) );
                }
                // Substring search across identity columns.
                if ( ! empty( $args['search'] ) ) {
                        $needle = trim( (string) $args['search'], '*' );
                        $rows   = array_values( array_filter( $rows, function ( $r ) use ( $needle ) {
                                foreach ( array( 'user_login', 'user_email', 'display_name' ) as $col ) {
                                        if ( false !== stripos( (string) $r[ $col ], $needle ) ) {
                                                return true;
                                        }
                                }
                                return false;
                        } ) );
                }

                $this->total = count( $rows );

                // Pagination.
                if ( ! empty( $args['number'] ) ) {
                        $number = (int) $args['number'];
                        $paged  = ! empty( $args['paged'] ) ? (int) $args['paged'] : 1;
                        $offset = ( $paged - 1 ) * $number;
                        $rows   = array_slice( $rows, $offset, $number );
                }

                $fields = $args['fields'] ?? 'all';
                foreach ( $rows as $r ) {
                        $this->results[] = ( 'ID' === $fields ) ? (int) $r['ID'] : new WP_User( $r );
                }
        }
        public function get_results() {
                return $this->results;
        }
        public function get_total() {
                return $this->total;
        }
}

function get_user_by( $field, $value ) {
        if ( 'id' === $field ) {
                $id = (int) $value;
                return isset( $GLOBALS['__mq_users'][ $id ] ) ? new WP_User( $GLOBALS['__mq_users'][ $id ] ) : false;
        }
        return false;
}
function username_exists( $login ) {
        foreach ( $GLOBALS['__mq_users'] as $r ) {
                if ( $r['user_login'] === $login ) {
                        return (int) $r['ID'];
                }
        }
        return false;
}
function email_exists( $email ) {
        foreach ( $GLOBALS['__mq_users'] as $r ) {
                if ( strtolower( $r['user_email'] ) === strtolower( $email ) ) {
                        return (int) $r['ID'];
                }
        }
        return false;
}
function wp_insert_user( $data ) {
        if ( empty( $data['user_login'] ) ) {
                return new WP_Error( 'empty_login', 'Empty login' );
        }
        $id = $GLOBALS['__mq_next_uid']++;
        $GLOBALS['__mq_users'][ $id ] = array(
                'ID'              => $id,
                'user_login'      => (string) $data['user_login'],
                'user_email'      => (string) $data['user_email'],
                'user_pass'       => (string) ( $data['user_pass'] ?? '' ),
                'display_name'    => (string) ( $data['display_name'] ?: $data['user_login'] ),
                'user_registered' => '2024-01-01 00:00:00',
                'user_url'        => (string) ( $data['user_url'] ?? '' ),
                'roles'           => array( (string) ( $data['role'] ?? 'subscriber' ) ),
        );
        return $id;
}
function wp_update_user( $data ) {
        $id = (int) ( $data['ID'] ?? 0 );
        if ( ! isset( $GLOBALS['__mq_users'][ $id ] ) ) {
                return new WP_Error( 'invalid_user', 'No user' );
        }
        $row = &$GLOBALS['__mq_users'][ $id ];
        foreach ( array( 'user_email', 'display_name', 'user_url', 'user_pass' ) as $k ) {
                if ( array_key_exists( $k, $data ) ) {
                        $row[ $k ] = (string) $data[ $k ];
                }
        }
        if ( array_key_exists( 'role', $data ) ) {
                $row['roles'] = array( (string) $data['role'] );
        }
        return $id;
}
function wp_delete_user( $id, $reassign = null ) {
        $id = (int) $id;
        if ( ! isset( $GLOBALS['__mq_users'][ $id ] ) ) {
                return false;
        }
        if ( null !== $reassign ) {
                foreach ( $GLOBALS['__mq_posts'] as $pid => $author ) {
                        if ( (int) $author === $id ) {
                                $GLOBALS['__mq_posts'][ $pid ] = (int) $reassign;
                        }
                }
        }
        unset( $GLOBALS['__mq_users'][ $id ] );
        return true;
}
function count_user_posts( $id ) {
        $id = (int) $id;
        $n  = 0;
        foreach ( $GLOBALS['__mq_posts'] as $author ) {
                if ( (int) $author === $id ) {
                        $n++;
                }
        }
        return $n;
}
function get_user_meta( $id, $key, $single = false ) {
        return $GLOBALS['__mq_user_meta'][ $id ][ $key ] ?? '';
}
function update_user_meta( $id, $key, $value ) {
        $GLOBALS['__mq_user_meta'][ $id ][ $key ] = $value;
        return true;
}

class MQ_Roles {
        public function get_names() {
                return array(
                        'administrator' => 'Administrator',
                        'editor'        => 'Editor',
                        'author'        => 'Author',
                        'contributor'   => 'Contributor',
                        'subscriber'    => 'Subscriber',
                        'shop_manager'  => 'Shop Manager', // custom role
                );
        }
}
function wp_roles() {
        static $r = null;
        if ( null === $r ) {
                $r = new MQ_Roles();
        }
        return $r;
}

// Seed one administrator so the site is never left admin-less.
$GLOBALS['__mq_users'][ $GLOBALS['__mq_next_uid'] ] = array(
        'ID'              => $GLOBALS['__mq_next_uid'],
        'user_login'      => 'owner',
        'user_email'      => 'owner@example.com',
        'user_pass'       => 'secret-hash',
        'display_name'    => 'Site Owner',
        'user_registered' => '2023-01-01 00:00:00',
        'user_url'        => '',
        'roles'           => array( 'administrator' ),
);
$admin_id = $GLOBALS['__mq_next_uid'];
$GLOBALS['__mq_next_uid']++;

require_once dirname( __DIR__ ) . '/includes/class-marqira-users.php';

/** Helper: call a handler and return the decoded response body. */
function mq_call( $method, array $params ) {
        $res = Marqira_Users::$method( new MQ_User_Request( $params ) );
        return array( 'status' => $res->get_status(), 'body' => $res->get_data() );
}

// ---------------------------------------------------------------------------
// Create
// ---------------------------------------------------------------------------
$r = mq_call( 'handle_create', array(
        'username' => 'jane',
        'email'    => 'jane@example.com',
        'role'     => 'editor',
        'password' => 'sup3rsecret!',
        'first_name' => 'Jane',
) );
mq_ok( 201 === $r['status'] && true === $r['body']['success'], 'create: returns 201 success' );
mq_ok( 'jane' === $r['body']['data']['username'] && in_array( 'editor', $r['body']['data']['roles'], true ), 'create: user has requested role' );
mq_ok( ! array_key_exists( 'user_pass', $r['body']['data'] ), 'create: never leaks user_pass' );
$jane_id = (int) $r['body']['data']['id'];

// Duplicate username.
$r = mq_call( 'handle_create', array( 'username' => 'jane', 'email' => 'other@example.com', 'role' => 'author' ) );
mq_ok( 409 === $r['status'] && 'username_exists' === $r['body']['error'], 'create: duplicate username rejected (409)' );

// Duplicate email.
$r = mq_call( 'handle_create', array( 'username' => 'jane2', 'email' => 'jane@example.com', 'role' => 'author' ) );
mq_ok( 409 === $r['status'] && 'email_exists' === $r['body']['error'], 'create: duplicate email rejected (409)' );

// Invalid email.
$r = mq_call( 'handle_create', array( 'username' => 'bad', 'email' => 'nope', 'role' => 'author' ) );
mq_ok( 422 === $r['status'] && 'invalid_email' === $r['body']['error'], 'create: invalid email rejected (422)' );

// Invalid role.
$r = mq_call( 'handle_create', array( 'username' => 'norole', 'email' => 'norole@example.com', 'role' => 'wizard' ) );
mq_ok( 422 === $r['status'] && 'invalid_role' === $r['body']['error'], 'create: unknown role rejected (422)' );

// Custom role is accepted.
$r = mq_call( 'handle_create', array( 'username' => 'shopper', 'email' => 'shop@example.com', 'role' => 'shop_manager' ) );
mq_ok( 201 === $r['status'] && in_array( 'shop_manager', $r['body']['data']['roles'], true ), 'create: custom role accepted' );

// Auto-generated password when none supplied (still created, no leak).
$r = mq_call( 'handle_create', array( 'username' => 'nopass', 'email' => 'nopass@example.com', 'role' => 'subscriber' ) );
mq_ok( 201 === $r['status'] && ! array_key_exists( 'user_pass', $r['body']['data'] ), 'create: auto-password path succeeds without leaking' );

// ---------------------------------------------------------------------------
// Idempotency (§9)
// ---------------------------------------------------------------------------
$before = count( $GLOBALS['__mq_users'] );
$idem   = array( 'username' => 'bulkuser', 'email' => 'bulk@example.com', 'role' => 'author', 'idempotency_key' => 'op-1|siteA|bulkuser' );
$r1     = mq_call( 'handle_create', $idem );
$r2     = mq_call( 'handle_create', $idem );
$after  = count( $GLOBALS['__mq_users'] );
mq_ok( 201 === $r1['status'], 'idempotent create: first call creates (201)' );
mq_ok( 200 === $r2['status'] && ! empty( $r2['body']['duplicate'] ), 'idempotent create: retry returns duplicate=true (200)' );
mq_ok( ( $after - $before ) === 1, 'idempotent create: retry does NOT create a second account' );

// ---------------------------------------------------------------------------
// List / search / role filter
// ---------------------------------------------------------------------------
$r = mq_call( 'handle_list', array( 'per_page' => 50 ) );
mq_ok( 200 === $r['status'] && $r['body']['meta']['total'] >= 5, 'list: returns users with meta' );
$has_pass = false;
foreach ( $r['body']['data'] as $u ) {
        if ( array_key_exists( 'user_pass', $u ) ) {
                $has_pass = true;
        }
}
mq_ok( ! $has_pass, 'list: no row leaks user_pass' );

$r = mq_call( 'handle_list', array( 'role' => 'editor' ) );
$all_editors = true;
foreach ( $r['body']['data'] as $u ) {
        if ( ! in_array( 'editor', $u['roles'], true ) ) {
                $all_editors = false;
        }
}
mq_ok( ! empty( $r['body']['data'] ) && $all_editors, 'list: role filter returns only that role' );

$r = mq_call( 'handle_list', array( 'search' => 'jane' ) );
mq_ok( 1 === count( $r['body']['data'] ) && 'jane' === $r['body']['data'][0]['username'], 'list: search matches by identity column' );

// ---------------------------------------------------------------------------
// Get single (detailed)
// ---------------------------------------------------------------------------
$r = mq_call( 'handle_get', array( 'id' => $jane_id ) );
mq_ok( 200 === $r['status'] && $jane_id === $r['body']['data']['id'], 'get: fetches the user' );
mq_ok( ! array_key_exists( 'user_pass', $r['body']['data'] ) && isset( $r['body']['data']['post_count'] ), 'get: detailed view without secrets' );

$r = mq_call( 'handle_get', array( 'id' => 99999 ) );
mq_ok( 404 === $r['status'], 'get: missing user returns 404' );

// ---------------------------------------------------------------------------
// Update profile / password / role
// ---------------------------------------------------------------------------
$r = mq_call( 'handle_update', array( 'id' => $jane_id, 'display_name' => 'Jane Doe', 'password' => 'newpass12345' ) );
mq_ok( 200 === $r['status'] && 'Jane Doe' === $r['body']['data']['display_name'], 'update: profile field changes' );
mq_ok( ! array_key_exists( 'user_pass', $r['body']['data'] ), 'update: password change never echoes the hash' );

$r = mq_call( 'handle_update', array( 'id' => $jane_id, 'role' => 'author' ) );
mq_ok( 200 === $r['status'] && in_array( 'author', $r['body']['data']['roles'], true ), 'update: role change applied' );

// ---------------------------------------------------------------------------
// Last-admin protection (§7)
// ---------------------------------------------------------------------------
$r = mq_call( 'handle_update', array( 'id' => $admin_id, 'role' => 'subscriber' ) );
mq_ok( 409 === $r['status'] && 'last_admin' === $r['body']['error'], 'update: cannot demote the last administrator' );

$r = mq_call( 'handle_delete', array( 'id' => $admin_id ) );
mq_ok( 409 === $r['status'] && 'last_admin' === $r['body']['error'], 'delete: cannot delete the last administrator' );

// ---------------------------------------------------------------------------
// Roles listing includes custom roles
// ---------------------------------------------------------------------------
$r = mq_call( 'handle_roles', array() );
$slugs = array_map( function ( $x ) {
        return $x['slug'];
}, $r['body']['data'] );
mq_ok( in_array( 'shop_manager', $slugs, true ) && in_array( 'administrator', $slugs, true ), 'roles: includes default AND custom roles' );

// ---------------------------------------------------------------------------
// Delete with content reassignment (§6)
// ---------------------------------------------------------------------------
// Give jane two posts, then delete-with-reassign to the admin.
$GLOBALS['__mq_posts'][101] = $jane_id;
$GLOBALS['__mq_posts'][102] = $jane_id;

// No reassign + content + no force => must ask (409).
$r = mq_call( 'handle_delete', array( 'id' => $jane_id ) );
mq_ok( 409 === $r['status'] && 'content_reassignment_required' === $r['body']['error'] && 2 === $r['body']['post_count'], 'delete: content owner requires a reassignment decision' );

// Reassign to the admin.
$r = mq_call( 'handle_delete', array( 'id' => $jane_id, 'reassign_to' => $admin_id ) );
mq_ok( 200 === $r['status'] && $admin_id === $r['body']['reassigned_to'], 'delete: reassignment succeeds' );
mq_ok( false === get_user_by( 'id', $jane_id ), 'delete: user actually removed' );
mq_ok( (int) $GLOBALS['__mq_posts'][101] === $admin_id && (int) $GLOBALS['__mq_posts'][102] === $admin_id, 'delete: content now belongs to the replacement user' );

// ---------------------------------------------------------------------------
// Reassign candidates exclude the target
// ---------------------------------------------------------------------------
$r = mq_call( 'handle_reassign_candidates', array( 'exclude' => $admin_id ) );
$ids = array_map( function ( $x ) {
        return (int) $x['id'];
}, $r['body']['data'] );
mq_ok( ! in_array( $admin_id, $ids, true ), 'reassign-candidates: excludes the user being deleted' );

echo "\n";
