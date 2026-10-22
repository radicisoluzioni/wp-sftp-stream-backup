<?php
namespace WPSSB\Tests;

use PHPUnit\Framework\TestCase;
use WPSSB\Archive\TarWriter;
use WPSSB\Tests\Support\Fs;

class RestorePartsTest extends TestCase {
	protected function setUp(): void {
		if ( ! is_dir( RS_DIR ) ) {
			mkdir( RS_DIR, 0777, true );
		}
	}

	protected function tearDown(): void {
		Fs::rmrf( RS_DIR );
	}

	private function writeStream( string $logical, string $data, string $prefix = '', int $chunk = 97 ): string {
		$parts = array();
		$offset = 0;
		foreach ( '' === $data ? array() : str_split( $data, $chunk ) as $index => $bytes ) {
			$file = $logical . '.part-' . sprintf( '%08d', $index ) . '-0123456789abcdef';
			file_put_contents( RS_DIR . '/' . $prefix . $file, $bytes );
			$parts[] = array( 'file' => $file, 'size' => strlen( $bytes ), 'offset' => $offset, 'sha256' => hash( 'sha256', $bytes ) );
			$offset += strlen( $bytes );
		}
		$previous = '';
		foreach ( array_chunk( $parts, 256 ) as $number => $page ) {
			$json = json_encode( array( 'previous' => $previous, 'parts' => $page ), JSON_UNESCAPED_SLASHES );
			file_put_contents( RS_DIR . '/' . $prefix . $logical . '.index-' . sprintf( '%08d', $number ) . '.json', $json );
			$previous = hash( 'sha256', $json );
		}
		$json = json_encode( array( 'format' => 2, 'bytes' => strlen( $data ), 'count' => count( $parts ), 'sha256' => $previous ) );
		file_put_contents( RS_DIR . '/' . $prefix . $logical . '.parts.json', $json );
		return hash( 'sha256', $json );
	}

	private function backup( string $files, string $database, string $prefix = '', string $manifestName = 'manifest.json', int $chunk = 97 ): array {
		$manifest = array(
			'format' => 2,
			'object_prefix' => $prefix,
			'files' => array( 'parts_sha256' => $this->writeStream( RS_FILES, $files, $prefix, $chunk ), 'bytes' => strlen( $files ) ),
			'database' => array( 'parts_sha256' => $this->writeStream( RS_DB, $database, $prefix, $chunk ), 'bytes' => strlen( $database ) ),
		);
		file_put_contents( RS_DIR . '/' . $manifestName, json_encode( $manifest ) );
		return $manifest;
	}

	private function state(): array {
		return \rs_new_state( array( 'url' => '', 'db_host' => '', 'db_name' => '', 'db_user' => '', 'db_pass' => '' ) );
	}

	public function testArbitraryBoundariesMultiplePagesMembersAndResume(): void {
		$a = random_bytes( 16000 ) . str_repeat( "one\n", 500 );
		$b = random_bytes( 16000 ) . str_repeat( "two\n", 500 );
		$plain = $a . $b;
		$compressed = gzencode( $a ) . gzencode( '' ) . gzencode( $b );
		$this->writeStream( RS_FILES, $compressed, '', 37 );
		$source = new \RsPartsSource( RS_DIR . '/' . RS_FILES );
		$reader = new \RsGzReader( $source, 0, 0, 0 );
		$this->assertSame( substr( $plain, 0, 20000 ), $reader->read( 20000 ) );
		$pos = $reader->position();
		list( $c, $u ) = $reader->checkpoint( $pos );
		$resumed = new \RsGzReader( new \RsPartsSource( RS_DIR . '/' . RS_FILES ), $c, $u, $pos );
		$this->assertSame( substr( $plain, $pos ), $resumed->read( strlen( $plain ) ) );
		$this->assertSame( '', $resumed->read( 1 ) );
		$this->assertSame( strlen( $compressed ), $resumed->compressedPosition() );
		$source->seek( 17000 );
		$this->assertSame( substr( $compressed, 17000, 500 ), $source->read( 500 ) );
		$source->seek( 3 );
		$this->assertSame( substr( $compressed, 3, 200 ), $source->read( 200 ) );
		$this->assertFileDoesNotExist( RS_DIR . '/' . RS_FILES );
	}

