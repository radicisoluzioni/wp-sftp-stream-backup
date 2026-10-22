<?php
/**
 * End-to-end test of the files phase with interrupted steps, verified with
 * GNU tar and with the extractor of restore.php.
 *
 * @package WPSSB
 */

namespace WPSSB\Tests;

use PHPUnit\Framework\TestCase;
use WPSSB\Files\Exclusions;
use WPSSB\Job\FatalJobException;
use WPSSB\Job\FilesPhase;
use WPSSB\Job\Phase;
use WPSSB\Tests\Support\Fs;
use WPSSB\Tests\Support\LocalStorage;

class FilesPhaseTest extends TestCase {

	/** @var string[] */
	private $cleanup = array();

	protected function tearDown(): void {
		foreach ( $this->cleanup as $dir ) {
			Fs::rmrf( $dir );
		}
	}

	private function backup( string $src, Exclusions $ex, bool $chaos ): array {
		$remote  = Fs::tmp( 'remote' );
		$storage = new LocalStorage( $remote );
		$storage->mkdir( 'bk' );
		$this->cleanup[] = $remote;

		$fs    = FilesPhase::initialState();
		$steps = 0;
		while ( ! $fs['done'] ) {
			$steps++;
			$this->assertLessThan( 20000, $steps );
			$result = FilesPhase::run( $storage, 'bk', $fs, $src, $ex, 1, microtime( true ) );
			if ( $chaos && ! $result['state']['done'] && 0 === $steps % 3 ) {
				// Crash before committing: the uploaded member must be discarded on resume.
				continue;
			}
			if ( $chaos && ! $result['state']['done'] && 0 === $steps % 4 ) {
				// Partially uploaded garbage after the committed offset.
				$storage->writeAt( 'bk/files.tar.gz', random_bytes( 777 ), $result['state']['offset'] );
			}
			$fs = $result['state'];
		}
		$this->assertSame( $fs['offset'], $storage->size( 'bk/files.tar.gz' ) );
		return array( $storage->path( 'bk/files.tar.gz' ), $steps, $fs );
	}

	public function testInterruptedBackupProducesValidArchive(): void {
		$src             = Fs::tmp( 'src' );
		$this->cleanup[] = $src;
		Fs::sampleTree( $src );
		$ex = new Exclusions( array( 'wp-content/cache', '*.log' ) );

		list( $archive, $steps, $fs ) = $this->backup( $src, $ex, true );
		$this->assertGreaterThan( 50, $steps, 'the backup should have needed several steps' );
		$this->assertGreaterThanOrEqual( 50, $fs['count'] );

		$out             = Fs::tmp( 'out' );
		$this->cleanup[] = $out;
		exec( 'gzip -t ' . escapeshellarg( $archive ) . ' 2>&1', $lines, $rc );
		$this->assertSame( 0, $rc, implode( "\n", $lines ) );
		exec( 'tar -xzf ' . escapeshellarg( $archive ) . ' -C ' . escapeshellarg( $out ) . ' 2>&1', $lines, $rc );
		$this->assertSame( 0, $rc, implode( "\n", $lines ) );

		$expected = Fs::snapshot( $src, array( 'wp-content/cache', 'wp-content/debug.log' ) );
		$this->assertSame( $expected, Fs::snapshot( $out ) );
	}

	public function testRestoreExtractorWithTinySteps(): void {
		$src             = Fs::tmp( 'src' );
		$this->cleanup[] = $src;
		Fs::sampleTree( $src );
		list( $archive ) = $this->backup( $src, new Exclusions( array() ), false );

		Fs::rmrf( RS_DIR );
		mkdir( RS_DIR, 0777, true );
		$this->cleanup[] = RS_DIR;
		copy( $archive, RS_DIR . '/' . RS_FILES );
		file_put_contents( RS_DIR . '/' . RS_MANIFEST, '{}' );

		$state = rs_new_state(
			array(
				'db_host' => 'x',
				'db_name' => 'x',
				'db_user' => 'x',
				'db_pass' => '',
				'url'     => '',
			)
		);
		$steps = 0;
		while ( ! rs_extract( $state, microtime( true ) ) ) {
			$state = json_decode( json_encode( $state ), true );
			$steps++;
			$this->assertLessThan( 50000, $steps );
		}
		$this->assertGreaterThan( 50, $steps );
		$this->assertSame( 0, $state['tar']['skipped'] );

		$expected = Fs::snapshot( $src );
		$this->assertSame( $expected, Fs::snapshot( RS_DIR, array( RS_FILES, RS_MANIFEST ) ) );
	}

	public function testReconcile(): void {
		$remote          = Fs::tmp( 'rec' );
		$this->cleanup[] = $remote;
		$storage         = new LocalStorage( $remote );

		Phase::reconcile( $storage, 'missing', 0 );
		$storage->putContents( 'f', '0123456789' );
		Phase::reconcile( $storage, 'f', 4 );
		$this->assertSame( '0123', $storage->getContents( 'f' ) );

		$this->expectException( FatalJobException::class );
		Phase::reconcile( $storage, 'f', 10 );
	}

	public function testRestoreRejectsUnsafePaths(): void {
		$this->assertNull( rs_safe_path( '../etc/passwd' ) );
		$this->assertNull( rs_safe_path( '/etc/passwd' ) );
		$this->assertNull( rs_safe_path( 'a/../../b' ) );
		$this->assertNull( rs_safe_path( 'C:/x' ) );
		$this->assertNull( rs_safe_path( "a\0b" ) );
		$this->assertSame( 'a/b/c', rs_safe_path( './a//b/c/' ) );
	}
}
