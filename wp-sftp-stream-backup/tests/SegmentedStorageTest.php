<?php
namespace WPSSB\Tests;

use PHPUnit\Framework\TestCase;
use WPSSB\Remote\ObjectStorage;
use WPSSB\Remote\SegmentedStorage;

class MemoryObjects implements ObjectStorage {
	public $data = array();
	public $fail_head = false;
	public $fail_remove = null;
	public $list_cap = 0;
	public function capabilities(): array { return array( 'read' => true, 'list' => true, 'delete' => true, 'overwrite' => true ); }
	public function put( string $key, string $data ): void {
		if ( $this->fail_head && '.parts.json' === substr( $key, -11 ) ) {
			throw new \RuntimeException( 'Simulated lost acknowledgement.' );
		}
		if ( strlen( $data ) > SegmentedStorage::PART_BYTES ) {
			throw new \RuntimeException( 'Oversized payload.' );
		}
		$this->data[ $key ] = $data;
	}
	public function get( string $key ): ?string { return $this->data[ $key ] ?? null; }
	public function stat( string $key ): ?array { return isset( $this->data[ $key ] ) ? array( 'size' => strlen( $this->data[ $key ] ), 'mtime' => 0 ) : null; }
	public function objects( string $prefix, ?string $cursor = null ): array {
		$items = array();
		foreach ( $this->data as $key => $data ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				$items[ $key ] = array( 'size' => strlen( $data ), 'mtime' => 0 );
			}
		}
		if ( $this->list_cap && count( $items ) > $this->list_cap ) {
			throw new \RuntimeException( 'Listing too large.' );
		}
		return array( 'items' => $items, 'next' => null );
	}
	public function remove( string $key ): void {
		if ( $key === $this->fail_remove ) {
			$this->fail_remove = null;
			throw new \RuntimeException( 'Injected deletion failure.' );
		}
		unset( $this->data[ $key ] );
	}
	public function disconnect(): void {}
}

class SegmentedStorageTest extends TestCase {
	public function testPartsPagesAndRollback(): void {
		$remote = new MemoryObjects();
		$storage = new SegmentedStorage( $remote );
		for ( $i = 0; $i < 260; $i++ ) {
			$storage->writeAt( 'backup/files.tar.gz', str_repeat( chr( $i % 255 ), 1024 ), $i * 1024 );
		}
		$this->assertSame( 260 * 1024, $storage->size( 'backup/files.tar.gz' ) );
		$storage->truncate( 'backup/files.tar.gz', 255 * 1024 );
		$storage->writeAt( 'backup/files.tar.gz', 'replacement', 255 * 1024 );
		$head = json_decode( $remote->data['backup/files.tar.gz.parts.json'], true );
		$page = json_decode( $remote->data['backup/files.tar.gz.index-00000000.json'], true );
		$this->assertSame( 256, $head['count'] );
		$this->assertCount( 256, $page['parts'] );
		$this->assertSame( hash( 'sha256', $remote->data['backup/files.tar.gz.index-00000000.json'] ), $head['sha256'] );
		$this->assertSame( 'replacement', $remote->data[ 'backup/' . $page['parts'][255]['file'] ] );
	}

	public function testCrashBeforeHeadSavePreservesEarlierCommittedParts(): void {
		$remote = new MemoryObjects();
		$storage = new SegmentedStorage( $remote );
		$storage->writeAt( 'b/database.sql.gz', 'committed', 0 );
		$remote->fail_head = true;
		try {
			$storage->writeAt( 'b/database.sql.gz', 'uncommitted', 9 );
			$this->fail( 'Expected simulated crash.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Simulated lost acknowledgement.', $e->getMessage() );
		}
		$remote->fail_head = false;
		$this->assertSame( 9, $storage->size( 'b/database.sql.gz' ) );
		$storage->writeAt( 'b/database.sql.gz', 'retry', 9 );
		$this->assertSame( 14, $storage->size( 'b/database.sql.gz' ) );
		$page = json_decode( $remote->data['b/database.sql.gz.index-00000000.json'], true );
		$this->assertCount( 2, $page['parts'] );
		$this->assertSame( 'retry', $remote->data[ 'b/' . $page['parts'][1]['file'] ] );
	}

	public function testOversizeInputIsSplit(): void {
		$remote = new MemoryObjects();
		$storage = new SegmentedStorage( $remote );
		$storage->writeAt( 'b/files.tar.gz', str_repeat( 'x', SegmentedStorage::PART_BYTES + 1 ), 0 );
		$head = json_decode( $remote->data['b/files.tar.gz.parts.json'], true );
		$this->assertSame( 2, $head['count'] );
		$storage->truncate( 'b/files.tar.gz', 0 );
		$this->assertSame( 0, $storage->size( 'b/files.tar.gz' ) );
	}

	public function testRetentionIsBoundedAndResumesWithoutPublishingPartialBackup(): void {
		$remote = new MemoryObjects();
		$storage = new SegmentedStorage( $remote );
		$storage->writeAt( 'b/database.sql.gz', 'db', 0 );
		for ( $i = 0; $i < 300; $i++ ) {
			$storage->writeAt( 'b/files.tar.gz', 'part', $i * 4 );
		}
		$manifest = array( 'format' => 2 );
		foreach ( array( 'database' => 'database.sql.gz', 'files' => 'files.tar.gz' ) as $section => $file ) {
			$manifest[ $section ]['parts_sha256'] = hash( 'sha256', $remote->data[ 'b/' . $file . '.parts.json' ] );
		}
		$remote->put( 'b/manifest.json', SegmentedStorage::json( $manifest ) );
		$page = json_decode( $remote->data['b/files.tar.gz.index-00000000.json'], true );
		$remote->fail_remove = 'b/' . $page['parts'][100]['file'];
		$remote->list_cap = 20;
		try {
			$storage->delete( 'b' );
			$this->fail( 'Expected deletion interruption.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Injected deletion failure.', $e->getMessage() );
		}
		$this->assertArrayNotHasKey( 'b/manifest.json', $remote->data );
		$this->assertArrayHasKey( 'b/manifest.deleting.json', $remote->data );
		$remote->put( 'another-backup/keep', 'safe' );
		$storage->delete( 'b' );
		$this->assertSame( array( 'another-backup/keep' => 'safe' ), $remote->data );
	}
}