	public function testPartHashIsCheckedBeforeAnyBytesAreReturned(): void {
		$this->writeStream( RS_FILES, 'abcdefgh', '', 4 );
		$file = RS_DIR . '/' . RS_FILES . '.part-00000001-0123456789abcdef';
		file_put_contents( $file, 'xxxx' );
		$source = new \RsPartsSource( RS_DIR . '/' . RS_FILES );
		$this->assertSame( 'abcd', $source->read( 4 ) );
		$this->expectExceptionMessage( 'checksum mismatch' );
		$source->read( 1 );
	}

	public function testMaximumSizePartAndSeekAcrossItsBoundary(): void {
		$data = str_repeat( 'x', \RsPartsSource::PART_BYTES ) . 'last';
		$this->writeStream( RS_FILES, $data, '', \RsPartsSource::PART_BYTES );
		$source = new \RsPartsSource( RS_DIR . '/' . RS_FILES );
		$source->seek( \RsPartsSource::PART_BYTES - 2 );
		$this->assertSame( 'xxlast', $source->read( 10 ) );
		$source->seek( 1 );
		$this->assertSame( 'xxx', $source->read( 3 ) );
		$this->expectException( \RuntimeException::class );
		$source->seek( strlen( $data ) + 1 );
	}

	public function testEmptyHeadIsAValidEmptyLogicalSourceButNotAGzipArchive(): void {
		$this->writeStream( RS_FILES, '' );
		$source = new \RsPartsSource( RS_DIR . '/' . RS_FILES );
		$this->assertSame( 0, $source->size() );
		$source->seek( 0 );
		$this->assertSame( '', $source->read( 10 ) );
		$this->expectException( \RuntimeException::class );
		new \RsGzReader( $source, 0, 0, 0 );
	}

	public function testMissingHeadIsNotAnEmptyStream(): void {
		$this->expectException( \RuntimeException::class );
		new \RsPartsSource( RS_DIR . '/' . RS_FILES );
	}

	/** @dataProvider invalidDescriptors */
	public function testInvalidDescriptorIsRejected( string $field, $value ): void {
		$this->writeStream( RS_FILES, 'abcdef' );
		$file = RS_DIR . '/' . RS_FILES . '.index-00000000.json';
		$page = json_decode( file_get_contents( $file ), true );
		$page['parts'][0][ $field ] = $value;
		$json = json_encode( $page );
		file_put_contents( $file, $json );
		$headFile = RS_DIR . '/' . RS_FILES . '.parts.json';
		$head = json_decode( file_get_contents( $headFile ), true );
		$head['sha256'] = hash( 'sha256', $json );
		file_put_contents( $headFile, json_encode( $head ) );
		$this->expectException( \RuntimeException::class );
		new \RsPartsSource( RS_DIR . '/' . RS_FILES );
	}

	public function invalidDescriptors(): array {
		return array(
			'traversal' => array( 'file', '../elsewhere' ),
			'windows traversal' => array( 'file', '..\\elsewhere' ),
			'absolute' => array( 'file', '/elsewhere' ),
			'drive' => array( 'file', 'C:elsewhere' ),
			'null' => array( 'file', "a\0b" ),
			'empty' => array( 'file', '' ),
			'negative offset' => array( 'offset', -1 ),
			'gap' => array( 'offset', 1 ),
			'float offset' => array( 'offset', 0.5 ),
			'string size' => array( 'size', '6' ),
			'zero size' => array( 'size', 0 ),
			'oversize' => array( 'size', 8388609 ),
			'bad hash' => array( 'sha256', 'not a hash' ),
		);
	}

