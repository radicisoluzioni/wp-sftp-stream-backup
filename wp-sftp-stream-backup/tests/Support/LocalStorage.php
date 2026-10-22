<?php
/**
 * RemoteStorage backed by a local directory (test double for SFTP).
 *
 * @package WPSSB
 */

namespace WPSSB\Tests\Support;

use WPSSB\Remote\RemoteException;
use WPSSB\Remote\RemoteStorage;

class LocalStorage implements RemoteStorage {

	/** @var string */
	private $root;

	/** @var int */
	public $writes = 0;

	public function __construct( string $root ) {
		$this->root = rtrim( $root, '/' );
		if ( ! is_dir( $this->root ) ) {
			mkdir( $this->root, 0777, true );
		}
	}

	public function path( string $path ): string {
		return $this->root . '/' . ltrim( $path, '/' );
	}

	public function mkdir( string $path ): void {
		$abs = $this->path( $path );
		if ( ! is_dir( $abs ) ) {
			mkdir( $abs, 0777, true );
		}
	}

	public function size( string $path ): ?int {
		$abs = $this->path( $path );
		clearstatcache( true, $abs );
		return file_exists( $abs ) ? (int) filesize( $abs ) : null;
	}

	public function truncate( string $path, int $size ): void {
		$h = fopen( $this->path( $path ), 'r+b' );
		ftruncate( $h, $size );
		fclose( $h );
	}

	public function writeAt( string $path, string $data, int $offset ): void {
		$abs = $this->path( $path );
		$h   = fopen( $abs, file_exists( $abs ) ? 'r+b' : 'w+b' );
		if ( ! $h ) {
			throw new RemoteException( 'cannot open ' . $path );
		}
		fseek( $h, $offset );
		fwrite( $h, $data );
		fclose( $h );
		$this->writes++;
	}

	public function putContents( string $path, string $data ): void {
		file_put_contents( $this->path( $path ), $data );
	}

	public function getContents( string $path ): ?string {
		$abs = $this->path( $path );
		return is_file( $abs ) ? (string) file_get_contents( $abs ) : null;
	}

	public function listDir( string $path ): array {
		$out = array();
		foreach ( scandir( $this->path( $path ) ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$abs          = $this->path( $path ) . '/' . $name;
			$out[ $name ] = array(
				'type'  => is_dir( $abs ) ? 'dir' : 'file',
				'size'  => is_file( $abs ) ? filesize( $abs ) : 0,
				'mtime' => filemtime( $abs ),
			);
		}
		return $out;
	}

	public function delete( string $path ): void {
		Fs::rmrf( $this->path( $path ) );
	}

	public function disconnect(): void {
	}
}
