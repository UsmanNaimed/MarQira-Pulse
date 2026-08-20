<?php
/**
 * REST controller for MarQira Connector WordPress user management.
 *
 * Registers signed `marqira/v1/users/*` endpoints so the MarQira control plane
 * can list, create, edit, delete and re-role WordPress users on this site
 * without an operator ever logging into wp-admin. Every endpoint is HMAC-signed
 * by the API and verified here with the SAME machinery used by the update-push
 * channel (Marqira_Hmac_Server) — see class-marqira-hmac-server.php.
 *
 * Design notes (§11 security, §12 architecture):
 *   - All operations are POST with a STABLE, permalink-independent sign path
 *     (the registered route path itself, e.g. "/marqira/v1/users/list"). IDs
 *     and filters travel in the signed JSON body, never in the URL, so the
 *     signature is portable across /wp-json, ?rest_route and subdirectories and
 *     there are no dynamic path segments to sign.
 *   - We use WordPress core user APIs (WP_User_Query, wp_insert_user,
 *     wp_update_user, wp_delete_user) rather than touching the DB directly.
 *   - Password hashes (user_pass) and other secrets are NEVER returned.
 *   - The last remaining administrator can never be deleted or demoted, which
 *     would lock the customer out of their own site.
 *   - Creation is idempotent via an optional idempotency_key so a retried bulk
 *     operation can never create duplicate accounts (§9).
 *
 * @package Marqira_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

/**
 * Class Marqira_Users
 */
class Marqira_Users {

        /**
         * REST namespace (shared with the other control-plane routes).
         */
        const NAMESPACE = 'marqira/v1';

        /**
         * Transient prefix used to remember idempotency keys for create.
         */
        const IDEM_PREFIX = 'marqira_user_idem_';

        /**
         * How long an idempotency key is remembered (24h). Longer than any realistic
         * bulk retry window so retries never create duplicate accounts.
         */
        const IDEM_TTL = 86400;

        /**
         * Register REST routes.
         *
         * @return void
         */
        public static function init() {
                add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        }

        /**
         * Register the signed user-management routes. Each uses its own stable sign
         * path (the route path), verified in authorize_signed().
         *
         * @return void
         */
        public static function register_routes() {
                $routes = array(
                        'list'                 => 'handle_list',
                        'get'                  => 'handle_get',
                        'create'               => 'handle_create',
                        'update'               => 'handle_update',
                        'delete'               => 'handle_delete',
                        'roles'                => 'handle_roles',
                        'reassign-candidates'  => 'handle_reassign_candidates',
                );

                foreach ( $routes as $slug => $callback ) {
                        register_rest_route(
                                self::NAMESPACE,
                                '/users/' . $slug,
                                array(
                                        'methods'             => 'POST',
                                        'callback'            => array( __CLASS__, $callback ),
                                        'permission_callback' => array( __CLASS__, 'authorize_signed' ),
                                )
                        );
                }
        }

        /**
         * Permission callback: verify the inbound HMAC signature against the stable
         * route path (no dynamic segments, so the route path IS the sign path).
         *
         * @param WP_REST_Request $request Request.
         * @return true|WP_Error
         */
        public static function authorize_signed( $request ) {
                if ( ! class_exists( 'Marqira_Hmac_Server' ) ) {
                        return new WP_Error( 'marqira_unavailable', 'Verifier unavailable.', array( 'status' => 500 ) );
                }
                // get_route() returns the concrete path, e.g. "/marqira/v1/users/list",
                // which the API signs verbatim.
                $sign_path = (string) $request->get_route();
                return Marqira_Hmac_Server::verify( $request, $sign_path );
        }

        /* --------------------------------------------------------------------- */
        /* Handlers                                                              */
        /* --------------------------------------------------------------------- */

