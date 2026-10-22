<?php
/**
 * @package WPSSB
 */

namespace WPSSB\Tests;

use PHPUnit\Framework\TestCase;
use WPSSB\Archive\TarWriter;
use WPSSB\Stream\GzipMemberWriter;
use WPSSB\Tests\Support\Fs;

class TarGzipTest extends TestCase {

	public function testMultiMemberGzipIsOneStream(): void {
		$out = '';
		$sink = function ( $b ) use ( &$out ) {
			$out .= $b;
		};
		foreach ( array( 'first ', 'second ', '', 'third' ) as $part ) {
			$gz = new GzipMemberWriter( $sink, 6 );
			$gz->write( $part );
			$gz->finish();
		}
		$file = tempnam( sys_get_temp_dir(), 'gz' );
		file_put_contents( $file, $out );
		$this->assertSame( 'first second third', shell_exec( 'gzip -dc ' . escapeshellarg( $file ) ) );
		$h = gzopen( $file, 'rb' );
		$this->assertSame( 'first second third', gzread( $h, 1000 ) );
		gzclose( $h );
		unlink( $file );
	}

	public function testLargeInputIsSplitIntoResumableMembers(): void {
		$out  = '';
		$gz   = new GzipMemberWriter(
			function ( $b ) use ( &$out ) {
				$out .= $b;
			},
			1,
			65536
		);
		$plain = '';
		for ( $i = 0; $i < 40; $i++ ) {
			$chunk  = random_bytes( 5000 ) . str_repeat( "data $i\n", 2000 );
			$plain .= $chunk;
			$gz->write( $chunk );
		}
		$gz->finish();
		$this->assertSame( strlen( $plain ), $gz->bytesIn() );
		$this->assertSame( sha1( $plain ), sha1( self::gunzipAll( $out ) ) );

		$file = tempnam( sys_get_temp_dir(), 'gzm' );
		file_put_contents( $file, $out );
		$reader = new \RsGzReader( $file, 0, 0, 0 );
		$reader->skip( strlen( $plain ) - 1000 );
		list( $c, $u ) = $reader->checkpoint( $reader->position() );
		$this->assertGreaterThan( 0, $c, 'resume point must be close to the requested position' );
		$this->assertGreaterThan( strlen( $plain ) - 65536 - 1000, $u );
		$resumed = new \RsGzReader( $file, $c, $u, strlen( $plain ) - 1000 );
		$this->assertSame( substr( $plain, -1000 ), $resumed->read( 5000 ) );
		unlink( $file );
	}

	private static function gunzipAll( string $data ): string {
		$file = tempnam( sys_get_temp_dir(), 'gza' );
		file_put_contents( $file, $data );
		$h   = gzopen( $file, 'rb' );
		$all = '';
		while ( ! gzeof( $h ) ) {
			$all .= gzread( $h, 65536 );
		}
		gzclose( $h );
		unlink( $file );
		return $all;
	}

	public function testTarReadableByGnuTar(): void {
		$dir  = Fs::tmp( 'tar' );
		$file = $dir . '/a.tar.gz';
		$out  = '';
		$gz   = null;
		$sink = function ( $b ) use ( &$gz ) {
			$gz->write( $b );
		};
		$gz_sink = function ( $b ) use ( &$out ) {
			$out .= $b;
		};

		$long_dir  = str_repeat( 'd', 90 ) . '/' . str_repeat( 'e', 90 );
		$very_long = str_repeat( 'x/', 150 ) . 'final-name.txt';
		$entries   = array(
			array( 'top/', 0, '5', '' ),
			array( 'top/hello.txt', 0, '0', 'hello world' ),
			array( 'top/empty.txt', 0, '0', '' ),
			array( $long_dir . '/' . str_repeat( 'n', 99 ), 0, '0', 'prefix split' ),
			array( $very_long, 0, '0', 'pax path' ),
			array( 'top/link', 0, '2', 'hello.txt' ),
		);

		$tar = new TarWriter( $sink );
		foreach ( $entries as $i => $e ) {
			// Every entry in its own gzip member, one entry split across two members.
			$gz = new GzipMemberWriter( $gz_sink, 1 );
			if ( '2' === $e[2] ) {
				$tar->beginEntry( $e[0], 0, 0777, 1700000000, '2', $e[3] );
				$tar->endEntry();
			} elseif ( '5' === $e[2] ) {
				$tar->beginEntry( $e[0], 0, 0755, 1700000000, '5' );
				$tar->endEntry();
			} else {
				$tar->beginEntry( $e[0], strlen( $e[3] ), 0644, 1700000000 );
				$half = (int) floor( strlen( $e[3] ) / 2 );
				$tar->writeData( substr( $e[3], 0, $half ) );
				$gz->finish();
				$gz = new GzipMemberWriter( $gz_sink, 1 );
				$tar->writeData( substr( $e[3], $half ) );
				$tar->endEntry();
			}
			$gz->finish();
		}
		$gz = new GzipMemberWriter( $gz_sink, 1 );
		$tar->finishArchive();
		$gz->finish();
		file_put_contents( $file, $out );

		mkdir( $dir . '/x' );
		exec( 'tar -xzf ' . escapeshellarg( $file ) . ' -C ' . escapeshellarg( $dir . '/x' ) . ' 2>&1', $lines, $rc );
		$this->assertSame( 0, $rc, implode( "\n", $lines ) );
		$this->assertSame( 'hello world', file_get_contents( $dir . '/x/top/hello.txt' ) );
		$this->assertSame( '', file_get_contents( $dir . '/x/top/empty.txt' ) );
		$this->assertSame( 'prefix split', file_get_contents( $dir . '/x/' . $long_dir . '/' . str_repeat( 'n', 99 ) ) );
		$this->assertSame( 'pax path', file_get_contents( $dir . '/x/' . $very_long ) );
		$this->assertSame( 'hello.txt', readlink( $dir . '/x/top/link' ) );
		$this->assertSame( 1700000000, filemtime( $dir . '/x/top/hello.txt' ) );
		Fs::rmrf( $dir );
	}

	public function testEntrySizeIsEnforced(): void {
		$out = '';
		$tar = new TarWriter(
			function ( $b ) use ( &$out ) {
				$out .= $b;
			}
		);
		$tar->beginEntry( 'grew.txt', 4, 0644, 0 );
		$this->assertSame( 4, $tar->writeData( 'abcdefgh' ) );
		$this->assertSame( 0, $tar->endEntry() );
		$tar->beginEntry( 'shrank.txt', 10, 0644, 0 );
		$tar->writeData( 'abc' );
		$this->assertSame( 7, $tar->endEntry() );
		$tar->finishArchive();
		$this->assertSame( 0, strlen( $out ) % 512 );
		$this->assertSame( 512 * 6, strlen( $out ) );
	}
}
