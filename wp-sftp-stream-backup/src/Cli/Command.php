<?php
/**
 * WP-CLI commands: wp sftp-backup <run|status|test|list|cancel>
 *
 * @package WPSSB
 */

namespace WPSSB\Cli;

use WPSSB\Job\Retention;
use WPSSB\Job\Runner;
use WPSSB\Job\State;
use WPSSB\Remote\HostKeyException;
use WPSSB\Remote\SftpStorage;
use WPSSB\Settings;

/**
 * Streams full WordPress backups to SFTP without using local disk space.
 */
class Command {

	/**
	 * Runs a full backup in this process (recommended for large sites / system cron).
	 *
	 * ## OPTIONS
	 *
	 * [--budget=<seconds>]
	 * : Seconds per step (each step uploads one gzip member). Default 120.
	 *
	 * [--resume]
	 * : Continue the currently running job instead of failing when one exists.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sftp-backup run
	 *     wp sftp-backup run --budget=30 --resume
	 *
	 * @when after_wp_load
	 */
	public function run( $args, $assoc ) {
		$budget  = max( 3, (int) \WP_CLI\Utils\get_flag_value( $assoc, 'budget', 120 ) );
		$current = State::load();
		if ( $current && 'running' === $current['status'] && \WP_CLI\Utils\get_flag_value( $assoc, 'resume', false ) ) {
			\WP_CLI::log( 'Resuming job ' . $current['id'] );
		} else {
			try {
				$current = Runner::start( 'cli' );
			} catch ( \Throwable $e ) {
				\WP_CLI::error( $e->getMessage() );
			}
			\WP_CLI::log( 'Started job ' . $current['id'] . ' -> ' . $current['remote_dir'] );
		}

		$printed = count( $current['log'] );
		$idle    = 0;
		while ( true ) {
			$result = Runner::step( (float) $budget, 'cli' );
			$state  = $result['state'];
			if ( ! $state ) {
				\WP_CLI::error( 'Job state lost.' );
			}
			$log = $state['log'];
			for ( $i = max( 0, min( $printed, count( $log ) ) ); $i < count( $log ); $i++ ) {
				\WP_CLI::log( sprintf( '%s [%s] %s', gmdate( 'H:i:s', (int) $log[ $i ][0] ), $log[ $i ][1], $log[ $i ][2] ) );
			}
			$printed = count( $log );

			if ( 'running' !== $state['status'] ) {
				break;
			}
			$s = State::summary( $state );
			\WP_CLI::log(
				sprintf(
					'  %s | db %d/%d tables, %s | files %d, %s',
					$s['phase'],
					$s['db_table_done'],
					$s['db_tables'],
					size_format( $s['db_bytes'], 1 ),
					$s['files_count'],
					size_format( $s['files_bytes'], 1 )
				)
			);
			if ( $result['busy'] ) {
				if ( ++$idle > 120 ) {
					\WP_CLI::error( 'Another process keeps the job locked; giving up.' );
				}
				sleep( 5 );
				continue;
			}
			$idle = 0;
			if ( (int) $state['errors'] > 0 ) {
				sleep( min( 60, 5 * (int) $state['errors'] ) );
			}
			if ( ( $state['retry_after'] ?? 0 ) > time() ) {
				sleep( min( 60, $state['retry_after'] - time() ) );
			}
		}

		if ( 'done' === $state['status'] ) {
			\WP_CLI::success( sprintf( 'Backup stored in %s (db %s, files %s).', $state['remote_dir'], size_format( $state['db']['offset'], 1 ), size_format( $state['files']['offset'], 1 ) ) );
		} else {
			\WP_CLI::error( sprintf( 'Backup %s: %s', $state['status'], $state['last_error'] ) );
		}
	}

