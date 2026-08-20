<?php
/**
 * Tests for the must-use recovery guard (mu-plugins/marqira-guard.php).
 *
 * The guard is a standalone, dependency-free class. We exercise its private
 * helpers via reflection: emergency_deactivate (removes ONLY named plugins),
 * is_fatal (fatal classification), and record_event (bounded event log).
 *
 * @package Marqira_Connector
 */

require __DIR__ . '/bootstrap.php';

// The guard checks ABSPATH (defined by bootstrap) then boots. Including it
// registers a shutdown handler which is harmless for the test process.
require dirname( __DIR__ ) . '/mu-plugins/marqira-guard.php';

echo "Marqira_Guard\n";

mq_ok( class_exists( 'Marqira_Guard' ), 'guard class is defined' );

// Helper to invoke a private static method by name.
$call = function ( $method, array $args = array() ) {
	$ref = new ReflectionMethod( 'Marqira_Guard', $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( null, $args );
};

// ---------------------------------------------------------------------------
// is_fatal()
// ---------------------------------------------------------------------------
mq_ok( true === $call( 'is_fatal', array( array( 'type' => E_ERROR ) ) ), 'is_fatal true for E_ERROR' );
mq_ok( true === $call( 'is_fatal', array( array( 'type' => E_PARSE ) ) ), 'is_fatal true for E_PARSE' );
mq_ok( true === $call( 'is_fatal', array( array( 'type' => E_COMPILE_ERROR ) ) ), 'is_fatal true for E_COMPILE_ERROR' );
mq_ok( false === $call( 'is_fatal', array( array( 'type' => E_WARNING ) ) ), 'is_fatal false for E_WARNING' );
mq_ok( false === $call( 'is_fatal', array( array( 'type' => E_NOTICE ) ) ), 'is_fatal false for E_NOTICE' );
mq_ok( false === $call( 'is_fatal', array( null ) ), 'is_fatal false for null' );
mq_ok( false === $call( 'is_fatal', array( array() ) ), 'is_fatal false for empty array' );

// ---------------------------------------------------------------------------
// emergency_deactivate() — removes ONLY named plugins, preserves the rest
// ---------------------------------------------------------------------------
update_option( 'active_plugins', array(
	'akismet/akismet.php',
	'marqira-connector/marqira-connector.php',
	'woocommerce/woocommerce.php',
) );

$removed = $call( 'emergency_deactivate', array( array( 'woocommerce/woocommerce.php' ) ) );
mq_ok( array( 'woocommerce/woocommerce.php' ) === $removed, 'emergency_deactivate returns the removed basename' );

$active = get_option( 'active_plugins' );
mq_ok( in_array( 'akismet/akismet.php', $active, true ), 'unrelated plugin akismet preserved' );
mq_ok( in_array( 'marqira-connector/marqira-connector.php', $active, true ), 'unrelated plugin connector preserved' );
mq_ok( ! in_array( 'woocommerce/woocommerce.php', $active, true ), 'targeted plugin woocommerce removed' );
mq_ok( 2 === count( $active ), 'active_plugins now has exactly 2 entries' );

// Deactivating a plugin that is not active is a no-op (nothing removed).
$removed2 = $call( 'emergency_deactivate', array( array( 'not-installed/foo.php' ) ) );
mq_ok( array() === $removed2, 'emergency_deactivate no-op for plugin that is not active' );
mq_ok( 2 === count( get_option( 'active_plugins' ) ), 'active_plugins unchanged after no-op' );

// When active_plugins is missing/not an array, return empty and do not fatal.
delete_option( 'active_plugins' );
$removed3 = $call( 'emergency_deactivate', array( array( 'anything/foo.php' ) ) );
mq_ok( array() === $removed3, 'emergency_deactivate safe when active_plugins missing' );

// ---------------------------------------------------------------------------
// record_event() — bounded to MAX_EVENTS (25)
// ---------------------------------------------------------------------------
delete_option( 'marqira_recovery_guard_events' );
for ( $i = 0; $i < 30; $i++ ) {
	$call( 'record_event', array( array( 'at' => $i, 'type' => 'test' ) ) );
}
$events = get_option( 'marqira_recovery_guard_events' );
mq_ok( is_array( $events ), 'events option is an array' );
mq_ok( 25 === count( $events ), 'record_event bounds the log to MAX_EVENTS (25)' );
mq_ok( 5 === $events[0]['at'], 'oldest retained event is #5 (first 5 evicted)' );
mq_ok( 29 === $events[24]['at'], 'newest event is #29' );
