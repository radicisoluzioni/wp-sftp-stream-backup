<?php
namespace WPSSB\Remote;

/** Presents the existing phase checkpoint protocol over bounded immutable objects. */
class SegmentedStorage implements RemoteStorage {
	const PART_BYTES = 8388608;
	const PAGE_PARTS = 256;
	private $objects;

	public function __construct( ObjectStorage $objects ) {
		$this->objects = $objects;
	}

	public function objects(): ObjectStorage {
		return $this->objects;
	}

	public static function decode( ?string $json ): array {
		if ( null === $json ) {
			throw new \RuntimeException( 'Backup catalog is missing.' );
		}
		$value = json_decode( $json, true );
		if ( ! is_array( $value ) || JSON_ERROR_NONE !== json_last_error() ) {
			throw new \RuntimeException( 'Invalid backup catalog JSON.' );
		}
		return $value;
	}

	private function head( string $path ): array {
		$json = $this->objects->get( $path . '.parts.json' );
		return null === $json ? array( 'format' => 2, 'bytes' => 0, 'count' => 0, 'sha256' => '' ) : self::decode( $json );
	}

	public static function pageName( string $path, int $page ): string {
		return $path . '.index-' . sprintf( '%08d', $page ) . '.json';
	}

	public function mkdir( string $path ): void {
		// Object namespaces do not need physical directories.
	}

	public function size( string $path ): ?int {
		if ( '.gz' === substr( $path, -3 ) ) {
			$data = $this->objects->get( $path . '.parts.json' );
			return null === $data ? null : (int) self::decode( $data )['bytes'];
		}
		$stat = $this->objects->stat( $path );
		return null === $stat ? null : $stat['size'];
	}

	public function writeAt( string $path, string $data, int $offset ): void {
		$head = $this->head( $path );
		if ( $offset !== (int) $head['bytes'] ) {
			throw new \RuntimeException( 'Segment write must follow the confirmed remote end.' );
		}
		while ( '' !== $data ) {
			$chunk = substr( $data, 0, self::PART_BYTES );
			$data = substr( $data, strlen( $chunk ) );
			$index = (int) $head['count'];
			$page_no = intdiv( $index, self::PAGE_PARTS );
			$within = $index % self::PAGE_PARTS;
			$page_path = self::pageName( $path, $page_no );
			$page = $within ? self::decode( $this->objects->get( $page_path ) ) : array( 'previous' => $head['sha256'], 'parts' => array() );
			$page['parts'] = array_slice( $page['parts'], 0, $within );
			if ( count( $page['parts'] ) !== $within ) {
				throw new \RuntimeException( 'Backup catalog lost committed parts.' );
			}
			$name = basename( $path ) . '.part-' . sprintf( '%08d', $index ) . '-' . bin2hex( random_bytes( 8 ) );
			$this->objects->put( dirname( $path ) . '/' . $name, $chunk );
			$page['parts'][] = array( 'file' => $name, 'offset' => (int) $head['bytes'], 'size' => strlen( $chunk ), 'sha256' => hash( 'sha256', $chunk ) );
			$json = self::json( $page );
			$this->objects->put( $page_path, $json );
			$head['count']++;
			$head['bytes'] += strlen( $chunk );
			$head['sha256'] = hash( 'sha256', $json );
			$this->objects->put( $path . '.parts.json', self::json( $head ) );
		}
	}

	public static function json( array $value ): string {
		$json = json_encode( $value, JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			throw new \RuntimeException( 'Cannot encode backup metadata: ' . json_last_error_msg() );
		}
		return $json;
	}

	public function truncate( string $path, int $size ): void {
		$head = $this->head( $path );
		if ( $size < 0 || $size > $head['bytes'] ) {
			throw new \RuntimeException( 'Invalid segmented checkpoint.' );
		}
		$count = (int) $head['count'];
		while ( $count > 0 ) {
			$page_no = intdiv( $count - 1, self::PAGE_PARTS );
			$page_path = self::pageName( $path, $page_no );
			$page = self::decode( $this->objects->get( $page_path ) );
			$parts = array_slice( $page['parts'], 0, min( $count - $page_no * self::PAGE_PARTS, self::PAGE_PARTS ) );
			$keep = array();
			foreach ( $parts as $part ) {
				if ( $part['offset'] + $part['size'] <= $size ) {
					$keep[] = $part;
				}
			}
			if ( $keep ) {
				$last = end( $keep );
				if ( $last['offset'] + $last['size'] !== $size ) {
					throw new \RuntimeException( 'Checkpoint is not on a segment boundary.' );
				}
				$page['parts'] = $keep;
				$json = self::json( $page );
				$this->objects->put( $page_path, $json );
				$head['count'] = $page_no * self::PAGE_PARTS + count( $keep );
				$head['bytes'] = $size;
				$head['sha256'] = hash( 'sha256', $json );
				$this->objects->put( $path . '.parts.json', self::json( $head ) );
				return;
			}
			$count = $page_no * self::PAGE_PARTS;
		}
		if ( 0 !== $size ) {
			throw new \RuntimeException( 'Cannot locate committed segment.' );
		}
		$this->objects->put( $path . '.parts.json', self::json( array( 'format' => 2, 'bytes' => 0, 'count' => 0, 'sha256' => '' ) ) );
	}

