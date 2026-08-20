<?php
/**
 * Tests for Marqira_Recovery — the snapshot / verify / rollback state machine
 * behind critical-error protection & automatic recovery.
 *
 * Health is driven by a stub Marqira_Health_Check whose verdict is scripted via
 * a global queue, so we can deterministically exercise the healthy, pre-existing
 * critical, auto-recovered and loop-protected paths without a live site. File
 * operations run against real temp directories.
 *
 * @package Marqira_Connector
 */

require __DIR__ . '/bootstrap.php';

// ---------------------------------------------------------------------------
// Sandbox filesystem: point WP content/plugins at a temp tree.
// ---------------------------------------------------------------------------
$sandbox = sys_get_temp_dir() . '/mqrec_' . getmypid();
@mkdir( $sandbox, 0777, true );
define( 'WP_CONTENT_DIR', $sandbox . '/wp-content' );
define( 'WP_PLUGIN_DIR', $sandbox . '/wp-content/plugins' );
@mkdir( WP_PLUGIN_DIR, 0777, true );

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $dir ) {
		return is_dir( $dir ) || mkdir( $dir, 0777, true );
	}
}

// Records deactivations so we can assert the deactivate fallback fired.
$GLOBALS['__mq_deactivated'] = array();
if ( ! function_exists( 'deactivate_plugins' ) ) {
	function deactivate_plugins( $basename, $silent = false ) {
		$GLOBALS['__mq_deactivated'][] = $basename;
	}
}

