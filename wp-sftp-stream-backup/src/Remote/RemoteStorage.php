<?php
/**
 * Minimal remote storage abstraction used by the backup job.
 *
 * @package WPSSB
 */

namespace WPSSB\Remote;

interface RemoteStorage {

	/** Creates a directory (recursively). */
	public function mkdir( string $path ): void;

	/** Returns the file size or null when the file does not exist. */
	public function size( string $path ): ?int;

	/** Shrinks a file to the given size. */
	public function truncate( string $path, int $size ): void;

	/** Writes $data at byte $offset (creating the file if needed, never truncating). */
	public function writeAt( string $path, string $data, int $offset ): void;

	/** Creates/overwrites a whole file. */
	public function putContents( string $path, string $data ): void;

	/** Reads a whole (small) file, null if missing. */
	public function getContents( string $path ): ?string;

	/**
	 * Lists a directory.
	 *
	 * @return array<string, array{type:string, size:int, mtime:int}> name => info
	 */
	public function listDir( string $path ): array;

	/** Deletes a file or a directory recursively. */
	public function delete( string $path ): void;

	public function disconnect(): void;
}