	/** @dataProvider brokenCatalogs */
	public function testBrokenCatalogOrMissingPayloadFailsPreflight( string $fault ): void {
		$this->writeStream( RS_FILES, str_repeat( 'x', 300 ), '', 1 );
		$page0 = RS_DIR . '/' . RS_FILES . '.index-00000000.json';
		$page1 = RS_DIR . '/' . RS_FILES . '.index-00000001.json';
		$headFile = RS_DIR . '/' . RS_FILES . '.parts.json';
		$part = RS_DIR . '/' . RS_FILES . '.part-00000299-0123456789abcdef';
		if ( 'missing page' === $fault ) {
			unlink( $page1 );
		} elseif ( 'missing payload' === $fault ) {
			unlink( $part );
		} elseif ( 'truncated payload' === $fault ) {
			file_put_contents( $part, '' );
		} elseif ( 'oversize page' === $fault ) {
			file_put_contents( $page0, str_repeat( ' ', \RsPartsSource::PAGE_BYTES + 1 ) );
		} elseif ( 'page zero hash' === $fault ) {
			file_put_contents( $page0, file_get_contents( $page0 ) . "\n" );
		} elseif ( 'tail hash' === $fault ) {
			file_put_contents( $page1, file_get_contents( $page1 ) . "\n" );
		} elseif ( 'malformed page' === $fault ) {
			file_put_contents( $page0, '{' );
		} else {
			$head = json_decode( file_get_contents( $headFile ), true );
			$head[ $fault ]++;
			file_put_contents( $headFile, json_encode( $head ) );
		}
		$this->expectException( \RuntimeException::class );
		new \RsPartsSource( RS_DIR . '/' . RS_FILES );
	}

	public function brokenCatalogs(): array {
		return array_map( function ( $fault ) { return array( $fault ); }, array(
			'missing page', 'missing payload', 'truncated payload', 'oversize page',
			'page zero hash', 'tail hash', 'malformed page', 'bytes', 'count',
		) );
	}

	public function testManifestBindsHeadAndRequiresRootHashes(): void {
		$manifest = $this->backup( gzencode( 'files' ), gzencode( 'database' ) );
		$head = RS_DIR . '/' . RS_FILES . '.parts.json';
		file_put_contents( $head, file_get_contents( $head ) . "\n" );
		$checks = \rs_requirements();
		$failed = array_values( array_filter( $checks, function ( $check ) { return RS_FILES === $check[0]; } ) );
		$this->assertFalse( $failed[0][1] );
		$this->assertStringContainsString( 'checksum', $failed[0][2] );
		unset( $manifest['files']['parts_sha256'] );
		file_put_contents( RS_DIR . '/manifest.json', json_encode( $manifest ) );
		$this->expectExceptionMessage( 'head checksum' );
		\rs_archive_source( RS_DIR . '/' . RS_FILES );
	}

	public function testPublicPrefixAndRenamedManifestAndSqlStream(): void {
		$sql = "-- comment\nDROP TABLE IF EXISTS `wp_test`;\nCREATE TABLE `wp_test` (`id` int);\nINSERT INTO `wp_test` VALUES (1);\n";
		$prefix = 'backupid--replicagen--';
		$this->backup( gzencode( str_repeat( "\0", 1024 ) ), gzencode( $sql ), $prefix );
		$reader = new \RsGzReader( RS_DIR . '/' . RS_DB, 0, 0, 0 );
		$this->assertSame( $sql, $reader->read( 1000 ) );
		rename( RS_DIR . '/manifest.json', RS_DIR . '/' . $prefix . 'manifest.json' );
		$this->assertSame( $prefix, \rs_manifest()['object_prefix'] );
		$this->assertSame( $sql, ( new \RsGzReader( RS_DIR . '/' . RS_DB, 0, 0, 0 ) )->read( 1000 ) );
		$this->assertFileDoesNotExist( RS_DIR . '/' . RS_DB );
	}

