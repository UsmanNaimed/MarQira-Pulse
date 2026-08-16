<?php
/**
 * Admin settings controller.
 *
 * @package Marqira_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

/**
 * Class Marqira_Admin
 *
 * Registers the settings page and handles saving settings and the
 * "Test Configuration" action.
 *
 * Settings are saved through a self-handled admin-post.php action rather
 * than the options.php Settings API. This avoids failures on locked-down
 * shared hosts (e.g. mod_security rules that block options.php POSTs
 * containing IP-address payloads) and gives the plugin full control over
 * validation and redirects.
 */
class Marqira_Admin {

        /**
         * Settings page hook suffix.
         *
         * @var string
         */
        private $page_hook = '';

        /**
         * Constructor. Registers admin hooks.
         */
        public function __construct() {
                add_action( 'admin_menu',                            array( $this, 'add_menu_page' ) );
                add_action( 'admin_post_marqira_save_settings',      array( $this, 'handle_save_settings' ) );
                add_action( 'admin_post_marqira_test_configuration', array( $this, 'handle_test_configuration' ) );
                add_action( 'admin_post_marqira_enroll',             array( $this, 'handle_enrollment' ) );
                add_action( 'admin_post_marqira_disconnect',         array( $this, 'handle_disconnect' ) );
                add_action( 'admin_enqueue_scripts',                 array( $this, 'enqueue_scripts' ) );
        }

        /**
         * Add the settings submenu page under Settings.
         *
         * @return void
         */
        public function add_menu_page() {
                $this->page_hook = add_options_page(
                        __( 'MarQira Connector', 'marqira-connector' ),
                        __( 'MarQira Connector', 'marqira-connector' ),
                        'manage_options',
                        'marqira-connector',
                        array( $this, 'render_settings_page' )
                );
        }

        /**
         * Build the URL of the settings page.
         *
         * @param array $args Optional query args to append.
         * @return string
         */
        private function settings_url( $args = array() ) {
                $url = admin_url( 'options-general.php?page=marqira-connector' );
                if ( ! empty( $args ) ) {
                        $url = add_query_arg( $args, $url );
                }
                return $url;
        }

