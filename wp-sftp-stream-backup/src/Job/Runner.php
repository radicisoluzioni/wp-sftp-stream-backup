<?php
/**
 * Backup job orchestration: a resumable state machine executed in
 * time-boxed steps (init -> db -> files -> finalize -> retention -> done).
 *
 * @package WPSSB
 */

namespace WPSSB\Job;

use WPSSB\Db\Dumper;
use WPSSB\Files\Exclusions;
use WPSSB\Notify\Notifier;
use WPSSB\Remote\HostKeyException;
use WPSSB\Remote\RemoteStorage;
use WPSSB\Remote\SftpStorage;
use WPSSB\Settings;

class Runner {

	const MAX_ERRORS    = 5;
	const STALE_AFTER   = 3600;
	const CANCEL_OPTION = 'wpssb_cancel';

	/** @var \wpdb|null Dedicated connection holding a consistent snapshot (CLI only). */
	private static $snapshot_db = null;

	/**
	 * Starts a new backup job.
	 *
	 * @throws \RuntimeException When a job is already running or the configuration is incomplete.
	 */
	public static function start( string $trigger ): array {
		$token = Lock::acquire( 120 );
		if ( null === $token ) {
			throw new \RuntimeException( 'A backup worker is busy. Try again after its current step.' );
		}
		Lock::begin( $token, 120 );
		try {
			return self::startLocked( $trigger );
		} finally {
			Lock::end();
			Lock::release( $token );
		}
	}

