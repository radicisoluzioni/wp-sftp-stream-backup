<?php
/**
 * Shared helpers for phases that append gzip members to a remote file.
 *
 * @package WPSSB
 */

namespace WPSSB\Job;

use WPSSB\Remote\RemoteStorage;

class Phase {

	/**
	 * Makes sure the remote file ends exactly at the last committed offset.
	 *
	 * A step interrupted after uploading part of a gzip member leaves extra
	 * bytes at the end of the file; those are cut away before resuming.
	 */
	public static function reconcile( RemoteStorage $storage, string $path, int $committed ): void {
		$size = $storage->size( $path );
		if ( null === $size ) {
			if ( $committed > 0 ) {
				throw new FatalJobException( sprintf( 'Remote file %s disappeared (expected %d bytes).', $path, $committed ) );
			}
			return;
		}
		if ( $size > $committed ) {
			$storage->truncate( $path, $committed );
			$check = $storage->size( $path );
			if ( $check !== $committed ) {
				throw new \RuntimeException( sprintf( 'Remote file %s could not be truncated to %d bytes.', $path, $committed ) );
			}
		} elseif ( $size < $committed ) {
			throw new FatalJobException( sprintf( 'Remote file %s is shorter than expected (%d < %d bytes).', $path, $size, $committed ) );
		}
	}
}
