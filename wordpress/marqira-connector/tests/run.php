<?php
/**
 * Standalone test runner for the MarQira Connector plugin.
 *
 * Each test file runs in its own PHP subprocess (so per-test constants like
 * MARQIRA_SECRET_KEY and memory_limit stay isolated). Exit code is non-zero if
 * any test fails or any subprocess fatals.
 *
 * Usage: php tests/run.php
 *
 * @package Marqira_Connector
 */

$tests = array(
        __DIR__ . '/test-crypto.php',
        __DIR__ . '/test-cloudflare-recursion.php',
        __DIR__ . '/test-hmac-vector.php',
);

$total_pass = 0;
$total_fail = 0;
$php        = PHP_BINARY;

foreach ( $tests as $test ) {
        $cmd    = escapeshellarg( $php ) . ' ' . escapeshellarg( $test ) . ' 2>&1';
        $output = shell_exec( $cmd );
        echo $output;

        // Count check/cross marks by their raw UTF-8 byte sequences.
        $pass = substr_count( (string) $output, "\xE2\x9C\x93" );
        $fail = substr_count( (string) $output, "\xE2\x9C\x97" );

        $total_pass += $pass;
        $total_fail += $fail;

        // A fatal error in a subprocess means the file did not complete cleanly.
        if ( false !== stripos( (string) $output, 'Fatal error' ) || false !== stripos( (string) $output, 'PHP Parse error' ) ) {
                $total_fail++;
                echo "  \xE2\x9C\x97 FAIL: subprocess fatal/parse error in " . basename( $test ) . "\n";
        }
        echo "\n";
}

echo "======================================\n";
echo "Connector tests: {$total_pass} passed, {$total_fail} failed\n";

exit( $total_fail > 0 ? 1 : 0 );
