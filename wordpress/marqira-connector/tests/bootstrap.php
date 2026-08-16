<?php
/**
 * Minimal WordPress stubs + plugin class loader for the standalone connector
 * test scripts. These tests exercise pure PHP logic (crypto, the Cloudflare
 * range fallback, the HMAC canonical string) without a full WordPress install.
 *
 * Run: php tests/run.php   (from the plugin root)
 *
 * @package Marqira_Connector
 */

// ---------------------------------------------------------------------------
// Core constants
// ---------------------------------------------------------------------------
if ( ! defined( 'ABSPATH' ) ) {
        define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
        define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
        define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'MARQIRA_CONNECTOR_VERSION' ) ) {
        define( 'MARQIRA_CONNECTOR_VERSION', '1.1.1' );
}

// WordPress salts (used by the crypto fallback key derivation).
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY' ) as $salt_const ) {
        if ( ! defined( $salt_const ) ) {
                define( $salt_const, 'test-salt-' . $salt_const . '-0123456789abcdef' );
        }
}

// ---------------------------------------------------------------------------
// In-memory option / transient stores
// ---------------------------------------------------------------------------
$GLOBALS['__mq_options']    = array();
$GLOBALS['__mq_transients'] = array();

if ( ! function_exists( 'get_option' ) ) {
        function get_option( $name, $default = false ) {
                return array_key_exists( $name, $GLOBALS['__mq_options'] )
                        ? $GLOBALS['__mq_options'][ $name ]
                        : $default;
        }
}
if ( ! function_exists( 'update_option' ) ) {
        function update_option( $name, $value ) {
                $GLOBALS['__mq_options'][ $name ] = $value;
                return true;
        }
}
if ( ! function_exists( 'delete_option' ) ) {
        function delete_option( $name ) {
                unset( $GLOBALS['__mq_options'][ $name ] );
                return true;
        }
}
if ( ! function_exists( 'get_transient' ) ) {
        function get_transient( $name ) {
                return array_key_exists( $name, $GLOBALS['__mq_transients'] )
                        ? $GLOBALS['__mq_transients'][ $name ]
                        : false;
        }
}
if ( ! function_exists( 'set_transient' ) ) {
        function set_transient( $name, $value, $ttl = 0 ) {
                $GLOBALS['__mq_transients'][ $name ] = $value;
                return true;
        }
}
if ( ! function_exists( 'delete_transient' ) ) {
        function delete_transient( $name ) {
                unset( $GLOBALS['__mq_transients'][ $name ] );
                return true;
        }
}

// ---------------------------------------------------------------------------
// Misc WP helpers
// ---------------------------------------------------------------------------
if ( ! function_exists( 'sanitize_text_field' ) ) {
        function sanitize_text_field( $str ) {
                return is_string( $str ) ? trim( $str ) : $str;
        }
}
if ( ! function_exists( 'wp_unslash' ) ) {
        function wp_unslash( $value ) {
                return is_string( $value ) ? stripslashes( $value ) : $value;
        }
}
if ( ! function_exists( '__' ) ) {
        function __( $text, $domain = 'default' ) {
                return $text;
        }
}

// Minimal logger stub so classes that call it do not fatal.
if ( ! class_exists( 'Marqira_Logger' ) ) {
        class Marqira_Logger {
                public static function log( $event, $message = '', $level = 'info' ) {
                        // no-op in tests
                }
        }
}

// ---------------------------------------------------------------------------
// Load the plugin classes under test
// ---------------------------------------------------------------------------
$inc = dirname( __DIR__ ) . '/includes';
require_once $inc . '/class-marqira-crypto.php';
require_once $inc . '/class-marqira-ip-utils.php';
require_once $inc . '/class-marqira-cloudflare.php';
require_once $inc . '/class-marqira-config-fetcher.php';
require_once $inc . '/class-marqira-enrollment.php';
require_once $inc . '/class-marqira-hmac-client.php';

// ---------------------------------------------------------------------------
// Tiny assertion helpers
// ---------------------------------------------------------------------------
$GLOBALS['__mq_pass'] = 0;
$GLOBALS['__mq_fail'] = 0;

function mq_ok( $cond, $label ) {
        if ( $cond ) {
                $GLOBALS['__mq_pass']++;
                echo "  \xE2\x9C\x93 {$label}\n";
        } else {
                $GLOBALS['__mq_fail']++;
                echo "  \xE2\x9C\x97 FAIL: {$label}\n";
        }
}