	public function putContents( string $path, string $data ): void {
		$this->objects->put( $path, $data );
	}

	public function getContents( string $path ): ?string {
		return $this->objects->get( $path );
	}

	public function listDir( string $path ): array {
		$out = array();
		$cursor = null;
		do {
			$page = $this->objects->objects( rtrim( $path, '/' ) . '/', $cursor );
			foreach ( $page['items'] as $key => $info ) {
				$out[ basename( $key ) ] = array( 'type' => 'file', 'size' => $info['size'], 'mtime' => $info['mtime'] );
			}
			$cursor = $page['next'];
		} while ( null !== $cursor );
		return $out;
	}

	public function delete( string $path ): void {
		// Remove catalogued payload first: WebDAV/SFTP listings are not paginated.
		$marker_key = rtrim( $path, '/' ) . '/manifest.deleting.json';
		$marker_json = $this->objects->get( $marker_key );
		$marker = null === $marker_json ? null : self::decode( $marker_json );
		$manifest_data = $this->objects->get( rtrim( $path, '/' ) . '/manifest.json' );
		if ( null !== $manifest_data && null === $marker ) {
			$manifest = self::decode( $manifest_data );
			$marker = array( 'manifest' => $manifest, 'stage' => 'payload' );
			$this->objects->put( $marker_key, self::json( $marker ) );
		}
		if ( null !== $marker ) {
			$this->objects->remove( rtrim( $path, '/' ) . '/manifest.json' );
			$manifest = $marker['manifest'];
			if ( 'payload' === $marker['stage'] && 2 === ( $manifest['format'] ?? 0 ) ) {
				foreach ( array( 'database' => 'database.sql.gz', 'files' => 'files.tar.gz' ) as $section => $file ) {
					$logical = rtrim( $path, '/' ) . '/' . $file;
					$head_json = $this->objects->get( $logical . '.parts.json' );
					if ( null === $head_json || ! hash_equals( (string) ( $manifest[ $section ]['parts_sha256'] ?? '' ), hash( 'sha256', $head_json ) ) ) {
						throw new \RuntimeException( 'Retention cannot trust the backup descriptor.' );
					}
					$head = self::decode( $head_json );
					$pages = (int) ceil( $head['count'] / self::PAGE_PARTS );
					$previous = '';
					for ( $i = 0; $i < $pages; $i++ ) {
						$json = $this->objects->get( self::pageName( $logical, $i ) );
						$page = self::decode( $json );
						if ( ! hash_equals( $previous, (string) $page['previous'] ) ) {
							throw new \RuntimeException( 'Retention catalog chain mismatch.' );
						}
						$previous = hash( 'sha256', $json );
					}
					if ( ! hash_equals( (string) $head['sha256'], $previous ) ) {
						throw new \RuntimeException( 'Retention catalog checksum mismatch.' );
					}
					for ( $i = 0; $i < $pages; $i++ ) {
						$page = self::decode( $this->objects->get( self::pageName( $logical, $i ) ) );
						foreach ( $page['parts'] as $part ) {
							$name = (string) $part['file'];
							if ( basename( $name ) !== $name || false !== strpos( $name, '..' ) || 0 !== strpos( $name, $file . '.part-' ) ) {
								throw new \RuntimeException( 'Invalid retention object name.' );
							}
							$this->objects->remove( rtrim( $path, '/' ) . '/' . $name );
						}
					}
				}
				$marker['stage'] = 'metadata';
				$this->objects->put( $marker_key, self::json( $marker ) );
			}
		}
		$cursor = null;
		do {
			$page = $this->objects->objects( rtrim( $path, '/' ) . '/', $cursor );
			foreach ( $page['items'] as $key => $info ) {
				if ( $key !== $marker_key ) {
					$this->objects->remove( $key );
				}
			}
			$cursor = $page['next'];
		} while ( null !== $cursor );
		if ( null !== $marker ) {
			$this->objects->remove( $marker_key );
		}
	}

	public function disconnect(): void {
		$this->objects->disconnect();
	}
}