	public function testPrefixedScriptSelectsItsOwnManifestAndExplicitOverrideWorks(): void {
		$this->backup( gzencode( 'selected' ), gzencode( 'sql' ), 'selected--', 'selected--manifest.json' );
		file_put_contents( RS_DIR . '/manifest.json', '{"format":1,"id":"other"}' );
		$script = RS_DIR . '/selected--restore.php';
		copy( dirname( __DIR__ ) . '/restore/restore.php', $script );
		$code = 'define("WPSSB_RESTORE_LIBRARY_ONLY",true);require ' . var_export( $script, true ) . ';echo rs_manifest()["object_prefix"];';
		$output = shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $code ) );
		$this->assertSame( 'selected--', $output );
		$code = 'define("WPSSB_RESTORE_LIBRARY_ONLY",true);define("RS_MANIFEST","manifest.json");require ' . var_export( $script, true ) . ';echo rs_manifest()["id"];';
		$this->assertSame( 'other', shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $code ) ) );
		// A non-default explicit name also works when the script was renamed.
		rename( RS_DIR . '/selected--manifest.json', RS_DIR . '/chosen.json' );
		$code = 'define("WPSSB_RESTORE_LIBRARY_ONLY",true);define("RS_MANIFEST","chosen.json");require ' . var_export( $script, true ) . ';echo rs_manifest()["object_prefix"];';
		$this->assertSame( 'selected--', shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $code ) ) );
	}

	public function testUnsafePrefixAndAmbiguousManifestsFailClosed(): void {
		file_put_contents( RS_DIR . '/a--manifest.json', '{"format":2}' );
		file_put_contents( RS_DIR . '/b--manifest.json', '{"format":2}' );
		try {
			\rs_manifest();
			$this->fail( 'Ambiguous manifests must not select a random generation.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Multiple', $e->getMessage() );
		}
		file_put_contents( RS_DIR . '/manifest.json', '{"format":2,"object_prefix":"../outside/"}' );
		$this->expectExceptionMessage( 'prefix' );
		\rs_manifest();
	}

	public function testExtractionProtectsEverySelectedObjectAndCanResume(): void {
		$prefix = 'backup--generation--';
		$protected = $prefix . RS_DB . '.part-00000000-0123456789abcdef';
		$tar = '';
		$writer = new TarWriter( function ( $data ) use ( &$tar ) { $tar .= $data; } );
		foreach ( array( $protected => 'overwrite', $protected . '/child' => 'overwrite', 'restored.txt' => str_repeat( 'hello', 10000 ) ) as $name => $data ) {
			$writer->beginEntry( $name, strlen( $data ), 0644, 1700000000 );
			$writer->writeData( $data );
			$writer->endEntry();
		}
		$writer->finishArchive();
		$this->backup( gzencode( $tar ), gzencode( 'SELECT 1;' ), $prefix );
		$original = file_get_contents( RS_DIR . '/' . $protected );
		$state = $this->state();
		$iterations = 0;
		do {
			$done = \rs_extract( $state, microtime( true ) - 1 );
			$this->assertLessThan( 30, ++$iterations );
		} while ( ! $done );
		$this->assertSame( str_repeat( 'hello', 10000 ), file_get_contents( RS_DIR . '/restored.txt' ) );
		$this->assertSame( $original, file_get_contents( RS_DIR . '/' . $protected ) );
		$this->assertSame( 2, $state['tar']['skipped'] );
	}

	public function testTarTerminatorDoesNotHideCorruptTrailingPayload(): void {
		$data = gzencode( str_repeat( "\0", 1024 ) ) . gzencode( random_bytes( 60000 ) );
		$this->backup( $data, gzencode( 'sql' ), '', 'manifest.json', 8192 );
		$number = intdiv( strlen( $data ) - 1, 8192 );
		$file = RS_DIR . '/' . RS_FILES . '.part-' . sprintf( '%08d', $number ) . '-0123456789abcdef';
		$bytes = file_get_contents( $file );
		$bytes[0] = chr( ord( $bytes[0] ) ^ 1 );
		file_put_contents( $file, $bytes );
		$state = $this->state();
		$this->expectExceptionMessage( 'checksum mismatch' );
		while ( ! \rs_extract( $state, microtime( true ) - 1 ) ) {
		}
	}

	public function testDeleteArchivesRemovesOnlySelectedCommittedGeneration(): void {
		$prefix = 'selected--generation--';
		$this->backup( gzencode( 'files' ), gzencode( 'database' ), $prefix );
		$this->backup( gzencode( 'other files' ), gzencode( 'other database' ), 'other--generation--', 'other--generation--manifest.json' );
		file_put_contents( RS_DIR . '/files.tar.gz', 'legacy' );
		file_put_contents( RS_DIR . '/' . $prefix . 'uncommitted-part', 'orphan' );
		$names = array();
		foreach ( \rs_parts_sources() as $source ) {
			foreach ( $source->objectNames() as $name ) {
				$names[] = $name;
			}
		}
		$state = $this->state();
		$state['delete_archives'] = true;
		\rs_cleanup( $state, false );
		foreach ( $names as $name ) {
			$this->assertFileDoesNotExist( RS_DIR . '/' . $name );
		}
		$this->assertFileDoesNotExist( RS_DIR . '/manifest.json' );
		$this->assertFileExists( RS_DIR . '/other--generation--manifest.json' );
		$this->assertFileExists( RS_DIR . '/other--generation--' . RS_FILES . '.parts.json' );
		$this->assertSame( 'legacy', file_get_contents( RS_DIR . '/files.tar.gz' ) );
		$this->assertFileExists( RS_DIR . '/' . $prefix . 'uncommitted-part' );
	}

	public function testCleanupRefusesAChangedGeneration(): void {
		$this->backup( gzencode( 'files' ), gzencode( 'database' ) );
		$state = $this->state();
		$state['delete_archives'] = true;
		file_put_contents( RS_DIR . '/manifest.json', file_get_contents( RS_DIR . '/manifest.json' ) . "\n" );
		$this->expectExceptionMessage( 'manifest changed' );
		\rs_cleanup( $state, false );
	}

	public function testLegacyFormatOneStillUsesPhysicalGzipFiles(): void {
		file_put_contents( RS_DIR . '/' . RS_FILES, gzencode( 'legacy files' ) );
		file_put_contents( RS_DIR . '/' . RS_DB, gzencode( 'legacy database' ) );
		file_put_contents( RS_DIR . '/manifest.json', '{"format":1}' );
		$this->assertSame( 'legacy files', ( new \RsGzReader( RS_DIR . '/' . RS_FILES, 0, 0, 0 ) )->read( 100 ) );
		$this->assertSame( array(), \rs_parts_sources() );
		\rs_cleanup( array( 'delete_archives' => true ), false );
		$this->assertFileDoesNotExist( RS_DIR . '/' . RS_FILES );
		$this->assertFileDoesNotExist( RS_DIR . '/' . RS_DB );
	}

	public function testLegacyExtractorDoesNotRequireDatabaseArchive(): void {
		$tar = '';
		$writer = new TarWriter( function ( $data ) use ( &$tar ) { $tar .= $data; } );
		$writer->beginEntry( 'legacy.txt', 5, 0644, 1700000000 );
		$writer->writeData( 'hello' );
		$writer->endEntry();
		$writer->finishArchive();
		file_put_contents( RS_DIR . '/' . RS_FILES, gzencode( $tar ) );
		file_put_contents( RS_DIR . '/manifest.json', '{}' );
		$state = $this->state();
		while ( ! \rs_extract( $state, microtime( true ) - 1 ) ) {
			$state = json_decode( json_encode( $state ), true );
		}
		$this->assertSame( 'hello', file_get_contents( RS_DIR . '/legacy.txt' ) );
		$this->assertFileDoesNotExist( RS_DIR . '/' . RS_DB );
		$this->assertSame( 0, $state['tar']['skipped'] );
	}
}
