<?php
/**
 * Cross-implementation HMAC known-answer vector.
 *
 * This MUST match the Laravel API test
 * (apps/api/tests/Unit/Services/Hmac/HmacServiceTest.php ->
 * "HMAC matches the shared cross-implementation known vector"). Both sides key
 * HMAC-SHA256 with the site secret used verbatim (the base64 text issued at
 * enrollment, NOT base64-decoded).
 *
 * @package Marqira_Connector
 */

require_once __DIR__ . '/bootstrap.php';

echo "HMAC client cross-implementation vector\n";

$method    = 'POST';
$path      = '/api/v1/heartbeat';
$query     = array();
$timestamp = '1704110400';
$nonce     = 'fixednonce123';
$body      = ''; // empty body -> sha256 of empty string
$secret    = 'bWFycWlyYS10ZXN0LXNlY3JldC0zMi1ieXRlcy1rZXkxMjM0NQ==';
$expected  = '9ccd841ddab2b814c9090915eec726ab6211d3ab48c01f480f1f7ffa1200d011';

// compute_signature() is private; call it via reflection so we test the real
// implementation rather than a copy.
$ref = new ReflectionMethod( 'Marqira_Hmac_Client', 'compute_signature' );
$ref->setAccessible( true );

$signature = $ref->invoke( null, $method, $path, $query, $timestamp, $nonce, $body, $secret );

mq_ok( $signature === $expected, 'connector HMAC signature matches the shared known vector' );

// Sanity: canonical query sorting matches the API (a=1&b=2).
$q = new ReflectionMethod( 'Marqira_Hmac_Client', 'canonicalize_query_string' );
$q->setAccessible( true );
mq_ok( 'a=1&b=2' === $q->invoke( null, array( 'b' => '2', 'a' => '1' ) ), 'canonical query string is sorted and encoded' );

// generate_headers() returns the full signed header set for valid credentials.
$headers = Marqira_Hmac_Client::generate_headers(
	'POST',
	$path,
	array(),
	$body,
	array( 'site_uuid' => 'uuid-1', 'site_secret' => $secret, 'kid' => 'kid-1' )
);
mq_ok(
	isset( $headers['X-MarQira-Signature'], $headers['X-MarQira-Nonce'], $headers['X-MarQira-Timestamp'], $headers['X-MarQira-Kid'] ),
	'generate_headers() returns the full signed header set'
);
mq_ok( empty( Marqira_Hmac_Client::generate_headers( 'POST', $path, array(), $body, array() ) ), 'generate_headers() fails closed without credentials' );