        /**
         * List users with search, role filter, sorting and pagination (§3).
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response
         */
        public static function handle_list( $request ) {
                $p        = self::params( $request );
                $per_page = isset( $p['per_page'] ) ? (int) $p['per_page'] : 25;
                $per_page = max( 1, min( 100, $per_page ) );
                $page     = isset( $p['page'] ) ? max( 1, (int) $p['page'] ) : 1;
                $search   = isset( $p['search'] ) ? trim( (string) $p['search'] ) : '';
                $role     = isset( $p['role'] ) ? sanitize_key( (string) $p['role'] ) : '';
                $orderby  = isset( $p['orderby'] ) ? (string) $p['orderby'] : 'registered';
                $order    = isset( $p['order'] ) && strtoupper( (string) $p['order'] ) === 'ASC' ? 'ASC' : 'DESC';

                $allowed_orderby = array( 'ID', 'login', 'nicename', 'email', 'registered', 'display_name' );
                if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
                        $orderby = 'registered';
                }

                $args = array(
                        'number'  => $per_page,
                        'paged'   => $page,
                        'orderby' => $orderby,
                        'order'   => $order,
                        'fields'  => 'all',
                );

                if ( '' !== $role ) {
                        $args['role'] = $role;
                }

                if ( '' !== $search ) {
                        // Search across common identity columns. The leading/trailing '*'
                        // makes it a substring match.
                        $args['search']         = '*' . $search . '*';
                        $args['search_columns'] = array( 'user_login', 'user_email', 'display_name', 'user_nicename' );
                }

                $query = new WP_User_Query( $args );
                $users = $query->get_results();
                $total = (int) $query->get_total();

                $data = array();
                foreach ( (array) $users as $user ) {
                        $data[] = self::present_user( $user );
                }

