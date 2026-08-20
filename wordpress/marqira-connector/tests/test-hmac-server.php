<?php
/**
 * Tests for Marqira_Hmac_Server — the inbound (API -> site) signed-request
 * verifier that powers immediate "update now" pushes.
 *
 * Verifies: a correctly signed request passes; tampered signature, wrong site,
 * wrong kid, expired timestamp, and replayed nonce all fail; and that the
 * canonical string is bound to the fixed logical path (not the request URI).
 *
 * @package Marqira_Connector
 */

require __DIR__ . '/bootstrap.php';

if ( ! function_exists( 'wp_json_encode' ) ) {
        function wp_json_encode( $data ) {
                return json_encode( $data );
        }
}

// ---------------------------------------------------------------------------
// Extra stubs needed by the HMAC server (WP_Error + a REST request double).
// ---------------------------------------------------------------------------
if ( ! class_exists( 'WP_Error' ) ) {
        class WP_Error {
                public $code;
                public $message;
                public $data;
                public function __construct( $code = '', $message = '', $data = array() ) {
                        $this->code    = $code;
                        $this->message = $message;
                        $this->data    = $data;
                }
                public function get_error_code() {
                        return $this->code;
                }
        }
}

/**
 * Minimal stand-in for WP_REST_Request. Header lookups use the same
 * lowercase-underscore keys WordPress normalises to (e.g. "x_marqira_site").
 */
class MQ_Fake_Request {
        private $headers;
        private $method;
        private $body;

        public function __construct( array $headers, $method, $body ) {
                $this->headers = $headers;
                $this->method  = $method;
                $this->body    = $body;
        }
        public function get_header( $key ) {
                return isset( $this->headers[ $key ] ) ? $this->headers[ $key ] : null;
        }
        public function get_method() {
                return $this->method;
        }
        public function get_body() {
                return $this->body;
        }
}

require_once dirname( __DIR__ ) . '/includes/class-marqira-hmac-server.php';

const SIGN_PATH = '/marqira/v1/execute-update';

// ---------------------------------------------------------------------------
// Enroll a fake site so get_credentials() returns known values.
// ---------------------------------------------------------------------------
$site_uuid   = 'test-uuid-1234-5678';
$site_secret = base64_encode( random_bytes( 32 ) );
$kid         = 'abcd1234';

$creds = wp_json_encode(
        array(
                'site_uuid'   => $site_uuid,
                'site_secret' => $site_secret,
                'kid'         => $kid,
                'api_url'     => 'https://api.marqira.test',
        )
);
update_option( Marqira_Enrollment::CREDENTIALS_OPTION, Marqira_Crypto::encrypt( $creds ) );

// Sanity: credentials round-trip.
$loaded = Marqira_Enrollment::get_credentials();
mq_ok( is_array( $loaded ) && $loaded['site_uuid'] === $site_uuid, 'fake credentials load correctly' );

/**
 * Build a validly signed request for the execute-update push.
 */
function mq_sign_request( $method, $sign_path, $body, $site_uuid, $site_secret, $kid, $ts = null, $nonce = null ) {
        $ts    = null === $ts ? (string) time() : (string) $ts;
        $nonce = null === $nonce ? bin2hex( random_bytes( 8 ) ) : $nonce;

        $canonical = implode(
                "\n",
                array( strtoupper( $method ), $sign_path, '', $ts, $nonce, hash( 'sha256', $body ) )
        );
        $sig = hash_hmac( 'sha256', $canonical, $site_secret );

        $headers = array(
                'x_marqira_site'      => $site_uuid,
                'x_marqira_timestamp' => $ts,
                'x_marqira_nonce'     => $nonce,
                'x_marqira_kid'       => $kid,
                'x_marqira_signature' => $sig,
        );

        return new MQ_Fake_Request( $headers, $method, $body );
}

$body = wp_json_encode( array( 'type' => 'update_plugin', 'target_version' => '1.2.11', 'command_id' => 'cmd-1' ) );