	private static function startLocked( string $trigger ): array {
		global $wpdb;

		$settings = Settings::get();
		$multi = Replication::configured();
		if ( ! $multi && ( '' === $settings['host'] || '' === $settings['username'] ) ) {
			throw new \RuntimeException( __( 'SFTP connection is not configured.', 'wp-sftp-stream-backup' ) );
		}
		if ( ! $multi && '' === trim( (string) $settings['fingerprint'] ) ) {
			throw new \RuntimeException( __( 'The SFTP server fingerprint has not been confirmed. Use "Test connection" first.', 'wp-sftp-stream-backup' ) );
		}
		if ( ! $multi && Settings::brokenSecrets() ) {
			throw new \RuntimeException( __( 'Stored SFTP credentials cannot be decrypted (the WordPress security keys changed?). Please enter them again.', 'wp-sftp-stream-backup' ) );
		}

		$current = State::load();
		if ( $current && 'partial' === $current['status'] && ! empty( $current['repositories'] ) ) {
			throw new \RuntimeException( 'Retry or abandon the pending replicas before starting another backup. The primary copy is protected.' );
		}
		if ( $current && 'running' === $current['status'] ) {
			if ( time() - (int) $current['updated_at'] < self::STALE_AFTER ) {
				throw new \RuntimeException( __( 'A backup is already running.', 'wp-sftp-stream-backup' ) );
			}
			self::finish( $current, 'failed', 'Stalled: no progress for more than one hour.' );
		}

		$tables = Dumper::listTables( $wpdb, $wpdb->prefix, (bool) $settings['all_tables'], (array) $settings['exclude_tables'] );
		$name   = self::siteSlug() . '-' . gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 6, false, false ) );
		$base   = Settings::remoteBase( $settings );

		$state = array(
			'id'            => $name,
			'status'        => 'running',
			'phase'         => 'init',
			'trigger'       => $trigger,
			'token'         => wp_generate_password( 32, false, false ),
			'started_at'    => time(),
			'updated_at'    => time(),
			'finished_at'   => 0,
			'base'          => $base,
			'remote_dir'    => $base . '/' . $name,
			'compression'   => (int) $settings['compression'],
			'retention'     => (int) $settings['retention'],
			'exclude_paths' => Exclusions::fromText( (string) $settings['exclude_paths'] )->patterns(),
			'db'            => array(
				'offset' => 0,
				'cursor' => Dumper::initialCursor( $tables ),
				'done'   => false,
			),
			'files'         => FilesPhase::initialState(),
			'warnings'      => 0,
			'errors'        => 0,
			'last_error'    => '',
			'steps'         => 0,
			'log'           => array(),
		);
		if ( $multi ) {
			Replication::initialize( $state );
		}
		State::log( $state, 'info', sprintf( 'Backup started (%s) to %s, %d tables.', $trigger, $state['remote_dir'], count( $tables ) ) );
		delete_option( self::CANCEL_OPTION );
		State::save( $state );
		return $state;
	}

	/**
	 * Runs the current job for at most $budget seconds.
	 *
	 * @param string $mode 'web' or 'cli'.
	 * @return array{busy: bool, ran: bool, state: array|null}
	 */
	public static function step( float $budget, string $mode = 'web' ): array {
		$budget = max( 3.0, $budget );
		$token  = Lock::acquire( (int) ceil( $budget ) + 120 );
		if ( null === $token ) {
			return array(
				'busy'  => true,
				'ran'   => false,
				'state' => State::load(),
			);
		}

		$storage = null;
		$ran     = false;
		Lock::begin( $token, (int) ceil( $budget ) + 120 );
		try {
			$state = State::load();
			if ( ! $state || 'running' !== $state['status'] ) {
				return array(
					'busy'  => false,
					'ran'   => false,
					'state' => $state,
				);
			}
			$ran      = true;
			$job_id   = $state['id'];
			$deadline = microtime( true ) + $budget;
			$storage  = self::storage();
			if ( $storage instanceof SftpStorage ) {
				$storage->connect();
			}
			$state['steps']++;

			while ( 'running' === $state['status'] && microtime( true ) < $deadline ) {
				if ( self::cancelRequested( $state ) ) {
					self::doCancel( $state, $storage );
					break;
				}
				$state               = self::runPhase( $state, $storage, $deadline, $mode );
				if ( $state['id'] !== $job_id ) {
					break;
				}
				$state['errors']     = 0;
				$state['last_error'] = '';
				$state['updated_at'] = time();
				$state['peak_memory'] = max( (int) ( $state['peak_memory'] ?? 0 ), memory_get_peak_usage( true ) );
				if ( 'running' === $state['status'] ) {
					State::save( $state );
				}
				if ( 'replicate' === $state['phase'] ) {
					break;
				}
			}
		} catch ( LostLockException $e ) {
			error_log( 'WPSSB: ' . $e->getMessage() );
			return array( 'busy' => true, 'ran' => false, 'state' => State::load() );
		} catch ( HostKeyException $e ) {
			self::failCommitted( $e->getMessage() );
		} catch ( FatalJobException $e ) {
			self::failCommitted( $e->getMessage() );
		} catch ( \Throwable $e ) {
			$committed = State::load();
			if ( $committed && 'running' === $committed['status'] ) {
				$committed['errors']     = (int) $committed['errors'] + 1;
				$committed['last_error'] = $e->getMessage();
				$committed['updated_at'] = time();
				State::log( $committed, 'error', sprintf( 'Step failed (attempt %d/%d): %s', $committed['errors'], self::MAX_ERRORS, $e->getMessage() ) );
				if ( $committed['errors'] >= self::MAX_ERRORS ) {
					self::finish( $committed, 'failed', $e->getMessage() );
				} else {
					State::save( $committed );
				}
			}
		} finally {
			Lock::end();
			if ( $storage ) {
				$storage->disconnect();
			}
			Lock::release( $token );
		}

		return array(
			'busy'  => false,
			'ran'   => $ran,
			'state' => State::load(),
		);
	}

	/**
	 * Cancels the running job and tries to remove its partial remote files.
	 *
	 * When a step is in progress the cancellation is only requested: the step
	 * holding the lock applies it as soon as its current phase returns.
	 */
	public static function cancel(): bool {
		$state = State::load();
		if ( ! $state || 'running' !== $state['status'] ) {
			return false;
		}
		$token = Lock::acquire( 120 );
		if ( null === $token ) {
			update_option( self::CANCEL_OPTION, $state['id'], false );
			return true;
		}
		try {
			$state = State::load();
			if ( $state && 'running' === $state['status'] ) {
				self::doCancel( $state, null );
			}
		} finally {
			Lock::release( $token );
		}
		return true;
	}

	private static function cancelRequested( array $state ): bool {
		wp_cache_delete( self::CANCEL_OPTION, 'options' );
		return get_option( self::CANCEL_OPTION ) === $state['id'];
	}

	/**
	 * Must be called while holding the job lock.
	 */
	private static function doCancel( array $state, ?RemoteStorage $storage ): void {
		delete_option( self::CANCEL_OPTION );
		self::finish( $state, 'cancelled', 'Cancelled by user.' );
		try {
			$own = null === $storage;
			if ( $own ) {
				$storage = self::storage();
				if ( $storage instanceof SftpStorage ) {
					$storage->connect();
				}
			}
			if ( ! empty( $state['repositories'] ) ) {
				State::log( $state, 'warning', 'Completed copies were preserved. Incomplete remote objects may require manual cleanup.' );
				State::save( $state );
			} elseif ( null !== $storage->size( $state['remote_dir'] ) ) {
				$storage->delete( $state['remote_dir'] );
			}
			if ( $own ) {
				$storage->disconnect();
			}
		} catch ( \Throwable $e ) {
			State::log( $state, 'warning', 'Cancellation cleanup failed: ' . $e->getMessage() );
			State::save( $state );
		}
	}

	public static function storage(): RemoteStorage {
		$state = State::load();
		if ( $state && ! empty( $state['repositories'] ) ) {
			return new \WPSSB\Remote\SegmentedStorage( \WPSSB\Repositories::storage( $state['primary'] ) );
		}
		$custom = apply_filters( 'wpssb_remote_storage', null );
		if ( $custom instanceof RemoteStorage ) {
			return $custom;
		}
		return new SftpStorage( Settings::connection() );
	}

	public static function siteSlug(): string {
		$url  = wp_parse_url( home_url() );
		$slug = strtolower( ( isset( $url['host'] ) ? $url['host'] : '' ) . ( isset( $url['path'] ) ? $url['path'] : '' ) );
		$slug = trim( preg_replace( '/[^a-z0-9.]+/', '-', $slug ), '-.' );
		return '' === $slug ? 'wordpress' : $slug;
	}

	public static function root(): string {
		return rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
	}

	private static function runPhase( array $state, RemoteStorage $storage, float $deadline, string $mode ): array {
		switch ( $state['phase'] ) {
			case 'init':
				$storage->mkdir( $state['remote_dir'] );
				$state['phase'] = 'db';
				break;

			case 'db':
				$db     = self::dumpConnection( $mode );
				$skip   = array(
					$GLOBALS['wpdb']->options => array( 'option_name', array( State::OPTION, Lock::NAME, self::CANCEL_OPTION ) ),
				);
				$result = DbPhase::run( $storage, $state['remote_dir'], $state['db'], $db, (int) $state['compression'], $deadline, $skip );
				$state['db'] = $result['state'];
				self::logWarnings( $state, $result['warnings'] );
				if ( $state['db']['done'] ) {
					self::endSnapshot();
					State::log( $state, 'info', sprintf( 'Database dump completed: %d rows, %s compressed.', $state['db']['cursor']['rows'], size_format( $state['db']['offset'], 1 ) ) );
					$state['phase'] = 'files';
				}
				break;

			case 'files':
				$result = FilesPhase::run(
					$storage,
					$state['remote_dir'],
					$state['files'],
					self::root(),
					new Exclusions( $state['exclude_paths'] ),
					(int) $state['compression'],
					$deadline
				);
				$state['files'] = $result['state'];
				self::logWarnings( $state, $result['warnings'] );
				if ( $state['files']['done'] ) {
					State::log( $state, 'info', sprintf( 'Files archived: %d entries, %s raw, %s compressed.', $state['files']['count'], size_format( $state['files']['raw_bytes'], 1 ), size_format( $state['files']['offset'], 1 ) ) );
					$state['phase'] = 'finalize';
				}
				break;

			case 'finalize':
				Phase::reconcile( $storage, $state['remote_dir'] . '/' . DbPhase::FILE, (int) $state['db']['offset'] );
				Phase::reconcile( $storage, $state['remote_dir'] . '/' . FilesPhase::FILE, (int) $state['files']['offset'] );
				$restore = WPSSB_DIR . 'restore/restore.php';
				if ( is_readable( $restore ) ) {
					$storage->putContents( $state['remote_dir'] . '/restore.php', (string) file_get_contents( $restore ) );
				} else {
					throw new FatalJobException( 'Restore script is missing from the plugin package.' );
				}
				$manifest = self::manifest( $state );
				if ( ! empty( $state['repositories'] ) ) {
					$manifest['format'] = 2;
					foreach ( array( 'database' => DbPhase::FILE, 'files' => FilesPhase::FILE ) as $section => $file ) {
						$manifest[ $section ]['parts_sha256'] = hash( 'sha256', $storage->getContents( $state['remote_dir'] . '/' . $file . '.parts.json' ) );
					}
				}
				$storage->putContents( $state['remote_dir'] . '/' . Retention::MANIFEST, (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				$state['phase'] = ! empty( $state['repositories'] ) ? 'replicate' : 'retention';
				break;

			case 'replicate':
				return Replication::run( $state, $storage, $deadline );

			case 'retention':
				if ( ! empty( $state['repositories'] ) ) {
					Replication::retention( $state );
					$failed = array_filter( $state['repositories'], function ( $r ) { return 'failed' === $r['status']; } );
					self::finish( $state, $failed ? 'partial' : 'done', $failed ? 'Some repository copies failed. Retry or abandon them before the next backup.' : '' );
					return State::load();
				}
				try {
					$result = Retention::apply( $storage, $state['base'], self::siteSlug(), (int) $state['retention'], $state['id'] );
					if ( $result['deleted'] ) {
						State::log( $state, 'info', 'Retention: deleted ' . implode( ', ', $result['deleted'] ) );
					}
					self::logWarnings( $state, $result['warnings'] );
				} catch ( \Throwable $e ) {
					State::log( $state, 'warning', 'Retention failed: ' . $e->getMessage() );
				}
				self::finish( $state, 'done' );
				$state = State::load();
				break;

			default:
				throw new FatalJobException( 'Unknown phase ' . $state['phase'] );
		}
		return $state;
	}

	private static function manifest( array $state ): array {
		global $wpdb, $wp_version;
		return array(
			'format'         => 1,
			'generator'      => 'WP SFTP Stream Backup ' . WPSSB_VERSION,
			'id'             => $state['id'],
			'started_utc'    => gmdate( 'c', (int) $state['started_at'] ),
			'finished_utc'   => gmdate( 'c' ),
			'home_url'       => home_url(),
			'site_url'       => site_url(),
			'abspath'        => ABSPATH,
			'table_prefix'   => $wpdb->prefix,
			'wp_version'     => $wp_version,
			'php_version'    => PHP_VERSION,
			'mysql_version'  => $wpdb->db_version(),
			'db_charset'     => $wpdb->charset,
			'database'       => array(
				'file'   => DbPhase::FILE,
				'bytes'  => (int) $state['db']['offset'],
				'rows'   => (int) $state['db']['cursor']['rows'],
				'tables' => $state['db']['cursor']['tables'],
			),
			'files'          => array(
				'file'      => FilesPhase::FILE,
				'bytes'     => (int) $state['files']['offset'],
				'entries'   => (int) $state['files']['count'],
				'raw_bytes' => (int) $state['files']['raw_bytes'],
			),
			'exclude_paths'  => $state['exclude_paths'],
			'warnings'       => (int) $state['warnings'],
		);
	}

	/**
	 * The CLI keeps one dedicated connection inside a consistent snapshot
	 * transaction for the whole database phase.
	 */
	private static function dumpConnection( string $mode ) {
		global $wpdb;
		if ( 'cli' !== $mode || ! class_exists( '\wpdb' ) ) {
			return $wpdb;
		}
		if ( null === self::$snapshot_db ) {
			$db = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			if ( empty( $db->dbh ) ) {
				return $wpdb;
			}
			$db->query( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
			$db->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' );
			self::$snapshot_db = $db;
		}
		return self::$snapshot_db;
	}

	private static function endSnapshot(): void {
		if ( self::$snapshot_db ) {
			self::$snapshot_db->query( 'COMMIT' );
			self::$snapshot_db->close();
			self::$snapshot_db = null;
		}
	}

	private static function logWarnings( array &$state, array $warnings ): void {
		foreach ( $warnings as $warning ) {
			State::log( $state, 'warning', $warning );
		}
	}

	private static function failCommitted( string $message ): void {
		$committed = State::load();
		if ( $committed && 'running' === $committed['status'] ) {
			self::finish( $committed, 'failed', $message );
		}
	}

	public static function finish( array $state, string $status, string $error = '' ): void {
		self::endSnapshot();
		$state['status']      = $status;
		$state['finished_at'] = time();
		$state['updated_at']  = time();
		if ( '' !== $error ) {
			$state['last_error'] = $error;
		}
		if ( 'done' === $status ) {
			$state['phase'] = 'done';
			State::log( $state, 'info', sprintf( 'Backup completed in %s.', human_time_diff( (int) $state['started_at'], time() ) ) );
		} else {
			State::log( $state, 'error', sprintf( 'Backup %s: %s', $status, $error ) );
		}
		State::save( $state );
		State::addHistory( $state );
		wp_clear_scheduled_hook( 'wpssb_continue' );
		Notifier::jobFinished( $state );
	}
}
