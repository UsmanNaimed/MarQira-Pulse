<?php
/**
 * Tests for the resilience primitives in Marqira_Remote_Update:
 *   - command idempotency (a re-delivered command never runs twice), and
 *   - the single-flight update lock with automatic stale-lock recovery.
 *
 * These exercise the pure locking / dedup logic in isolation (via reflection),
 * without invoking the WordPress upgrader, which needs a full WP runtime.
 *
 * @package Marqira_Connector
 */

require __DIR__ . '/bootstrap.php';

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-marqira-remote-update.php';

/**
 * Invoke a private static method on Marqira_Remote_Update.
 */
function mq_call_private( $method, array $args = array() ) {
	$ref = new ReflectionMethod( 'Marqira_Remote_Update', $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( null, $args );
}

// ---------------------------------------------------------------------------
// Idempotency / dedup
// ---------------------------------------------------------------------------

// Unknown command is not a duplicate.
mq_ok( false === Marqira_Remote_Update::has_processed_command( 'cmd-1' ), 'unknown command is not a duplicate' );

// After marking, it is recognised as processed.
mq_call_private( 'mark_command_processed', array( 'cmd-1' ) );
mq_ok( true === Marqira_Remote_Update::has_processed_command( 'cmd-1' ), 'marked command is recognised as processed' );

// An empty command id is never treated as processed (no id -> no dedup).
mq_ok( false === Marqira_Remote_Update::has_processed_command( '' ), 'empty command id is never a duplicate' );

// Marking the same id twice does not create duplicates in the stored list.
mq_call_private( 'mark_command_processed', array( 'cmd-1' ) );
$processed = get_option( Marqira_Remote_Update::PROCESSED_OPTION, array() );
$count_cmd1 = count( array_keys( $processed, 'cmd-1', true ) );
mq_ok( 1 === $count_cmd1, 'marking the same command twice stores it once' );

// The processed list stays bounded to PROCESSED_CAP.
for ( $i = 0; $i < Marqira_Remote_Update::PROCESSED_CAP + 25; $i++ ) {
	mq_call_private( 'mark_command_processed', array( 'bulk-' . $i ) );
}
$processed = get_option( Marqira_Remote_Update::PROCESSED_OPTION, array() );
mq_ok(
	count( $processed ) <= Marqira_Remote_Update::PROCESSED_CAP,
	'processed-command list is bounded to PROCESSED_CAP'
);
// The most recent id is still present after trimming.
mq_ok(
	in_array( 'bulk-' . ( Marqira_Remote_Update::PROCESSED_CAP + 24 ), $processed, true ),
	'the most recent command survives list trimming'
);

// ---------------------------------------------------------------------------
// Single-flight lock + stale-lock recovery
// ---------------------------------------------------------------------------

// Ensure a clean lock slate.
delete_transient( Marqira_Remote_Update::LOCK_TRANSIENT );

// First acquire succeeds.
mq_ok( true === mq_call_private( 'acquire_lock' ), 'lock is acquired when free' );

// A second acquire while the (fresh) lock is held fails.
mq_ok( false === mq_call_private( 'acquire_lock' ), 'lock blocks a concurrent run' );

// Releasing the lock frees it.
mq_call_private( 'release_lock' );
mq_ok( true === mq_call_private( 'acquire_lock' ), 'lock can be re-acquired after release' );

// Simulate a stale/orphaned lock (older than LOCK_MAX_AGE) left by a crashed
// run — acquire_lock must recover it and succeed rather than block forever.
set_transient(
	Marqira_Remote_Update::LOCK_TRANSIENT,
	time() - Marqira_Remote_Update::LOCK_MAX_AGE - 10,
	Marqira_Remote_Update::LOCK_MAX_AGE
);
mq_ok( true === mq_call_private( 'acquire_lock' ), 'a stale lock is auto-recovered' );

echo "\n";
echo 'test-remote-update-dedup.php: ' . $GLOBALS['__mq_pass'] . " passed, " . $GLOBALS['__mq_fail'] . " failed\n";
