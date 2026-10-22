<?php
/**
 * Files phase: streams the WordPress tree as tar into gzip members appended
 * to files.tar.gz. A single file may span several steps.
 *
 * @package WPSSB
 */

namespace WPSSB\Job;

use WPSSB\Archive\TarWriter;
use WPSSB\Files\Exclusions;
use WPSSB\Files\Walker;
use WPSSB\Remote\RemoteAppender;
use WPSSB\Remote\RemoteStorage;
use WPSSB\Stream\GzipMemberWriter;

class FilesPhase {

	const FILE       = 'files.tar.gz';
	const READ_CHUNK = 262144;

	public static function initialState(): array {
		return array(
			'offset'    => 0,
			'cursor'    => null,
			'partial'   => null,
			'count'     => 0,
			'raw_bytes' => 0,
			'done'      => false,
		);
	}

	/**
	 * Root-level files living outside the root (wp-config.php one level up).
	 *
	 * @return array<string,string>
	 */
	public static function extraFiles( string $root ): array {
		$root   = rtrim( str_replace( '\\', '/', $root ), '/' );
		$parent = dirname( $root );
		if ( ! file_exists( $root . '/wp-config.php' ) && file_exists( $parent . '/wp-config.php' ) && ! file_exists( $parent . '/wp-settings.php' ) ) {
			return array( 'wp-config.php' => $parent . '/wp-config.php' );
		}
		return array();
	}

	/**
	 * @param array $fs State from initialState() or a previous run.
	 * @return array{state: array, warnings: string[]}
	 */
	public static function run( RemoteStorage $storage, string $remote_dir, array $fs, string $root, Exclusions $exclusions, int $level, float $deadline ): array {
		$path = $remote_dir . '/' . self::FILE;
		Phase::reconcile( $storage, $path, (int) $fs['offset'] );

		$warnings = array();
		$warn     = function ( $message ) use ( &$warnings ) {
			$warnings[] = $message;
		};
		$appender = new RemoteAppender( $storage, $path, (int) $fs['offset'] );
		$gzip     = new GzipMemberWriter( array( $appender, 'write' ), $level );
		$tar      = new TarWriter( array( $gzip, 'write' ) );

		$paused = false;
		$worked = false;

		if ( ! empty( $fs['partial'] ) ) {
			$worked = true;
			$p      = $fs['partial'];
			$tar->resumeEntry( (int) $p['size'], (int) $p['written'] );
			$handle = @fopen( $p['abs'], 'rb' );
			if ( false === $handle ) {
				$warn( sprintf( 'File "%s" disappeared while being archived; padded with zeros.', $p['rel'] ) );
			} else {
				if ( (int) $p['written'] > 0 && 0 !== fseek( $handle, (int) $p['written'] ) ) {
					$warn( sprintf( 'Cannot seek in "%s"; padded with zeros.', $p['rel'] ) );
				} else {
					$paused = ! self::copy( $handle, $tar, $deadline );
				}
				fclose( $handle );
			}
			if ( $paused ) {
				$fs['partial']['written'] = $tar->entryWritten();
			} else {
				if ( $tar->endEntry() > 0 ) {
					$warn( sprintf( 'File "%s" shrank while being archived; padded with zeros.', $p['rel'] ) );
				}
				$fs['cursor']  = $p['rel'];
				$fs['partial'] = null;
				$fs['count']++;
			}
		}

		$exhausted = false;
		if ( ! $paused ) {
			$walker    = new Walker( $root, $exclusions, self::extraFiles( $root ), $warn );
			$exhausted = true;
			foreach ( $walker->walk( $fs['cursor'] ) as $entry ) {
				if ( $worked && microtime( true ) >= $deadline ) {
					$exhausted = false;
					break;
				}
				$worked = true;
				$rel    = $entry['rel'];

				if ( 'dir' === $entry['type'] ) {
					$st = @stat( $entry['abs'] );
					$tar->beginEntry( $rel . '/', 0, $st ? $st['mode'] : 0755, $st ? $st['mtime'] : time(), '5' );
					$tar->endEntry();
					$fs['cursor'] = $rel;
					continue;
				}

				if ( 'link' === $entry['type'] ) {
					$st = @lstat( $entry['abs'] );
					$tar->beginEntry( $rel, 0, 0777, $st ? $st['mtime'] : time(), '2', $entry['target'] );
					$tar->endEntry();
					$fs['cursor'] = $rel;
					$fs['count']++;
					continue;
				}

				$handle = @fopen( $entry['abs'], 'rb' );
				if ( false === $handle ) {
					$warn( sprintf( 'Cannot read file "%s", skipped.', $rel ) );
					$fs['cursor'] = $rel;
					continue;
				}
				$st   = fstat( $handle );
				$size = $st ? (int) $st['size'] : 0;
				$tar->beginEntry( $rel, $size, $st ? $st['mode'] : 0644, $st ? $st['mtime'] : time() );
				$fs['raw_bytes'] += $size;

				$complete = self::copy( $handle, $tar, $deadline );
				fclose( $handle );

				if ( ! $complete ) {
					$fs['partial'] = array(
						'rel'     => $rel,
						'abs'     => $entry['abs'],
						'size'    => $size,
						'written' => $tar->entryWritten(),
					);
					$exhausted = false;
					break;
				}
				if ( $tar->endEntry() > 0 ) {
					$warn( sprintf( 'File "%s" shrank while being archived; padded with zeros.', $rel ) );
				}
				$fs['cursor'] = $rel;
				$fs['count']++;
			}
		}

		if ( $exhausted ) {
			$tar->finishArchive();
			$fs['done'] = true;
		}

		$gzip->finish();
		$appender->flush();
		$fs['offset'] = $appender->position();

		return array(
			'state'    => $fs,
			'warnings' => $warnings,
		);
	}

	/**
	 * Copies the remaining bytes of the open tar entry from $handle.
	 *
	 * @return bool True when the entry data is complete (or the file ended), false when paused by the deadline.
	 */
	private static function copy( $handle, TarWriter $tar, float $deadline ): bool {
		while ( $tar->entryWritten() < $tar->entrySize() ) {
			$chunk = fread( $handle, (int) min( self::READ_CHUNK, $tar->entrySize() - $tar->entryWritten() ) );
			if ( false === $chunk || '' === $chunk ) {
				return true;
			}
			$tar->writeData( $chunk );
			if ( $tar->entryWritten() < $tar->entrySize() && microtime( true ) >= $deadline ) {
				return false;
			}
		}
		return true;
	}
}