                return self::ok(
                        array(
                                'data' => $data,
                                'meta' => array(
                                        'total'        => $total,
                                        'per_page'     => $per_page,
                                        'current_page' => $page,
                                        'last_page'    => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
                                ),
                        )
                );
        }

        /**
         * Fetch a single user (§12).
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response
         */
        public static function handle_get( $request ) {
                $p  = self::params( $request );
                $id = isset( $p['id'] ) ? (int) $p['id'] : 0;
                if ( $id <= 0 ) {
                        return self::err( 'invalid_id', 'A valid user id is required.', 422 );
                }

                $user = get_user_by( 'id', $id );
                if ( ! $user ) {
                        return self::err( 'not_found', 'User not found.', 404 );
                }

                return self::ok( array( 'data' => self::present_user( $user, true ) ) );
        }

        /**
         * Create a WordPress user (§4). Idempotent via idempotency_key (§9).
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response
         */
        public static function handle_create( $request ) {
                $p = self::params( $request );

                $idem_key = isset( $p['idempotency_key'] ) ? sanitize_text_field( (string) $p['idempotency_key'] ) : '';
                if ( '' !== $idem_key ) {
                        $existing = get_transient( self::IDEM_PREFIX . md5( $idem_key ) );
                        if ( is_array( $existing ) && ! empty( $existing['user_id'] ) ) {
                                $user = get_user_by( 'id', (int) $existing['user_id'] );
                                if ( $user ) {
                                        return self::ok(
                                                array(
                                                        'data'      => self::present_user( $user, true ),
                                                        'duplicate' => true,
                                                ),
                                                200
                                        );
                                }
                        }
                }

                $username = isset( $p['username'] ) ? sanitize_user( (string) $p['username'], true ) : '';
                $email    = isset( $p['email'] ) ? sanitize_email( (string) $p['email'] ) : '';
                $password = isset( $p['password'] ) ? (string) $p['password'] : '';
                $role     = isset( $p['role'] ) ? sanitize_key( (string) $p['role'] ) : get_option( 'default_role', 'subscriber' );

                if ( '' === $username ) {
                        return self::err( 'invalid_username', 'A username is required.', 422 );
                }
                if ( '' === $email || ! is_email( $email ) ) {
                        return self::err( 'invalid_email', 'A valid email address is required.', 422 );
                }
                if ( username_exists( $username ) ) {
                        return self::err( 'username_exists', 'That username already exists on this site.', 409 );
                }
                if ( email_exists( $email ) ) {
                        return self::err( 'email_exists', 'That email address is already in use on this site.', 409 );
                }
                if ( ! self::role_exists( $role ) ) {
                        return self::err( 'invalid_role', 'The selected role does not exist on this site.', 422 );
                }
                if ( '' === $password ) {
                        $password = wp_generate_password( 20, true, true );
                }

                $userdata = array(
                        'user_login'   => $username,
                        'user_email'   => $email,
                        'user_pass'    => $password,
                        'role'         => $role,
                        'first_name'   => isset( $p['first_name'] ) ? sanitize_text_field( (string) $p['first_name'] ) : '',
                        'last_name'    => isset( $p['last_name'] ) ? sanitize_text_field( (string) $p['last_name'] ) : '',
                        'display_name' => isset( $p['display_name'] ) ? sanitize_text_field( (string) $p['display_name'] ) : '',
                        'user_url'     => isset( $p['website'] ) ? esc_url_raw( (string) $p['website'] ) : '',
                        'description'  => isset( $p['bio'] ) ? sanitize_textarea_field( (string) $p['bio'] ) : '',
                );

                $user_id = wp_insert_user( $userdata );
                if ( is_wp_error( $user_id ) ) {
                        return self::err( 'create_failed', $user_id->get_error_message(), 422 );
                }

                self::apply_meta( (int) $user_id, $p );

                if ( '' !== $idem_key ) {
                        set_transient( self::IDEM_PREFIX . md5( $idem_key ), array( 'user_id' => (int) $user_id ), self::IDEM_TTL );
                }

                self::audit( 'user.created', (int) $user_id, array( 'role' => $role, 'username' => $username ) );

                $user = get_user_by( 'id', (int) $user_id );
                return self::ok( array( 'data' => self::present_user( $user, true ) ), 201 );
        }

        /**
         * Update an existing user (§5, §7). Handles profile fields, password, role.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response
         */
        public static function handle_update( $request ) {
                $p  = self::params( $request );
                $id = isset( $p['id'] ) ? (int) $p['id'] : 0;
                if ( $id <= 0 ) {
                        return self::err( 'invalid_id', 'A valid user id is required.', 422 );
                }

                $user = get_user_by( 'id', $id );
                if ( ! $user ) {
                        return self::err( 'not_found', 'User not found.', 404 );
                }

                $userdata = array( 'ID' => $id );
                $changed  = array();

                if ( array_key_exists( 'email', $p ) ) {
                        $email = sanitize_email( (string) $p['email'] );
                        if ( '' === $email || ! is_email( $email ) ) {
                                return self::err( 'invalid_email', 'A valid email address is required.', 422 );
                        }
                        $owner = email_exists( $email );
                        if ( $owner && (int) $owner !== $id ) {
                                return self::err( 'email_exists', 'That email address is already in use on this site.', 409 );
                        }
                        $userdata['user_email'] = $email;
                        $changed[]              = 'email';
                }

                foreach ( array(
                        'first_name'   => 'first_name',
                        'last_name'    => 'last_name',
                        'display_name' => 'display_name',
                ) as $field => $key ) {
                        if ( array_key_exists( $key, $p ) ) {
                                $userdata[ $field ] = sanitize_text_field( (string) $p[ $key ] );
                                $changed[]          = $field;
                        }
                }
                if ( array_key_exists( 'website', $p ) ) {
                        $userdata['user_url'] = esc_url_raw( (string) $p['website'] );
                        $changed[]            = 'website';
                }
                if ( array_key_exists( 'bio', $p ) ) {
                        $userdata['description'] = sanitize_textarea_field( (string) $p['bio'] );
                        $changed[]               = 'bio';
                }

                $password_changed = false;
                if ( ! empty( $p['password'] ) ) {
                        $userdata['user_pass'] = (string) $p['password'];
                        $password_changed      = true;
                }

                // Role change with last-admin protection (§7).
                $role_changed = false;
                if ( array_key_exists( 'role', $p ) ) {
                        $new_role = sanitize_key( (string) $p['role'] );
                        if ( ! self::role_exists( $new_role ) ) {
                                return self::err( 'invalid_role', 'The selected role does not exist on this site.', 422 );
                        }
                        $is_admin_now = in_array( 'administrator', (array) $user->roles, true );
                        if ( $is_admin_now && 'administrator' !== $new_role && self::admin_count() <= 1 ) {
                                return self::err(
                                        'last_admin',
                                        'This is the last administrator; change another user to administrator first to avoid locking yourself out.',
                                        409
                                );
                        }
                        $userdata['role'] = $new_role;
                        $role_changed     = true;
                }

                $result = wp_update_user( $userdata );
                if ( is_wp_error( $result ) ) {
                        return self::err( 'update_failed', $result->get_error_message(), 422 );
                }

                self::apply_meta( $id, $p );

                if ( $password_changed ) {
                        self::audit( 'user.password_changed', $id, array() );
                }
                if ( $role_changed ) {
                        self::audit( 'user.role_changed', $id, array( 'role' => $userdata['role'] ) );
                }
                self::audit( 'user.updated', $id, array( 'fields' => array_values( array_unique( $changed ) ) ) );

                $fresh = get_user_by( 'id', $id );
                return self::ok( array( 'data' => self::present_user( $fresh, true ) ) );
        }

        /**
         * Delete a user with optional content reassignment (§6). Uses WordPress's
         * native deletion/reassignment so ownership records stay consistent.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response
         */
        public static function handle_delete( $request ) {
                $p  = self::params( $request );
                $id = isset( $p['id'] ) ? (int) $p['id'] : 0;
                if ( $id <= 0 ) {
                        return self::err( 'invalid_id', 'A valid user id is required.', 422 );
                }

                $user = get_user_by( 'id', $id );
                if ( ! $user ) {
                        return self::err( 'not_found', 'User not found.', 404 );
                }

                // Never delete the last administrator (§7).
                if ( in_array( 'administrator', (array) $user->roles, true ) && self::admin_count() <= 1 ) {
                        return self::err(
                                'last_admin',
                                'This is the last administrator and cannot be deleted; it would lock you out of the site.',
                                409
                        );
                }

                $reassign_to = isset( $p['reassign_to'] ) ? (int) $p['reassign_to'] : 0;
                $post_count  = self::user_post_count( $id );

                if ( $reassign_to > 0 ) {
                        if ( $reassign_to === $id ) {
                                return self::err( 'invalid_reassign', 'Content cannot be reassigned to the user being deleted.', 422 );
                        }
                        $target = get_user_by( 'id', $reassign_to );
                        if ( ! $target ) {
                                return self::err( 'invalid_reassign', 'The selected replacement user does not exist.', 422 );
                        }
                } elseif ( $post_count > 0 && empty( $p['force_delete'] ) ) {
                        // Content exists but no reassignment target and no explicit force —
                        // refuse so the customer must make an informed choice (§6).
                        return self::err(
                                'content_reassignment_required',
                                'This user owns content. Choose a user to reassign it to, or confirm permanent deletion.',
                                409,
                                array( 'post_count' => $post_count )
                        );
                }

                self::require_user_admin();
                if ( ! function_exists( 'wp_delete_user' ) ) {
                        return self::err( 'unavailable', 'User deletion is unavailable on this site.', 500 );
                }

                $deleted = $reassign_to > 0 ? wp_delete_user( $id, $reassign_to ) : wp_delete_user( $id );
                if ( ! $deleted ) {
                        return self::err( 'delete_failed', 'WordPress could not delete this user.', 422 );
                }

                self::audit(
                        'user.deleted',
                        $id,
                        array(
                                'reassigned_to' => $reassign_to > 0 ? $reassign_to : null,
                                'post_count'    => $post_count,
                        )
                );
                if ( $reassign_to > 0 && $post_count > 0 ) {
                        self::audit( 'user.content_reassigned', $reassign_to, array( 'from_user' => $id, 'post_count' => $post_count ) );
                }

                return self::ok(
                        array(
                                'deleted'       => true,
                                'reassigned_to' => $reassign_to > 0 ? $reassign_to : null,
                                'post_count'    => $post_count,
                        )
                );
        }

        /**
         * List the roles available on THIS site (§3, §7). Includes custom roles.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response
         */
        public static function handle_roles( $request ) {
                unset( $request );
                $roles = array();
                if ( function_exists( 'wp_roles' ) ) {
                        $wp_roles = wp_roles();
                        $names    = $wp_roles->get_names(); // slug => display name (translated).
                        foreach ( $names as $slug => $name ) {
                                $roles[] = array(
                                        'slug' => (string) $slug,
                                        'name' => (string) $name,
                                );
                        }
                }

                return self::ok( array( 'data' => $roles ) );
        }

        /**
         * Eligible users to receive reassigned content (§6): everyone except the
         * user being deleted. Supports search for large sites.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response
         */
        public static function handle_reassign_candidates( $request ) {
                $p       = self::params( $request );
                $exclude = isset( $p['exclude'] ) ? (int) $p['exclude'] : 0;
                $search  = isset( $p['search'] ) ? trim( (string) $p['search'] ) : '';

                $args = array(
                        'number'  => 50,
                        'orderby' => 'display_name',
                        'order'   => 'ASC',
                        'fields'  => 'all',
                );
                if ( $exclude > 0 ) {
                        $args['exclude'] = array( $exclude );
                }
                if ( '' !== $search ) {
                        $args['search']         = '*' . $search . '*';
                        $args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
                }

                $query = new WP_User_Query( $args );
                $data  = array();
                foreach ( (array) $query->get_results() as $user ) {
                        $data[] = array(
                                'id'           => (int) $user->ID,
                                'display_name' => (string) $user->display_name,
                                'user_login'   => (string) $user->user_login,
                                'user_email'   => (string) $user->user_email,
                                'roles'        => array_values( (array) $user->roles ),
                        );
                }

                return self::ok( array( 'data' => $data ) );
        }

        /* --------------------------------------------------------------------- */
        /* Helpers                                                              */
        /* --------------------------------------------------------------------- */

        /**
         * Decode the JSON body into an array.
         *
         * @param WP_REST_Request $request Request.
         * @return array<string,mixed>
         */
        private static function params( $request ) {
                $params = $request->get_json_params();
                return is_array( $params ) ? $params : array();
        }

        /**
         * Present a user for output. NEVER includes user_pass or other secrets.
         *
         * @param WP_User $user     User object.
         * @param bool    $detailed Include extended profile fields.
         * @return array<string,mixed>
         */
        private static function present_user( $user, $detailed = false ) {
                $roles      = array_values( (array) $user->roles );
                $role_names = array();
                if ( function_exists( 'wp_roles' ) ) {
                        $names = wp_roles()->get_names();
                        foreach ( $roles as $slug ) {
                                $role_names[] = isset( $names[ $slug ] ) ? (string) $names[ $slug ] : (string) $slug;
                        }
                } else {
                        $role_names = $roles;
                }

                $data = array(
                        'id'              => (int) $user->ID,
                        'username'        => (string) $user->user_login,
                        'display_name'    => (string) $user->display_name,
                        'email'           => (string) $user->user_email,
                        'roles'           => $roles,
                        'role_names'      => $role_names,
                        'registered_at'   => (string) $user->user_registered,
                        'website'         => (string) $user->user_url,
                );

                if ( $detailed ) {
                        $data['first_name'] = (string) get_user_meta( $user->ID, 'first_name', true );
                        $data['last_name']  = (string) get_user_meta( $user->ID, 'last_name', true );
                        $data['bio']        = (string) get_user_meta( $user->ID, 'description', true );
                        $data['post_count'] = self::user_post_count( (int) $user->ID );
                }

                return $data;
        }

        /**
         * Apply supported user meta fields (extensible, §4/§5).
         *
         * @param int                 $user_id User id.
         * @param array<string,mixed> $p       Request params.
         * @return void
         */
        private static function apply_meta( $user_id, $p ) {
                if ( isset( $p['meta'] ) && is_array( $p['meta'] ) ) {
                        $allowed = array( 'nickname', 'twitter', 'facebook', 'instagram' );
                        foreach ( $p['meta'] as $key => $value ) {
                                $key = sanitize_key( (string) $key );
                                if ( in_array( $key, $allowed, true ) ) {
                                        update_user_meta( $user_id, $key, sanitize_text_field( (string) $value ) );
                                }
                        }
                }
        }

        /**
         * Whether a role slug exists on this site (default OR custom).
         *
         * @param string $role Role slug.
         * @return bool
         */
        private static function role_exists( $role ) {
                if ( '' === $role ) {
                        return false;
                }
                if ( function_exists( 'wp_roles' ) ) {
                        return in_array( $role, array_keys( wp_roles()->get_names() ), true );
                }
                return false;
        }

        /**
         * Count administrators on the site (last-admin protection).
         *
         * @return int
         */
        private static function admin_count() {
                $query = new WP_User_Query(
                        array(
                                'role'   => 'administrator',
                                'fields' => 'ID',
                                'number' => 2, // We only need to know whether there is >1.
                        )
                );
                return (int) $query->get_total();
        }

        /**
         * Number of posts owned by a user.
         *
         * @param int $user_id User id.
         * @return int
         */
        private static function user_post_count( $user_id ) {
                if ( function_exists( 'count_user_posts' ) ) {
                        return (int) count_user_posts( $user_id );
                }
                return 0;
        }

        /**
         * Ensure wp-admin user functions (wp_delete_user) are loaded.
         *
         * @return void
         */
        private static function require_user_admin() {
                if ( ! function_exists( 'wp_delete_user' ) && defined( 'ABSPATH' ) ) {
                        $file = ABSPATH . 'wp-admin/includes/user.php';
                        if ( file_exists( $file ) ) {
                                require_once $file;
                        }
                }
        }

        /**
         * Record an audit-log entry (§13). Never logs passwords.
         *
         * @param string              $event   Event slug.
         * @param int                 $user_id Target user id.
         * @param array<string,mixed> $meta    Extra metadata.
         * @return void
         */
        private static function audit( $event, $user_id, $meta = array() ) {
                if ( class_exists( 'Marqira_Logger' ) ) {
                        $detail = array_merge(
                                array( 'target_user_id' => (int) $user_id ),
                                is_array( $meta ) ? $meta : array()
                        );
                        // Logger::log stores a plain string message; encode metadata as JSON.
                        // Passwords are never included here (§13).
                        $message = wp_json_encode( $detail );
                        Marqira_Logger::log( $event, is_string( $message ) ? $message : '', 'info' );
                }
        }

        /**
         * Success response.
         *
         * @param array<string,mixed> $payload Body.
         * @param int                 $status  HTTP status.
         * @return WP_REST_Response
         */
        private static function ok( $payload, $status = 200 ) {
                $payload = array_merge( array( 'success' => true ), $payload );
                return new WP_REST_Response( $payload, $status );
        }

        /**
         * Error response with a stable machine code and HTTP status.
         *
         * @param string              $code   Machine code.
         * @param string              $message Human message.
         * @param int                 $status HTTP status.
         * @param array<string,mixed> $extra  Extra fields.
         * @return WP_REST_Response
         */
        private static function err( $code, $message, $status = 422, $extra = array() ) {
                $payload = array_merge(
                        array(
                                'success' => false,
                                'error'   => $code,
                                'message' => $message,
                        ),
                        is_array( $extra ) ? $extra : array()
                );
                return new WP_REST_Response( $payload, $status );
        }
}