// ---------------------------------------------------------------------------
// Scripted health stub. Each run() shifts the next verdict off the queue; when
// the queue is empty it falls back to $GLOBALS['__mq_health'] (default healthy).
// ---------------------------------------------------------------------------
$GLOBALS['__mq_health']       = true;
$GLOBALS['__mq_health_queue'] = array();
if ( ! class_exists( 'Marqira_Health_Check' ) ) {
	class Marqira_Health_Check {
		public static function run( $args = array() ) {
			if ( ! empty( $GLOBALS['__mq_health_queue'] ) ) {
				$healthy = (bool) array_shift( $GLOBALS['__mq_health_queue'] );
			} else {
				$healthy = (bool) $GLOBALS['__mq_health'];
			}
			return array(
				'healthy' => $healthy,
				'checks'  => array(),
				'summary' => $healthy ? 'ok' : 'Public frontend: HTTP 500 returned.',
			);
		}
		public static function is_critical( $args = array() ) {
			$r = self::run( $args );
			return empty( $r['healthy'] );
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-marqira-recovery.php';

/** Invoke a private static method on Marqira_Recovery. */
function mq_rec_private( $method, array $args = array() ) {
	$ref = new ReflectionMethod( 'Marqira_Recovery', $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( null, $args );
}

/** Reset all recovery state between scenarios. */
function mq_rec_reset() {
	$GLOBALS['__mq_options']      = array();
	$GLOBALS['__mq_health']       = true;
	$GLOBALS['__mq_health_queue'] = array();
	$GLOBALS['__mq_deactivated']  = array();
}

// ---------------------------------------------------------------------------
// Sentinel set / get / clear
// ---------------------------------------------------------------------------
mq_rec_reset();
mq_ok( array() === Marqira_Recovery::get_sentinel(), 'sentinel is empty by default' );
Marqira_Recovery::set_sentinel( array( 'action_id' => 'a1', 'type' => 'update_plugin' ) );
$s = Marqira_Recovery::get_sentinel();
mq_ok( isset( $s['action_id'] ) && 'a1' === $s['action_id'], 'sentinel round-trips its data' );
Marqira_Recovery::clear_sentinel();
mq_ok( array() === Marqira_Recovery::get_sentinel(), 'clear_sentinel() empties the sentinel' );

// ---------------------------------------------------------------------------
// plugin_slug
// ---------------------------------------------------------------------------
mq_ok( 'akismet' === Marqira_Recovery::plugin_slug( 'akismet/akismet.php' ), 'plugin_slug() extracts folder slug' );
mq_ok( 'hello.php' === Marqira_Recovery::plugin_slug( 'hello.php' ), 'plugin_slug() handles single-file plugins' );

// ---------------------------------------------------------------------------
// Filesystem helpers: copy_dir / replace_dir / remove_dir
// ---------------------------------------------------------------------------
mq_rec_reset();
$src = $sandbox . '/src';
$dst = $sandbox . '/dst';
@mkdir( $src . '/sub', 0777, true );
file_put_contents( $src . '/a.txt', 'AAA' );
file_put_contents( $src . '/sub/b.txt', 'BBB' );

mq_ok( true === Marqira_Recovery::copy_dir( $src, $dst ), 'copy_dir() reports success' );
mq_ok( 'AAA' === @file_get_contents( $dst . '/a.txt' ), 'copy_dir() copies top-level files' );
mq_ok( 'BBB' === @file_get_contents( $dst . '/sub/b.txt' ), 'copy_dir() copies nested files' );

// replace_dir replaces destination contents with the backup contents.
$backup = $sandbox . '/backup';
@mkdir( $backup, 0777, true );
file_put_contents( $backup . '/a.txt', 'OLD' );
mq_ok( true === Marqira_Recovery::replace_dir( $backup, $dst ), 'replace_dir() reports success' );
mq_ok( 'OLD' === @file_get_contents( $dst . '/a.txt' ), 'replace_dir() restores prior file contents' );
mq_ok( ! file_exists( $dst . '/sub/b.txt' ), 'replace_dir() removes files not present in the backup' );

Marqira_Recovery::remove_dir( $dst );
mq_ok( ! is_dir( $dst ), 'remove_dir() deletes the directory tree' );

// ---------------------------------------------------------------------------
// begin(): refuses to proceed when the site is ALREADY critical
// ---------------------------------------------------------------------------
mq_rec_reset();
$GLOBALS['__mq_health'] = false; // pre-existing critical
$guard = Marqira_Recovery::begin( 'cmd-pre', 'update_all_plugins', array( 'plugins' => array( 'x/x.php' ) ) );
mq_ok( false === $guard['proceed'], 'begin() refuses to proceed on a pre-existing critical site' );
mq_ok( 'pre_existing_critical' === $guard['reason'], 'begin() reports reason=pre_existing_critical' );
mq_ok( array() === Marqira_Recovery::get_sentinel(), 'begin() sets NO sentinel when it refuses' );

// ---------------------------------------------------------------------------
// begin(): healthy site proceeds and arms the sentinel
// ---------------------------------------------------------------------------
mq_rec_reset();
$guard = Marqira_Recovery::begin( 'cmd-ok', 'update_core', array() );
mq_ok( true === $guard['proceed'], 'begin() proceeds on a healthy site' );
$s = Marqira_Recovery::get_sentinel();
mq_ok( isset( $s['action_id'] ) && 'cmd-ok' === $s['action_id'], 'begin() arms the sentinel with the action id' );

// ---------------------------------------------------------------------------
// finish_and_verify(): healthy after action -> clean completion, sentinel cleared
// ---------------------------------------------------------------------------
mq_rec_reset();
Marqira_Recovery::begin( 'cmd-clean', 'update_core', array() );
$GLOBALS['__mq_health'] = true;
$report = Marqira_Recovery::finish_and_verify( 'cmd-clean', 'update_core', array(), array() );
mq_ok( true === $report['healthy'], 'finish_and_verify() healthy path reports healthy' );
mq_ok( false === $report['rolled_back'], 'healthy path performs NO rollback' );
mq_ok( array() === Marqira_Recovery::get_sentinel(), 'healthy path clears the sentinel' );

// ---------------------------------------------------------------------------
// finish_and_verify(): action broke the site, file restore recovers it
// ---------------------------------------------------------------------------
mq_rec_reset();
// Build a plugin dir + a backup of its prior (good) files.
$slug     = 'demo';
$basename = 'demo/demo.php';
$live     = WP_PLUGIN_DIR . '/' . $slug;
@mkdir( $live, 0777, true );
file_put_contents( $live . '/demo.php', "<?php // BROKEN\n" );
$bkpdir = Marqira_Recovery::backups_root() . '/cmd-broke/plugins/' . $slug;
@mkdir( $bkpdir, 0777, true );
file_put_contents( $bkpdir . '/demo.php', "<?php // GOOD\n" );

$snapshot = array(
	'action_id' => 'cmd-broke',
	'type'      => 'update_all_plugins',
	'plugins'   => array(
		$basename => array( 'slug' => $slug, 'version' => '1.0', 'backup' => $bkpdir ),
	),
	'themes'    => array(),
	'backups'   => array( Marqira_Recovery::backups_root() . '/cmd-broke' ),
);
// Post-action unhealthy, then healthy after the rollback restores the files.
$GLOBALS['__mq_health_queue'] = array( false, true );
$report = Marqira_Recovery::finish_and_verify( 'cmd-broke', 'update_all_plugins', array( 'plugins' => array( $basename ) ), $snapshot );
mq_ok( true === $report['rolled_back'], 'a post-action critical error triggers a rollback' );
mq_ok( true === $report['recovered'] && true === $report['healthy'], 'file restore recovers the site' );
mq_ok( "<?php // GOOD\n" === @file_get_contents( $live . '/demo.php' ), 'rollback restored the previous plugin files' );

// ---------------------------------------------------------------------------
// rollback(): no backup available -> deactivate fallback (never touches core)
// ---------------------------------------------------------------------------
mq_rec_reset();
$snapshot = array(
	'plugins' => array(
		'evil/evil.php' => array( 'slug' => 'evil', 'version' => '2.0', 'backup' => null ),
	),
	'themes'  => array(),
	'backups' => array(),
);
$did = mq_rec_private( 'rollback', array( 'update_all_plugins', array( 'plugins' => array( 'evil/evil.php' ) ), $snapshot ) );
mq_ok( true === $did && in_array( 'evil/evil.php', $GLOBALS['__mq_deactivated'], true ), 'rollback deactivates a plugin when no file backup exists' );

// ---------------------------------------------------------------------------
// Loop protection: only MAX_ATTEMPTS rollback attempts per action id
// ---------------------------------------------------------------------------
mq_rec_reset();
mq_ok( 0 === Marqira_Recovery::attempts( 'cmd-loop' ), 'attempts start at 0' );
// Site stays broken forever (queue empty -> falls back to unhealthy default).
$GLOBALS['__mq_health'] = false;
$snapshot = array( 'plugins' => array( 'evil/evil.php' => array( 'slug' => 'evil', 'backup' => null ) ), 'themes' => array(), 'backups' => array() );
$r1 = Marqira_Recovery::finish_and_verify( 'cmd-loop', 'update_all_plugins', array( 'plugins' => array( 'evil/evil.php' ) ), $snapshot );
mq_ok( false === $r1['healthy'] && true === $r1['rolled_back'], 'first attempt rolls back but site is still broken' );
mq_ok( 1 === Marqira_Recovery::attempts( 'cmd-loop' ), 'attempt counter incremented to MAX_ATTEMPTS' );
$GLOBALS['__mq_deactivated'] = array();
$r2 = Marqira_Recovery::finish_and_verify( 'cmd-loop', 'update_all_plugins', array( 'plugins' => array( 'evil/evil.php' ) ), $snapshot );
mq_ok( false === $r2['healthy'], 'second call still reports unhealthy' );
mq_ok( array() === $GLOBALS['__mq_deactivated'], 'loop protection: no further rollback is attempted after MAX_ATTEMPTS' );

// ---------------------------------------------------------------------------
// get_last_report() persists the outcome
// ---------------------------------------------------------------------------
$last = Marqira_Recovery::get_last_report();
mq_ok( isset( $last['report'] ) && 'cmd-loop' === $last['action_id'], 'get_last_report() returns the most recent stored report' );

// Cleanup sandbox.
Marqira_Recovery::remove_dir( $sandbox );

echo "\n";
echo 'test-recovery.php: ' . $GLOBALS['__mq_pass'] . " passed, " . $GLOBALS['__mq_fail'] . " failed\n";
