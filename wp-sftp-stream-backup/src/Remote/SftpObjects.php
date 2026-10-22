<?php
namespace WPSSB\Remote;

class SftpObjects implements ObjectStorage {
	private $storage;

	public function __construct( RemoteStorage $storage ) {
		$this->storage = $storage;
	}

	public function capabilities(): array {
		return array( 'read' => true, 'list' => true, 'delete' => true, 'overwrite' => true );
	}

	public function put( string $key, string $data ): void {
		\WPSSB\Job\Lock::heartbeat();
		if ( strlen( $data ) > SegmentedStorage::PART_BYTES ) {
			throw new \RuntimeException( 'Object exceeds the 8 MiB upload limit.' );
		}
		$this->storage->mkdir( dirname( $key ) );
		$this->storage->putContents( $key, $data );
	}

	public function get( string $key ): ?string {
		\WPSSB\Job\Lock::heartbeat();
		$size = $this->storage->size( $key );
		if ( null === $size ) {
			return null;
		}
		if ( $size > SegmentedStorage::PART_BYTES ) {
			throw new \RuntimeException( 'Remote object exceeds the 8 MiB read limit.' );
		}
		$data = $this->storage->getContents( $key );
		if ( null === $data || strlen( $data ) !== $size ) {
			throw new \RuntimeException( 'Cannot read complete SFTP object.' );
		}
		return $data;
	}

	public function stat( string $key ): ?array {
		\WPSSB\Job\Lock::heartbeat();
		$size = $this->storage->size( $key );
		return null === $size ? null : array( 'size' => $size, 'mtime' => 0 );
	}

	public function objects( string $prefix, ?string $cursor = null ): array {
		\WPSSB\Job\Lock::heartbeat();
		$dir = rtrim( $prefix, '/' );
		$items = array();
		foreach ( $this->storage->listDir( $dir ) as $name => $info ) {
			if ( 'file' === $info['type'] ) {
				$items[ $dir . '/' . $name ] = array( 'size' => $info['size'], 'mtime' => $info['mtime'] );
			}
		}
		ksort( $items, SORT_STRING );
		return array( 'items' => $items, 'next' => null );
	}

	public function remove( string $key ): void {
		\WPSSB\Job\Lock::heartbeat();
		if ( null !== $this->storage->size( $key ) ) {
			$this->storage->delete( $key );
		}
	}

	public function disconnect(): void {
		$this->storage->disconnect();
	}
}
