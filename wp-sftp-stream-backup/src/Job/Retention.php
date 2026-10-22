<?php
/**
 * Remote retention: keeps the newest N complete backups of this site.
 *
 * @package WPSSB
 */

namespace WPSSB\Job;

use WPSSB\Remote\RemoteStorage;

class Retention {

	const MANIFEST          = 'manifest.json';
	const INCOMPLETE_MAX_AGE = 172800;

	public static function sitePattern( string $slug ): string {
		return '/^' . preg_quote( $slug, '/' ) . '-(\d{8}-\d{6})-[a-z0-9]{6}$/D';
	}

	/**
	 * Lists backups of this site found in $base, newest first.
	 *
	 * @return array<int, array{name:string, time:int, complete:bool}>
	 */
	public static function listBackups( RemoteStorage $storage, string $base, string $slug ): array {
		$pattern = self::sitePattern( $slug );
		$out     = array();
		foreach ( $storage->listDir( $base ) as $name => $info ) {
			if ( 'dir' !== $info['type'] || ! preg_match( $pattern, $name, $m ) ) {
				continue;
			}
			$time  = \DateTime::createFromFormat( 'Ymd-His', $m[1], new \DateTimeZone( 'UTC' ) );
			$out[] = array(
				'name'     => $name,
				'time'     => $time ? $time->getTimestamp() : 0,
				'complete' => null !== $storage->size( $base . '/' . $name . '/' . self::MANIFEST ),
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				return strcmp( $b['name'], $a['name'] );
			}
		);
		return $out;
	}

	/**
	 * @return array{deleted: string[], warnings: string[]}
	 */
	public static function apply( RemoteStorage $storage, string $base, string $slug, int $keep, string $current, ?int $now = null ): array {
		$now      = null === $now ? time() : $now;
		$deleted  = array();
		$warnings = array();
		$kept     = 0;

		foreach ( self::listBackups( $storage, $base, $slug ) as $backup ) {
			if ( $backup['name'] === $current ) {
				$kept++;
				continue;
			}
			$delete = false;
			if ( $backup['complete'] ) {
				if ( $keep > 0 && $kept >= $keep ) {
					$delete = true;
				} else {
					$kept++;
				}
			} elseif ( $backup['time'] > 0 && $now - $backup['time'] > self::INCOMPLETE_MAX_AGE ) {
				$delete = true;
			}
			if ( $delete ) {
				try {
					$storage->delete( $base . '/' . $backup['name'] );
					$deleted[] = $backup['name'];
				} catch ( \Throwable $e ) {
					$warnings[] = 'Retention: cannot delete ' . $backup['name'] . ': ' . $e->getMessage();
				}
			}
		}
		return array(
			'deleted'  => $deleted,
			'warnings' => $warnings,
		);
	}
}
