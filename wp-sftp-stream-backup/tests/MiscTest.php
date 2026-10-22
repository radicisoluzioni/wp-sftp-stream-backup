<?php
/**
 * @package WPSSB
 */

namespace WPSSB\Tests;

use PHPUnit\Framework\TestCase;
use WPSSB\Db\Dumper;
use WPSSB\Job\Retention;
use WPSSB\Remote\SftpStorage;
use WPSSB\Tests\Support\Fs;
use WPSSB\Tests\Support\LocalStorage;

class MiscTest extends TestCase {

	public function testDumperLiterals(): void {
		$d = new Dumper( new \stdClass(), Dumper::initialCursor( array() ) );
		$this->assertSame( 'NULL', $d->literal( null, 'str' ) );
		$this->assertSame( '42', $d->literal( '42', 'num' ) );
		$this->assertSame( '-1.5e3', $d->literal( '-1.5e3', 'num' ) );
		$this->assertSame( "'0x1A'", $d->literal( '0x1A', 'num' ) );
		$this->assertSame( "'1 OR 1'", $d->literal( '1 OR 1', 'num' ) );
		$this->assertSame( '0x00ff', $d->literal( "\x00\xff", 'bin' ) );
		$this->assertSame( "''", $d->literal( '', 'bin' ) );
		$this->assertSame( "'it\\'s \\\"x\\\"\\n\\r\\\\ \\0 \\Z %s'", $d->literal( "it's \"x\"\n\r\\ \0 \x1a %s", 'str' ) );
		$this->assertStringNotContainsString( "\n", $d->literal( "a\nb", 'str' ) );
	}

	public function testColumnKinds(): void {
		$this->assertSame( 'num', Dumper::columnKind( 'bigint(20) unsigned' ) );
		$this->assertSame( 'num', Dumper::columnKind( 'decimal(10,2)' ) );
		$this->assertSame( 'bin', Dumper::columnKind( 'longblob' ) );
		$this->assertSame( 'bin', Dumper::columnKind( 'varbinary(16)' ) );
		$this->assertSame( 'str', Dumper::columnKind( 'varchar(191)' ) );
		$this->assertSame( 'str', Dumper::columnKind( 'datetime' ) );
		$this->assertSame( 'str', Dumper::columnKind( 'json' ) );
		$this->assertSame( '`a``b`', Dumper::ident( 'a`b' ) );
	}

	public function testFingerprint(): void {
		$blob = random_bytes( 51 );
		$key  = 'ssh-ed25519 ' . base64_encode( $blob );
		$fp   = SftpStorage::fingerprint( $key );
		$this->assertSame( 'SHA256:' . rtrim( base64_encode( hash( 'sha256', $blob, true ) ), '=' ), $fp );
		$this->assertSame( $fp, SftpStorage::normalizeFingerprint( substr( $fp, 7 ) . '=' ) );
		$this->assertSame( '', SftpStorage::normalizeFingerprint( '  ' ) );
	}

	public function testRetention(): void {
		$root    = Fs::tmp( 'ret' );
		$storage = new LocalStorage( $root );
		$now     = gmmktime( 12, 0, 0, 10, 21, 2026 );
		$mk      = function ( $name, $complete ) use ( $storage ) {
			$storage->mkdir( 'b/' . $name );
			if ( $complete ) {
				$storage->putContents( 'b/' . $name . '/manifest.json', '{}' );
			}
		};
		$mk( 'site.test-20261021-110000-aaaaaa', false ); // current job.
		$mk( 'site.test-20261020-030000-bbbbbb', true );
		$mk( 'site.test-20261019-030000-cccccc', true );
		$mk( 'site.test-20261018-030000-dddddd', true );
		$mk( 'site.test-20261021-030000-eeeeee', false ); // recent incomplete: kept.
		$mk( 'site.test-20261010-030000-ffffff', false ); // old incomplete: deleted.
		$mk( 'other.test-20261001-030000-gggggg', true ); // other site: untouched.
		$mk( 'site.test-unrelated', true );

		$result = Retention::apply( $storage, 'b', 'site.test', 2, 'site.test-20261021-110000-aaaaaa', $now );
		sort( $result['deleted'] );
		$this->assertSame( array( 'site.test-20261010-030000-ffffff', 'site.test-20261018-030000-dddddd', 'site.test-20261019-030000-cccccc' ), $result['deleted'] );
		$left = array_keys( $storage->listDir( 'b' ) );
		sort( $left );
		$this->assertSame( array( 'other.test-20261001-030000-gggggg', 'site.test-20261020-030000-bbbbbb', 'site.test-20261021-030000-eeeeee', 'site.test-20261021-110000-aaaaaa', 'site.test-unrelated' ), $left );

		$this->assertSame( array(), Retention::apply( $storage, 'b', 'site.test', 0, 'x', $now )['deleted'] );
		Fs::rmrf( $root );
	}

	public function testRetentionDoesNotMatchAnotherSiteWithSharedPrefix(): void {
		$pattern = Retention::sitePattern( 'site.test' );
		$this->assertSame( 1, preg_match( $pattern, 'site.test-20261022-093000-abcdef' ) );
		$this->assertSame( 0, preg_match( $pattern, 'site.test-sub-20261022-093000-abcdef' ) );
		$this->assertSame( 0, preg_match( $pattern, "site.test-20261022-093000-abcdef\n" ) );
	}
}
