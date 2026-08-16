<?php
/**
 * AES-256-GCM credential encryption tests.
 *
 * @package Marqira_Connector
 */

if ( ! defined( 'MARQIRA_SECRET_KEY' ) ) {
	// A base64-encoded 32-byte key, mirroring the recommended wp-config setup.
	define( 'MARQIRA_SECRET_KEY', base64_encode( str_repeat( 'k', 32 ) ) );
}

require_once __DIR__ . '/bootstrap.php';

echo "Marqira_Crypto (AES-256-GCM)\n";

// 1. Round-trip.
$plaintext  = wp_json_encode_stub( array( 'site_uuid' => 'abc-123', 'site_secret' => 'shhh' ) );
$ciphertext = Marqira_Crypto::encrypt( $plaintext );

mq_ok( is_string( $ciphertext ) && '' !== $ciphertext, 'encrypt() returns a non-empty string' );
mq_ok( 0 === strncmp( $ciphertext, 'MQG1:', 5 ), 'ciphertext carries the MQG1 version prefix' );
mq_ok( false === strpos( $ciphertext, 'shhh' ), 'plaintext is not present in the ciphertext' );
mq_ok( Marqira_Crypto::decrypt( $ciphertext ) === $plaintext, 'decrypt() round-trips to the original plaintext' );

// 2. Tampering is rejected (authentication tag fails).
$decoded  = base64_decode( substr( $ciphertext, 5 ), true );
$tampered = $decoded;
$tampered[ strlen( $tampered ) - 1 ] = ( "\x00" === $tampered[ strlen( $tampered ) - 1 ] ) ? "\x01" : "\x00";
$tampered_payload = 'MQG1:' . base64_encode( $tampered );
mq_ok( false === Marqira_Crypto::decrypt( $tampered_payload ), 'tampered ciphertext fails closed (returns false)' );

// 3. Wrong / missing version prefix is rejected (e.g. legacy CBC blobs).
mq_ok( false === Marqira_Crypto::decrypt( 'CBC1:' . base64_encode( 'anything' ) ), 'non-GCM prefix is rejected' );
mq_ok( false === Marqira_Crypto::decrypt( base64_encode( 'no-prefix' ) ), 'payload without version prefix is rejected' );

// 4. Invalid base64 body is rejected.
mq_ok( false === Marqira_Crypto::decrypt( 'MQG1:!!!not-base64!!!' ), 'invalid base64 body is rejected' );

// 5. Two encryptions of the same plaintext differ (random IV).
$c1 = Marqira_Crypto::encrypt( 'same' );
$c2 = Marqira_Crypto::encrypt( 'same' );
mq_ok( $c1 !== $c2, 'random IV makes repeated encryptions differ' );

// helper: a tiny stand-in for wp_json_encode (not loaded here).
function wp_json_encode_stub( $data ) {
	return json_encode( $data );
}