// 1. Happy path — a correctly signed request verifies.
$req = mq_sign_request( 'POST', SIGN_PATH, $body, $site_uuid, $site_secret, $kid );
$res = Marqira_Hmac_Server::verify( $req, SIGN_PATH );
mq_ok( true === $res, 'valid signed request verifies' );

// 2. Tampered body -> signature no longer matches.
$req2  = mq_sign_request( 'POST', SIGN_PATH, $body, $site_uuid, $site_secret, $kid );
$bad   = new MQ_Fake_Request(
        array(
                'x_marqira_site'      => $req2->get_header( 'x_marqira_site' ),
                'x_marqira_timestamp' => $req2->get_header( 'x_marqira_timestamp' ),
                'x_marqira_nonce'     => $req2->get_header( 'x_marqira_nonce' ),
                'x_marqira_kid'       => $req2->get_header( 'x_marqira_kid' ),
                'x_marqira_signature' => $req2->get_header( 'x_marqira_signature' ),
        ),
        'POST',
        $body . 'tampered'
);
$res = Marqira_Hmac_Server::verify( $bad, SIGN_PATH );
mq_ok( $res instanceof WP_Error && 'marqira_bad_signature' === $res->get_error_code(), 'tampered body is rejected' );

// 3. Wrong site uuid in header.
$req = mq_sign_request( 'POST', SIGN_PATH, $body, 'someone-elses-uuid', $site_secret, $kid );
$res = Marqira_Hmac_Server::verify( $req, SIGN_PATH );
mq_ok( $res instanceof WP_Error && 'marqira_site_mismatch' === $res->get_error_code(), 'wrong site uuid is rejected' );

// 4. Wrong kid.
$req = mq_sign_request( 'POST', SIGN_PATH, $body, $site_uuid, $site_secret, 'wrongkid' );
$res = Marqira_Hmac_Server::verify( $req, SIGN_PATH );
mq_ok( $res instanceof WP_Error && 'marqira_bad_kid' === $res->get_error_code(), 'wrong kid is rejected' );

// 5. Expired timestamp (outside ±300s).
$req = mq_sign_request( 'POST', SIGN_PATH, $body, $site_uuid, $site_secret, $kid, time() - 4000 );
$res = Marqira_Hmac_Server::verify( $req, SIGN_PATH );
mq_ok( $res instanceof WP_Error && 'marqira_stale_timestamp' === $res->get_error_code(), 'expired timestamp is rejected' );

// 6. Signature bound to the path: signing path A but verifying against path B fails.
$req = mq_sign_request( 'POST', '/marqira/v1/some-other-endpoint', $body, $site_uuid, $site_secret, $kid );
$res = Marqira_Hmac_Server::verify( $req, SIGN_PATH );
mq_ok( $res instanceof WP_Error && 'marqira_bad_signature' === $res->get_error_code(), 'signature is bound to the logical path' );

// 7. Replay protection: the same nonce cannot be used twice.
$ts    = (string) time();
$nonce = 'fixed-nonce-xyz';
$req_a = mq_sign_request( 'POST', SIGN_PATH, $body, $site_uuid, $site_secret, $kid, $ts, $nonce );
$req_b = mq_sign_request( 'POST', SIGN_PATH, $body, $site_uuid, $site_secret, $kid, $ts, $nonce );
$first  = Marqira_Hmac_Server::verify( $req_a, SIGN_PATH );
$second = Marqira_Hmac_Server::verify( $req_b, SIGN_PATH );
mq_ok( true === $first, 'first use of a nonce verifies' );
mq_ok( $second instanceof WP_Error && 'marqira_replay' === $second->get_error_code(), 'replayed nonce is rejected' );

// 8. Missing headers.
$req = new MQ_Fake_Request( array( 'x_marqira_site' => $site_uuid ), 'POST', $body );
$res = Marqira_Hmac_Server::verify( $req, SIGN_PATH );
mq_ok( $res instanceof WP_Error && 'marqira_missing_headers' === $res->get_error_code(), 'missing headers are rejected' );

echo "\n";
echo 'test-hmac-server.php: ' . $GLOBALS['__mq_pass'] . " passed, " . $GLOBALS['__mq_fail'] . " failed\n";