        /**
         * Handle the settings save request (admin-post.php).
         *
         * @return void
         */
        public function handle_save_settings() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'marqira-connector' ) );
                }

                check_admin_referer( 'marqira_save_settings', 'marqira_settings_nonce' );

                $defaults = marqira_connector_default_settings();
                $output   = array();

                $output['protection_enabled']       = ! empty( $_POST['protection_enabled'] );
                $output['rest_restriction_enabled'] = ! empty( $_POST['rest_restriction_enabled'] );

                $raw_ips = isset( $_POST['allowed_ips'] )
                        ? sanitize_textarea_field( wp_unslash( $_POST['allowed_ips'] ) )
                        : '';

                $parsed   = Marqira_IP_Utils::parse_ip_list( $raw_ips );
                $rejected = $this->collect_rejected_entries( $raw_ips );

                if ( empty( $parsed ) ) {
                        $parsed = $defaults['allowed_ips'];
                        $this->store_notice( 'warning', __( 'No valid IPs were provided. The default allowed IP list has been restored.', 'marqira-connector' ) );
                }

                $output['allowed_ips'] = $parsed;

                update_option( 'marqira_connector_settings', $output );

                if ( ! empty( $rejected ) ) {
                        foreach ( $rejected as $bad ) {
                                // Log the rejected entry — never log the actual value with a secret in it.
                                Marqira_Logger::log(
                                        'settings_invalid_ip',
                                        'Rejected invalid IP/CIDR entry during settings save.',
                                        'warning'
                                );
                        }
                        $this->store_notice(
                                'warning',
                                sprintf(
                                        /* translators: %s: comma-separated list of rejected entries */
                                        __( 'Some entries were rejected as invalid and were not saved: %s', 'marqira-connector' ),
                                        implode( ', ', array_map( 'esc_html', $rejected ) )
                                )
                        );
                }

                Marqira_Logger::log_settings_saved();
                $this->store_notice( 'success', __( 'Settings saved.', 'marqira-connector' ) );

                wp_safe_redirect( $this->settings_url() );
                exit;
        }

        /**
         * Identify submitted entries that are not valid IPs or CIDRs.
         *
         * @param string $raw_ips Raw textarea value.
         * @return array
         */
        private function collect_rejected_entries( $raw_ips ) {
                $rejected = array();
                $lines    = preg_split( '/[\r\n,]+/', (string) $raw_ips );

                if ( ! is_array( $lines ) ) {
                        return $rejected;
                }

                foreach ( $lines as $line ) {
                        $line = trim( $line );
                        if ( '' === $line || '#' === $line[0] ) {
                                continue;
                        }
                        $hash = strpos( $line, '#' );
                        if ( false !== $hash ) {
                                $line = trim( substr( $line, 0, $hash ) );
                        }
                        if ( '' === $line ) {
                                continue;
                        }

                        $is_valid = ( false !== strpos( $line, '/' ) )
                                ? Marqira_IP_Utils::is_valid_cidr( $line )
                                : ( false !== Marqira_IP_Utils::normalize( $line ) );

                        if ( ! $is_valid ) {
                                $rejected[] = $line;
                        }
                }

                return array_values( array_unique( $rejected ) );
        }

        /**
         * Store a transient admin notice for the current user.
         *
         * @param string $type    Notice type (success|warning|error).
         * @param string $message Notice message.
         * @return void
         */
        private function store_notice( $type, $message ) {
                $key      = 'marqira_notices_' . get_current_user_id();
                $existing = get_transient( $key );
                if ( ! is_array( $existing ) ) {
                        $existing = array();
                }
                $existing[] = array(
                        'type'    => $type,
                        'message' => $message,
                );
                set_transient( $key, $existing, 60 );
        }

        /**
         * Retrieve and clear stored admin notices for the current user.
         *
         * @return array
         */
        private function get_notices() {
                $key      = 'marqira_notices_' . get_current_user_id();
                $existing = get_transient( $key );
                delete_transient( $key );
                return is_array( $existing ) ? $existing : array();
        }

        /**
         * Enqueue admin styles only on the plugin settings page.
         *
         * @param string $hook Current admin page hook.
         * @return void
         */
        public function enqueue_scripts( $hook ) {
                if ( empty( $this->page_hook ) || $hook !== $this->page_hook ) {
                        return;
                }

                wp_enqueue_style(
                        'marqira-connector-admin',
                        MARQIRA_CONNECTOR_PLUGIN_URL . 'assets/admin.css',
                        array(),
                        MARQIRA_CONNECTOR_VERSION
                );
        }

        /**
         * Handle the "Test Configuration" request (admin-post.php).
         *
         * @return void
         */
        public function handle_test_configuration() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'marqira-connector' ) );
                }

                check_admin_referer( 'marqira_test_configuration', 'marqira_test_nonce' );

                $settings    = marqira_connector_get_settings();
                $client      = Marqira_Cloudflare::get_real_client_ip();
                $client_ip   = isset( $client['ip'] ) ? $client['ip'] : '';
                $allowed_ips = is_array( $settings['allowed_ips'] ) ? $settings['allowed_ips'] : array();

                $would_allow = ( '' !== $client_ip )
                        && Marqira_IP_Utils::ip_in_list( $client_ip, $allowed_ips );

                $result = array(
                        'ip'          => $client_ip,
                        'source'      => isset( $client['source'] ) ? $client['source'] : 'REMOTE_ADDR',
                        'cloudflare'  => ! empty( $client['cloudflare'] ),
                        'would_allow' => $would_allow,
                        'protection'  => (bool) $settings['protection_enabled'],
                );

                set_transient( 'marqira_test_result_' . get_current_user_id(), $result, 60 );

                wp_safe_redirect( $this->settings_url( array( 'tested' => '1' ) ) );
                exit;
        }

        /**
         * Render the settings page.
         *
         * @return void
         */
        public function render_settings_page() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'marqira-connector' ) );
                }

                $settings    = marqira_connector_get_settings();
                $diagnostics = Marqira_Diagnostics::get_all();
                $notices     = $this->get_notices();
                $recent_logs = class_exists( 'Marqira_Logger' ) ? Marqira_Logger::get_recent( 20 ) : array();

                $test_result = null;
                $test_key    = 'marqira_test_result_' . get_current_user_id();
                $stored_test = get_transient( $test_key );
                if ( is_array( $stored_test ) ) {
                        $test_result = $stored_test;
                        delete_transient( $test_key );
                }

                $save_url = admin_url( 'admin-post.php' );

                // Phase 4: Enrollment data
                $enrolled         = Marqira_Enrollment::is_enrolled();
                $credentials      = Marqira_Enrollment::get_credentials();
                $last_heartbeat   = Marqira_Heartbeat::get_last_heartbeat_sent();

                require MARQIRA_CONNECTOR_PLUGIN_DIR . 'admin/views/settings-page.php';
        }

        /**
         * Handle enrollment request (admin-post.php).
         *
         * Phase 4.
         *
         * @return void
         */
        public function handle_enrollment() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'marqira-connector' ) );
                }

                check_admin_referer( 'marqira_enroll', 'marqira_enroll_nonce' );

                $token = isset( $_POST['enrollment_token'] )
                        ? sanitize_text_field( wp_unslash( $_POST['enrollment_token'] ) )
                        : '';

                if ( empty( $token ) ) {
                        $this->store_notice( 'error', __( 'Please enter an enrollment token.', 'marqira-connector' ) );
                        wp_safe_redirect( $this->settings_url() );
                        exit;
                }

                $result = Marqira_Enrollment::enroll( $token );

                if ( is_wp_error( $result ) ) {
                        $this->store_notice( 'error', sprintf(
                                __( 'Enrollment failed: %s', 'marqira-connector' ),
                                $result->get_error_message()
                        ) );
                } else {
                        $this->store_notice( 'success', __( 'Site enrolled successfully! Heartbeats will begin shortly.', 'marqira-connector' ) );
                        
                        // Trigger first heartbeat immediately
                        if ( class_exists( 'Marqira_Heartbeat' ) ) {
                                Marqira_Heartbeat::send_heartbeat();
                        }
                }

                wp_safe_redirect( $this->settings_url() );
                exit;
        }

        /**
         * Handle disconnect request (admin-post.php).
         *
         * Phase 4.
         *
         * @return void
         */
        public function handle_disconnect() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'marqira-connector' ) );
                }

                check_admin_referer( 'marqira_disconnect', 'marqira_disconnect_nonce' );

                Marqira_Enrollment::disconnect();

                $this->store_notice( 'success', __( 'Site disconnected from MarQira.', 'marqira-connector' ) );

                wp_safe_redirect( $this->settings_url() );
                exit;
        }
}
