<?php
/**
 * Marqira critical-error protection & automatic recovery.
 *
 * Wraps a potentially risky, Marqira-managed action (plugin/theme/core update)
 * with:
 *   1. a PRE health check   — refuse & report if the site is already broken;
 *   2. a rollback point     — a file-level backup of the exact target(s);
 *   3. a recovery sentinel  — a marker the mu-plugin guard can read even if the
 *                             main plugin can no longer bootstrap;
 *   4. the action itself     (run by the caller);
 *   5. a POST health check  — if the site became critical *and* it was healthy
 *                             before, revert ONLY the specific managed change,
 *                             once (loop-protected), then re-verify.
 *
 * Design rules enforced here (see spec §2 "Safety requirements"):
 *   - Only ever rolls back a change this class snapshotted for a known action.
 *   - Never touches unrelated plugins/themes/files.
 *   - Never restores database data.
 *   - Detects genuine unhealthiness (via Marqira_Health_Check) before reverting.
 *   - Avoids rollback loops via a bounded attempts counter keyed on action id.
 *   - Logs every decision to the security log.
 *   - If the site was already broken *before* the action, it reports that and
 *     does not blame or roll back the requested operation.
 *
 * @package Marqira_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Marqira_Recovery {

	/** Option holding the in-progress action marker (read by the mu-plugin guard). */
	const SENTINEL_OPTION = 'marqira_recovery_sentinel';

	/** Option holding per-action rollback attempt counts (loop protection). */
	const ATTEMPTS_OPTION = 'marqira_recovery_attempts';

	/** Option holding the most recent recovery report (for telemetry/dashboard). */
	const LAST_REPORT_OPTION = 'marqira_recovery_last_report';

	/** Max rollback attempts per action id. */
	const MAX_ATTEMPTS = 1;

	/** Backup dir name under wp-content/upgrade/. */
	const BACKUP_SUBDIR = 'marqira-recovery-backups';

	/**
	 * Establish a baseline and rollback point before a risky action.
	 *
	 * @param string $action_id Unique id for this managed action (command id).
	 * @param string $type      One of update_plugin|update_all_plugins|update_all_themes|update_core.
	 * @param array  $targets   {
	 *     @type array $plugins Array of plugin basenames (folder/file.php) being changed.
	 *     @type array $themes  Array of theme stylesheets being changed.
	 * }
	 * @return array {
	 *     @type bool   $proceed  Whether it is safe to run the action.
	 *     @type string $reason   'ok' | 'pre_existing_critical'.
	 *     @type array  $health   The PRE health report.
	 *     @type array  $snapshot Snapshot metadata (versions + backup paths).
	 * }
	 */
	public static function begin( $action_id, $type, $targets = array() ) {
		$pre = Marqira_Health_Check::run();

		if ( empty( $pre['healthy'] ) ) {
			// The site is ALREADY in a critical state. Do not run and do not
			// blame our action. Report so the dashboard can surface it.
			Marqira_Logger::log(
				'recovery_pre_existing_critical',
				sprintf( 'Site is already in a critical state before action (%s): %s', $type, $pre['summary'] ),
				'warning'
			);
			return array(
				'proceed'  => false,
				'reason'   => 'pre_existing_critical',
				'health'   => $pre,
				'snapshot' => array(),
			);
		}

		$snapshot = self::snapshot( $action_id, $type, $targets );

		// Set the sentinel so the mu-plugin guard can act if we fatal mid-action.
		self::set_sentinel(
			array(
				'action_id' => (string) $action_id,
				'type'      => (string) $type,
				'targets'   => $targets,
				'snapshot'  => $snapshot,
				'started'   => time(),
			)
		);

		Marqira_Logger::log(
			'recovery_snapshot_created',
			sprintf( 'Rollback point created for %s (%s).', $type, self::describe_targets( $targets ) ),
			'info'
		);

		return array(
			'proceed'  => true,
			'reason'   => 'ok',
			'health'   => $pre,
			'snapshot' => $snapshot,
		);
	}

	/**
	 * Verify the outcome of a risky action and roll back if it broke the site.
	 *
	 * @param string $action_id Same id passed to begin().
	 * @param string $type      Action type.
	 * @param array  $targets   Same targets passed to begin().
	 * @param array  $snapshot  Snapshot returned by begin().
	 * @return array {
	 *     @type bool   $healthy    Site health after the action (and any rollback).
	 *     @type bool   $rolled_back Whether a rollback was performed.
	 *     @type bool   $recovered   Whether the site is healthy again after rollback.
	 *     @type string $detail      Human-readable outcome.
	 *     @type array  $health      POST (final) health report.
	 * }
	 */
	public static function finish_and_verify( $action_id, $type, $targets, $snapshot ) {
		$post = Marqira_Health_Check::run();

		if ( ! empty( $post['healthy'] ) ) {
			// All good. Clean up and clear the marker.
			self::cleanup( $snapshot );
			self::clear_sentinel();
			self::reset_attempts( $action_id );
			$report = array(
				'healthy'     => true,
				'rolled_back' => false,
				'recovered'   => true,
				'detail'      => 'Site healthy after action.',
				'health'      => $post,
			);
			self::store_report( $action_id, $type, $report );
			return $report;
		}

		// Site is unhealthy after the action. Loop protection: only attempt a
		// rollback MAX_ATTEMPTS times for a given action id.
		if ( self::attempts( $action_id ) >= self::MAX_ATTEMPTS ) {
			Marqira_Logger::log(
				'recovery_abandoned',
				sprintf( 'Site still critical after %d rollback attempt(s) for %s; not looping.', self::MAX_ATTEMPTS, $type ),
				'error'
			);
			self::clear_sentinel();
			$report = array(
				'healthy'     => false,
				'rolled_back' => true,
				'recovered'   => false,
				'detail'      => 'Rollback did not restore the site; manual intervention required. ' . $post['summary'],
				'health'      => $post,
			);
			self::store_report( $action_id, $type, $report );
			return $report;
		}

		self::increment_attempts( $action_id );

		Marqira_Logger::log(
			'recovery_rollback_started',
			sprintf( 'Site critical after %s (%s); rolling back the managed change.', $type, $post['summary'] ),
			'error'
		);

		$rolled_back = self::rollback( $type, $targets, $snapshot );

		// Re-verify after the rollback.
		$after = Marqira_Health_Check::run();
		self::clear_sentinel();

		if ( ! empty( $after['healthy'] ) ) {
			self::cleanup( $snapshot );
			self::reset_attempts( $action_id );
			Marqira_Logger::log(
				'recovery_rollback_succeeded',
				sprintf( 'Rollback restored the site after a failed %s.', $type ),
				'warning'
			);
			$report = array(
				'healthy'     => true,
				'rolled_back' => true,
				'recovered'   => true,
				'detail'      => $rolled_back
					? 'Update caused a critical error; the change was automatically rolled back and the site is healthy again.'
					: 'Update caused a critical error; the site recovered.',
				'health'      => $after,
			);
			self::store_report( $action_id, $type, $report );
			return $report;
		}

		Marqira_Logger::log(
			'recovery_rollback_incomplete',
			sprintf( 'Rollback attempted for %s but the site is still critical: %s', $type, $after['summary'] ),
			'error'
		);
		$report = array(
			'healthy'     => false,
			'rolled_back' => $rolled_back,
			'recovered'   => false,
			'detail'      => 'Update caused a critical error; automatic rollback did not fully restore the site. Manual intervention required. ' . $after['summary'],
			'health'      => $after,
		);
		self::store_report( $action_id, $type, $report );
		return $report;
	}

	/* ------------------------------------------------------------------ */
	/* Snapshot / backup                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Record versions and copy the target plugin/theme directories to a backup
	 * location so we can restore the exact prior files if the update fatals.
	 *
	 * @param string $action_id Action id.
	 * @param string $type      Action type.
	 * @param array  $targets   Targets.
	 * @return array Snapshot metadata.
	 */
	private static function snapshot( $action_id, $type, $targets ) {
		$snapshot = array(
			'action_id' => (string) $action_id,
			'type'      => (string) $type,
			'plugins'   => array(),
			'themes'    => array(),
			'backups'   => array(),
		);

		$base = self::backup_base_dir( $action_id );

		// Plugins.
		if ( ! empty( $targets['plugins'] ) && is_array( $targets['plugins'] ) ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$all = function_exists( 'get_plugins' ) ? get_plugins() : array();
			foreach ( $targets['plugins'] as $basename ) {
				$slug    = self::plugin_slug( $basename );
				$version = isset( $all[ $basename ]['Version'] ) ? $all[ $basename ]['Version'] : null;
				$src     = self::plugins_dir() . '/' . $slug;
				$dest    = $base . '/plugins/' . $slug;
				$ok      = ( $slug && is_dir( $src ) ) ? self::copy_dir( $src, $dest ) : false;
				$snapshot['plugins'][ $basename ] = array(
					'slug'    => $slug,
					'version' => $version,
					'backup'  => $ok ? $dest : null,
				);
			}
		}

		// Themes.
		if ( ! empty( $targets['themes'] ) && is_array( $targets['themes'] ) ) {
			foreach ( $targets['themes'] as $stylesheet ) {
				$src  = self::themes_dir() . '/' . $stylesheet;
				$dest = $base . '/themes/' . $stylesheet;
				$ok   = ( $stylesheet && is_dir( $src ) ) ? self::copy_dir( $src, $dest ) : false;
				$ver  = null;
				if ( function_exists( 'wp_get_theme' ) ) {
					$theme = wp_get_theme( $stylesheet );
					if ( $theme && $theme->exists() ) {
						$ver = $theme->get( 'Version' );
					}
				}
				$snapshot['themes'][ $stylesheet ] = array(
					'version' => $ver,
					'backup'  => $ok ? $dest : null,
				);
			}
		}

		$snapshot['backups'][] = $base;
		return $snapshot;
	}

	/* ------------------------------------------------------------------ */
	/* Rollback                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Revert the specific managed change. Restores files from the snapshot; if a
	 * file restore is not possible, deactivates the offending plugin so the site
	 * can bootstrap again. Never touches unrelated code.
	 *
	 * @param string $type     Action type.
	 * @param array  $targets  Targets.
	 * @param array  $snapshot Snapshot metadata.
	 * @return bool True if at least one concrete rollback action was performed.
	 */
	private static function rollback( $type, $targets, $snapshot ) {
		$did_something = false;

		// Plugin rollback: restore prior files, else deactivate.
		if ( ! empty( $snapshot['plugins'] ) ) {
			foreach ( $snapshot['plugins'] as $basename => $meta ) {
				$slug = isset( $meta['slug'] ) ? $meta['slug'] : self::plugin_slug( $basename );
				$dest = self::plugins_dir() . '/' . $slug;

				if ( ! empty( $meta['backup'] ) && is_dir( $meta['backup'] ) ) {
					if ( self::replace_dir( $meta['backup'], $dest ) ) {
						$did_something = true;
						Marqira_Logger::log( 'recovery_plugin_restored', sprintf( 'Restored previous version of plugin %s.', $slug ), 'warning' );
						continue;
					}
				}

				// Fallback: deactivate the plugin so WP can boot.
				if ( self::deactivate_plugin( $basename ) ) {
					$did_something = true;
					Marqira_Logger::log( 'recovery_plugin_deactivated', sprintf( 'Deactivated plugin %s after a fatal.', $basename ), 'warning' );
				}
			}
		}

		// Theme rollback: restore prior files; if the active theme is broken,
		// switch to a core default so the site can render.
		if ( ! empty( $snapshot['themes'] ) ) {
			foreach ( $snapshot['themes'] as $stylesheet => $meta ) {
				$dest = self::themes_dir() . '/' . $stylesheet;
				if ( ! empty( $meta['backup'] ) && is_dir( $meta['backup'] ) ) {
					if ( self::replace_dir( $meta['backup'], $dest ) ) {
						$did_something = true;
						Marqira_Logger::log( 'recovery_theme_restored', sprintf( 'Restored previous version of theme %s.', $stylesheet ), 'warning' );
						continue;
					}
				}
				if ( self::switch_to_default_theme( $stylesheet ) ) {
					$did_something = true;
					Marqira_Logger::log( 'recovery_theme_switched', sprintf( 'Switched away from broken theme %s to a default theme.', $stylesheet ), 'warning' );
				}
			}
		}

		// Core updates: WordPress does not support a safe generic file rollback
		// from the connector. We report; we never attempt to overwrite core.
		if ( 'update_core' === $type && ! $did_something ) {
			Marqira_Logger::log(
				'recovery_core_no_autorevert',
				'Core update left the site critical. Automatic core rollback is not safely supported from the connector; reporting for manual recovery.',
				'error'
			);
		}

		return $did_something;
	}

	/* ------------------------------------------------------------------ */
	/* Sentinel & attempts                                                 */
	/* ------------------------------------------------------------------ */

	public static function set_sentinel( $data ) {
		update_option( self::SENTINEL_OPTION, $data, false );
	}

	public static function get_sentinel() {
		return get_option( self::SENTINEL_OPTION, array() );
	}

	public static function clear_sentinel() {
		delete_option( self::SENTINEL_OPTION );
	}

	public static function attempts( $action_id ) {
		$all = get_option( self::ATTEMPTS_OPTION, array() );
		return isset( $all[ $action_id ] ) ? (int) $all[ $action_id ] : 0;
	}

	private static function increment_attempts( $action_id ) {
		$all               = get_option( self::ATTEMPTS_OPTION, array() );
		$all[ $action_id ] = ( isset( $all[ $action_id ] ) ? (int) $all[ $action_id ] : 0 ) + 1;
		// Bound the map so it cannot grow forever.
		if ( count( $all ) > 50 ) {
			$all = array_slice( $all, -50, null, true );
		}
		update_option( self::ATTEMPTS_OPTION, $all, false );
	}

	private static function reset_attempts( $action_id ) {
		$all = get_option( self::ATTEMPTS_OPTION, array() );
		if ( isset( $all[ $action_id ] ) ) {
			unset( $all[ $action_id ] );
			update_option( self::ATTEMPTS_OPTION, $all, false );
		}
	}

	private static function store_report( $action_id, $type, $report ) {
		update_option(
			self::LAST_REPORT_OPTION,
			array(
				'action_id' => (string) $action_id,
				'type'      => (string) $type,
				'report'    => $report,
				'at'        => time(),
			),
			false
		);
	}

	public static function get_last_report() {
		return get_option( self::LAST_REPORT_OPTION, array() );
	}

	/* ------------------------------------------------------------------ */
	/* Filesystem helpers                                                  */
	/* ------------------------------------------------------------------ */

	private static function backup_base_dir( $action_id ) {
		$root = self::backups_root();
		$safe = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $action_id );
		if ( '' === $safe ) {
			$safe = 'action-' . substr( md5( (string) $action_id . microtime() ), 0, 12 );
		}
		return $root . '/' . $safe;
	}

	public static function backups_root() {
		$content = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
		return $content . '/upgrade/' . self::BACKUP_SUBDIR;
	}

	private static function plugins_dir() {
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			return WP_PLUGIN_DIR;
		}
		$content = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
		return $content . '/plugins';
	}

	private static function themes_dir() {
		if ( function_exists( 'get_theme_root' ) ) {
			return get_theme_root();
		}
		$content = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
		return $content . '/themes';
	}

	public static function plugin_slug( $basename ) {
		$basename = (string) $basename;
		if ( false !== strpos( $basename, '/' ) ) {
			return substr( $basename, 0, strpos( $basename, '/' ) );
		}
		// Single-file plugin (no folder).
		return $basename;
	}

	/**
	 * Recursively copy a directory. Returns true on success.
	 *
	 * @param string $src  Source directory.
	 * @param string $dest Destination directory.
	 * @return bool
	 */
	public static function copy_dir( $src, $dest ) {
		if ( ! is_dir( $src ) ) {
			return false;
		}
		if ( ! wp_mkdir_p( $dest ) ) {
			return false;
		}
		$dir = @opendir( $src );
		if ( false === $dir ) {
			return false;
		}
		$ok = true;
		while ( false !== ( $entry = readdir( $dir ) ) ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$s = $src . '/' . $entry;
			$d = $dest . '/' . $entry;
			if ( is_dir( $s ) ) {
				$ok = self::copy_dir( $s, $d ) && $ok;
			} else {
				$ok = @copy( $s, $d ) && $ok;
			}
		}
		closedir( $dir );
		return $ok;
	}

	/**
	 * Replace a directory with the contents of a backup (atomic-ish: remove then copy).
	 *
	 * @param string $backup Backup directory.
	 * @param string $dest   Destination to replace.
	 * @return bool
	 */
	public static function replace_dir( $backup, $dest ) {
		if ( ! is_dir( $backup ) ) {
			return false;
		}
		self::remove_dir( $dest );
		return self::copy_dir( $backup, $dest );
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory to remove.
	 * @return void
	 */
	public static function remove_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$handle = @opendir( $dir );
		if ( false === $handle ) {
			return;
		}
		while ( false !== ( $entry = readdir( $handle ) ) ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				self::remove_dir( $path );
			} else {
				@unlink( $path );
			}
		}
		closedir( $handle );
		@rmdir( $dir );
	}

	/**
	 * Remove all backups taken for an action once we no longer need them.
	 *
	 * @param array $snapshot Snapshot metadata.
	 * @return void
	 */
	private static function cleanup( $snapshot ) {
		if ( empty( $snapshot['backups'] ) || ! is_array( $snapshot['backups'] ) ) {
			return;
		}
		foreach ( $snapshot['backups'] as $dir ) {
			// Guard: only ever remove paths under our own backups root.
			if ( 0 === strpos( (string) $dir, self::backups_root() ) ) {
				self::remove_dir( $dir );
			}
		}
	}

	private static function deactivate_plugin( $basename ) {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( function_exists( 'deactivate_plugins' ) ) {
			deactivate_plugins( $basename, true );
			return true;
		}
		return false;
	}

	private static function switch_to_default_theme( $broken_stylesheet ) {
		if ( ! function_exists( 'wp_get_themes' ) || ! function_exists( 'switch_theme' ) ) {
			return false;
		}
		$defaults = array( 'twentytwentyfour', 'twentytwentythree', 'twentytwentytwo', 'twentytwentyone', 'twentytwenty' );
		foreach ( $defaults as $candidate ) {
			$theme = wp_get_theme( $candidate );
			if ( $theme && $theme->exists() && $candidate !== $broken_stylesheet ) {
				switch_theme( $candidate );
				return true;
			}
		}
		return false;
	}

	private static function describe_targets( $targets ) {
		$parts = array();
		if ( ! empty( $targets['plugins'] ) ) {
			$parts[] = count( (array) $targets['plugins'] ) . ' plugin(s)';
		}
		if ( ! empty( $targets['themes'] ) ) {
			$parts[] = count( (array) $targets['themes'] ) . ' theme(s)';
		}
		return $parts ? implode( ', ', $parts ) : 'no file targets';
	}
}
