<?php
/**
 * Tests for Marqira_IP_Utils::sanitize_ip() — the shared canonical IP
 * normalizer used to build the heartbeat payload.
 *
 * Run via: php tests/run.php
 *
 * @package Marqira_Connector
 */

require_once __DIR__ . '/bootstrap.php';

echo "IP normalization (sanitize_ip)\n";

// --- Valid inputs that should normalize/pass through -----------------------
mq_ok( '192.0.2.1' === Marqira_IP_Utils::sanitize_ip( '192.0.2.1' ), 'valid IPv4 passes through' );
mq_ok( '2001:db8::1' === Marqira_IP_Utils::sanitize_ip( '2001:db8::1' ), 'valid IPv6 passes through' );
mq_ok( '192.0.2.1' === Marqira_IP_Utils::sanitize_ip( '203.0.113.9' ) ? false : true, 'distinct IPv4 values are not conflated' );

// --- Whitespace ------------------------------------------------------------
mq_ok( '192.0.2.1' === Marqira_IP_Utils::sanitize_ip( '  192.0.2.1  ' ), 'surrounding whitespace is trimmed' );

// --- IPv4 with port --------------------------------------------------------
mq_ok( '203.0.113.1' === Marqira_IP_Utils::sanitize_ip( '203.0.113.1:443' ), 'IPv4 with port strips the port' );

// --- Bracketed IPv6 (with and without port) --------------------------------
mq_ok( '2001:db8::1' === Marqira_IP_Utils::sanitize_ip( '[2001:db8::1]' ), 'bracketed IPv6 strips brackets' );
mq_ok( '2001:db8::1' === Marqira_IP_Utils::sanitize_ip( '[2001:db8::1]:443' ), 'bracketed IPv6 with port strips brackets + port' );

// --- IPv6 zone id + IPv4-mapped IPv6 ---------------------------------------
mq_ok( 'fe80::1' === Marqira_IP_Utils::sanitize_ip( 'fe80::1%eth0' ), 'IPv6 zone id is stripped' );
mq_ok( '192.0.2.1' === Marqira_IP_Utils::sanitize_ip( '::ffff:192.0.2.1' ), 'IPv4-mapped IPv6 reduces to IPv4' );

// --- Comma-separated proxy list --------------------------------------------
mq_ok( '203.0.113.1' === Marqira_IP_Utils::sanitize_ip( '203.0.113.1, 70.41.3.18, 150.172.238.178' ), 'comma-separated list uses the first entry' );

// --- Invalid / rejected inputs (must return false) -------------------------
mq_ok( false === Marqira_IP_Utils::sanitize_ip( '' ), 'empty string is rejected' );
mq_ok( false === Marqira_IP_Utils::sanitize_ip( '   ' ), 'whitespace-only string is rejected' );
mq_ok( false === Marqira_IP_Utils::sanitize_ip( 'unknown' ), 'the "unknown" sentinel is rejected' );
mq_ok( false === Marqira_IP_Utils::sanitize_ip( 'example.com' ), 'a hostname is rejected (not an IP)' );
mq_ok( false === Marqira_IP_Utils::sanitize_ip( 'web01.litespeed.local' ), 'a FQDN hostname is rejected' );
mq_ok( false === Marqira_IP_Utils::sanitize_ip( '999.999.999.999' ), 'out-of-range IPv4 is rejected' );
mq_ok( false === Marqira_IP_Utils::sanitize_ip( 'not-an-ip' ), 'garbage input is rejected' );
mq_ok( false === Marqira_IP_Utils::sanitize_ip( null ), 'non-string input is rejected' );

echo "\n";
echo "test-ip-normalization.php: {$GLOBALS['__mq_pass']} passed, {$GLOBALS['__mq_fail']} failed\n";