	/**
	 * Shows the status of the current/last job.
	 *
	 * [--format=<format>]
	 * : table or json. Default table.
	 *
	 * @when after_wp_load
	 */
	public function status( $args, $assoc ) {
		$summary = State::summary( State::load() );
		if ( ! $summary ) {
			\WP_CLI::log( 'No backup has been run yet.' );
			return;
		}
		if ( 'json' === \WP_CLI\Utils\get_flag_value( $assoc, 'format', 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $summary ) );
			return;
		}
		unset( $summary['log'] );
		$rows = array();
		foreach ( $summary as $key => $value ) {
			$rows[] = array(
				'key'   => $key,
				'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'key', 'value' ) );
	}

	/**
	 * Tests the SFTP connection with the saved settings.
	 *
	 * [--trust]
	 * : Save the server fingerprint when none is configured yet.
	 *
	 * [--repository=<id>]
	 * : Test this repository instead of the primary (multi-repository mode).
	 *
	 * [--allow-public-probe]
	 * : Consent to leaving a small probe file in a public upload-only share.
	 *
	 * @when after_wp_load
	 */
	public function test( $args, $assoc ) {
		if ( \WPSSB\Job\Replication::configured() ) {
			$all = \WPSSB\Repositories::get();
			try {
				\WP_CLI::success( \WPSSB\Repositories::test( $assoc['repository'] ?? $all['primary'], ! empty( $assoc['allow-public-probe'] ) ) );
			} catch ( \Throwable $e ) {
				\WP_CLI::error( $e->getMessage() );
			}
			return;
		}
		$settings = Settings::get();
		$storage  = new SftpStorage( Settings::connection( $settings ) );
		try {
			$fingerprint = $storage->connect();
			$base        = Settings::remoteBase( $settings );
			$storage->mkdir( $base );
			$probe = $base . '/.wpssb-write-test-' . wp_generate_password( 8, false, false );
			$storage->putContents( $probe, 'ok' );
			$storage->delete( $probe );
			$storage->disconnect();
		} catch ( HostKeyException $e ) {
			if ( '' === trim( (string) $settings['fingerprint'] ) && \WP_CLI\Utils\get_flag_value( $assoc, 'trust', false ) && ! Settings::isConstant( 'fingerprint' ) ) {
				$raw                = Settings::raw();
				$raw['fingerprint'] = $e->fingerprint();
				Settings::save( $raw );
				\WP_CLI::log( 'Fingerprint saved: ' . $e->fingerprint() );
				$this->test( $args, array() );
				return;
			}
			\WP_CLI::error( $e->getMessage() . ' Server fingerprint: ' . $e->fingerprint() . ( '' === trim( (string) $settings['fingerprint'] ) ? ' (re-run with --trust to accept it)' : '' ) );
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		\WP_CLI::success( sprintf( 'Connected (%s), folder "%s" is writable.', $fingerprint, $base ) );
	}

	/**
	 * Lists the backups of this site stored on the server.
	 *
	 * [--repository=<id>]
	 * : Show known backup history for this repository instead of the primary.
	 *
	 * @when after_wp_load
	 * @subcommand list
	 */
	public function list_( $args, $assoc ) {
		try {
			if ( \WPSSB\Job\Replication::configured() ) {
				$all = \WPSSB\Repositories::get();
				\WP_CLI::log( 'Known plugin history; File Drop entries are upload acknowledgements, not remote verification.' );
				\WP_CLI\Utils\format_items( 'table', \WPSSB\Repositories::listing( $assoc['repository'] ?? $all['primary'] ), array( 'name', 'status', 'complete', 'db', 'files', 'path' ) );
				return;
			}
			$storage = Runner::storage();
			if ( $storage instanceof SftpStorage ) {
				$storage->connect();
			}

			$base  = Settings::remoteBase();
			$items = array();
			foreach ( Retention::listBackups( $storage, $base, Runner::siteSlug() ) as $b ) {
				$dir     = $base . '/' . $b['name'];
				$items[] = array(
					'name'     => $b['name'],
					'date_utc' => $b['time'] ? gmdate( 'Y-m-d H:i:s', $b['time'] ) : '',
					'complete' => $b['complete'] ? 'yes' : 'no',
					'db'       => size_format( (int) $storage->size( $dir . '/database.sql.gz' ), 1 ),
					'files'    => size_format( (int) $storage->size( $dir . '/files.tar.gz' ), 1 ),
				);
			}
			$storage->disconnect();
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		\WP_CLI\Utils\format_items( 'table', $items, array( 'name', 'date_utc', 'complete', 'db', 'files' ) );
	}

	/** Lists configured repositories (secrets are never printed). */
	public function repositories( $args, $assoc ) {
		$all = \WPSSB\Repositories::get();
		$rows = array();
		foreach ( $all['items'] as $id => $r ) {
			$rows[] = array( 'id' => $id, 'name' => $r['name'], 'type' => $r['type'], 'enabled' => $r['enabled'], 'primary' => $all['primary'] === $id );
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'name', 'type', 'enabled', 'primary' ) );
	}

	/** Retries failed replicas from the same protected primary backup. */
	public function retry( $args, $assoc ) {
		try {
			\WPSSB\Job\Replication::retry();
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		$this->run( array(), array( 'resume' => true ) );
	}

	/** Abandons pending replicas and preserves completed remote copies. */
	public function abandon( $args, $assoc ) {
		try {
			\WPSSB\Job\Replication::abandon();
			\WP_CLI::success( 'Pending copies abandoned. Completed backups preserved; partial uploads may need manual cleanup.' );
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Cancels the running job and deletes its partial remote files.
	 *
	 * @when after_wp_load
	 */
	public function cancel( $args, $assoc ) {
		if ( Runner::cancel() ) {
			$state = State::load();
			if ( $state && 'running' === $state['status'] ) {
				\WP_CLI::success( 'Cancellation requested: the running step will stop and clean up shortly.' );
			} else {
				\WP_CLI::success( 'Backup cancelled.' );
			}
		} else {
			\WP_CLI::warning( 'No backup is running.' );
		}
	}

	/**
	 * Restores this site from a backup, streaming it directly from the repository.
	 *
	 * Files and tables not in the backup are deleted (excluded paths and tables are kept).
	 * wp-config.php is preserved. No safety backup is made.
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : Remote backup path as shown by `wp sftp-backup list`.
	 *
	 * [--repository=<id>]
	 * : Repository id (defaults to the primary one).
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * @when after_wp_load
	 */
	public function restore( $args, $assoc ) {
		$all  = \WPSSB\Repositories::get();
		$repo = (string) ( $assoc['repository'] ?? $all['primary'] );
		$host = \WPSSB\Restore\OneClick::expectedConfirmation();
		\WP_CLI::confirm( sprintf( 'Overwrite %s with backup %s? Files and tables not in the backup are deleted.', $host, $args[0] ), $assoc );
		try {
			$runtime = \WPSSB\Restore\OneClick::prepareRollback( $repo, (string) $args[0], $host );
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		$script = rtrim( str_replace( '\\', '/', ABSPATH ), '/' ) . '/' . $runtime['dir'] . '/run.php';
		\WP_CLI::log( 'Restore runtime: ' . $script );
		$env = getenv();
		$env = is_array( $env ) ? $env : array();
		$env['WPSSB_RUNTIME_TOKEN'] = $runtime['token'];
		$php  = defined( 'PHP_BINARY' ) && PHP_BINARY ? PHP_BINARY : 'php';
		$proc = proc_open( array( $php, $script ), array( 0 => STDIN, 1 => STDOUT, 2 => STDERR ), $pipes, null, $env );
		if ( ! is_resource( $proc ) ) {
			\WP_CLI::error( sprintf( 'Cannot start PHP. Resume with: WPSSB_RUNTIME_TOKEN=%s php %s', $runtime['token'], $script ) );
		}
		$code = proc_close( $proc );
		if ( 0 !== $code ) {
			\WP_CLI::error( sprintf( 'Restore interrupted. Resume with: WPSSB_RUNTIME_TOKEN=%s php %s', $runtime['token'], $script ) );
		}
		\WP_CLI::success( 'Site restored.' );
	}

	/**
	 * Creates a password-protected migration code for restoring a backup on another server.
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : Remote backup path as shown by `wp sftp-backup list`.
	 *
	 * [--repository=<id>]
	 * : Repository id (defaults to the primary one).
	 *
	 * [--days=<days>]
	 * : Validity in days (1-30).
	 * ---
	 * default: 7
	 * ---
	 *
	 * [--installer=<file>]
	 * : Also write the installer with the code embedded to this file.
	 *
	 * The password is read from the WPSSB_MIGRATION_PASSWORD environment variable or prompted.
	 *
	 * @subcommand migration-code
	 * @when after_wp_load
	 */
	public function migration_code( $args, $assoc ) {
		$all      = \WPSSB\Repositories::get();
		$repo     = (string) ( $assoc['repository'] ?? $all['primary'] );
		$password = (string) getenv( 'WPSSB_MIGRATION_PASSWORD' );
		if ( '' === $password ) {
			$password = self::askHidden( 'Migration password (min. 12 characters): ' );
			if ( self::askHidden( 'Repeat password: ' ) !== $password ) {
				\WP_CLI::error( 'The passwords do not match.' );
			}
		}
		try {
			$result = \WPSSB\Restore\OneClick::migrationCode( $repo, (string) $args[0], $password, (int) ( $assoc['days'] ?? 7 ) );
			if ( ! empty( $assoc['installer'] ) ) {
				if ( false === file_put_contents( (string) $assoc['installer'], \WPSSB\Restore\OneClick::installerSource( $result['code'] ) ) ) {
					throw new \RuntimeException( 'Cannot write ' . $assoc['installer'] );
				}
				\WP_CLI::log( 'Installer written to ' . $assoc['installer'] );
			}
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		\WP_CLI::line( $result['code'] );
		\WP_CLI::success( 'Migration code valid until ' . gmdate( 'Y-m-d H:i', $result['expires'] ) . ' UTC.' );
	}

	private static function askHidden( string $prompt ): string {
		fwrite( STDERR, $prompt );
		$tty = '\\' !== DIRECTORY_SEPARATOR && function_exists( 'shell_exec' );
		if ( $tty ) {
			shell_exec( 'stty -echo 2>/dev/null' );
		}
		$line = fgets( STDIN );
		if ( $tty ) {
			shell_exec( 'stty echo 2>/dev/null' );
		}
		fwrite( STDERR, "\n" );
		return rtrim( (string) $line, "\r\n" );
	}
}
