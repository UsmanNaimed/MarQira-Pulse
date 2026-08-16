<?php
/**
 * Regression test for the Cloudflare range fallback recursion that previously
 * exhausted PHP memory (get_all_ranges -> config fetcher fallback ->
 * get_all_ranges -> ...). With an empty cache and an unenrolled site the
 * resolver must return the bundled ranges WITHOUT recursing.
 *
 * A tight memory limit is set so that if the recursion is ever reintroduced,
 * this test fatals (and the runner reports a failure) instead of hanging.
 *
 * @package Marqira_Connector
 */

require_once __DIR__ . '/bootstrap.php';

echo "Cloudflare range fallback (no recursion)\n";

// Constrain memory: the old infinite recursion would blow past this instantly.
@ini_set( 'memory_limit', '48M' );

// Ensure the site is NOT enrolled and the cache is empty.
delete_option( Marqira_Enrollment::CREDENTIALS_OPTION );
delete_transient( 'marqira_cloudflare_ranges' );

$bundled = Marqira_Cloudflare::get_bundled_ranges();
mq_ok( is_array( $bundled ) && ! empty( $bundled ), 'get_bundled_ranges() returns a non-empty array' );

// The fetcher fallback path (empty cache + not enrolled) must terminate and
// return the bundled ranges.
$fetched = Marqira_Config_Fetcher::get_cloudflare_ranges();
mq_ok( is_array( $fetched ) && ! empty( $fetched ), 'get_cloudflare_ranges() returns without recursing' );
mq_ok( $fetched === $bundled, 'fallback returns exactly the bundled ranges' );

// get_all_ranges() (used by the App Password guard) must also terminate.
delete_transient( 'marqira_cloudflare_ranges' );
$all = Marqira_Cloudflare::get_all_ranges();
mq_ok( is_array( $all ) && ! empty( $all ), 'get_all_ranges() terminates and returns ranges' );

// is_cloudflare_ip() exercises the full path used on every auth request.
delete_transient( 'marqira_cloudflare_ranges' );
$is_cf = Marqira_Cloudflare::is_cloudflare_ip( '173.245.48.1' );
mq_ok( true === $is_cf, 'a known Cloudflare IP is recognised through the full path' );
mq_ok( false === Marqira_Cloudflare::is_cloudflare_ip( '8.8.8.8' ), 'a non-Cloudflare IP is rejected' );
