<?php
/**
 * @package WPSSB
 */

namespace WPSSB\Tests;

use PHPUnit\Framework\TestCase;
use WPSSB\Remote\NextcloudStorage;
use WPSSB\Remote\RemoteException;

class NextcloudStorageTest extends TestCase {

	/** @var array<int,array{method:string,url:string,args:array}> */
	private $calls = array();

	/** @var array<int,mixed> */
	private $queue = array();

	private static function res( int $code, string $body = '', array $headers = array() ): array {
		return array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
		);
	}

	private function storage( array $cfg = array(), array $responses = array() ): NextcloudStorage {
		$this->calls = array();
		$this->queue = $responses;
		return new NextcloudStorage(
			array_merge(
				array(
					'url'      => 'https://cloud.example.com/nc/',
					'username' => 'jo doe@x',
					'password' => 'app-pass',
					'path'     => '/Backups/WP site/',
				),
				$cfg
			),
			function ( string $method, string $url, array $args ) {
				$this->calls[] = compact( 'method', 'url', 'args' );
				if ( ! $this->queue ) {
					$this->fail( 'Unexpected request ' . $method . ' ' . $url );
				}
				return array_shift( $this->queue );
			}
		);
	}

	private function publicStorage( array $responses = array(), string $password = 'sh4re' ): NextcloudStorage {
		return $this->storage(
			array(
				'public_url'      => 'https://cloud.example.com/nc/index.php/s/AbC123xyz',
				'public_password' => $password,
			),
			$responses
		);
	}

	private function assertThrowsRemote( callable $fn, string $needle ): void {
		try {
			$fn();
			$this->fail( 'Expected RemoteException containing ' . $needle );
		} catch ( RemoteException $e ) {
			$this->assertStringContainsString( $needle, $e->getMessage() );
		}
	}

	private const ROOT = 'https://cloud.example.com/nc/remote.php/dav/files/jo%20doe%40x/Backups/WP%20site/';

	public function testAuthPutUrlHeadersAndCapabilities(): void {
		$s = $this->storage( array(), array( self::res( 201 ) ) );
		$this->assertSame( array( 'read' => true, 'list' => true, 'delete' => true, 'overwrite' => true ), $s->capabilities() );
		$s->put( 'b1/a #?%.bin', "data\0" );
		$this->assertCount( 1, $this->calls );
		$c = $this->calls[0];
		$this->assertSame( 'PUT', $c['method'] );
		$this->assertSame( self::ROOT . 'b1/a%20%23%3F%25.bin', $c['url'] );
		$this->assertSame( "data\0", $c['args']['body'] );
		$this->assertSame( 'Basic ' . base64_encode( 'jo doe@x:app-pass' ), $c['args']['headers']['Authorization'] );
		$this->assertSame( 'application/octet-stream', $c['args']['headers']['Content-Type'] );
		$this->assertSame( 0, $c['args']['redirection'] );
		$this->assertTrue( $c['args']['sslverify'] );
	}

	public function testPutCreatesMissingCollectionsOnce(): void {
		$s = $this->storage( array(), array( self::res( 409 ), self::res( 405 ), self::res( 201 ), self::res( 201 ), self::res( 201 ), self::res( 204 ), self::res( 404 ), self::res( 201 ), self::res( 201 ) ) );
		$s->put( 'base/b1/x', 'a' );
		$this->assertSame(
			array(
				'PUT ' . self::ROOT . 'base/b1/x',
				'MKCOL https://cloud.example.com/nc/remote.php/dav/files/jo%20doe%40x/Backups/',
				'MKCOL https://cloud.example.com/nc/remote.php/dav/files/jo%20doe%40x/Backups/WP%20site/',
				'MKCOL ' . self::ROOT . 'base/',
				'MKCOL ' . self::ROOT . 'base/b1/',
				'PUT ' . self::ROOT . 'base/b1/x',
			),
			array_map( function ( $c ) { return $c['method'] . ' ' . $c['url']; }, array_slice( $this->calls, 0, 6 ) )
		);
		$s->put( 'base/b2/y', 'b' );
		$methods = array_map( function ( $c ) { return $c['method'] . ' ' . $c['url']; }, array_slice( $this->calls, 6 ) );
		$this->assertSame( array( 'PUT ' . self::ROOT . 'base/b2/y', 'MKCOL ' . self::ROOT . 'base/b2/', 'PUT ' . self::ROOT . 'base/b2/y' ), $methods );
	}

	public function testPutErrors(): void {
		$s = $this->storage( array(), array( self::res( 507 ) ) );
		$this->assertThrowsRemote( function () use ( $s ) { $s->put( 'k', 'x' ); }, 'quota exceeded' );
		$s = $this->storage( array(), array( self::res( 401 ) ) );
		$this->assertThrowsRemote( function () use ( $s ) { $s->put( 'k', 'x' ); }, 'app password' );
		$s = $this->storage( array(), array( self::res( 409 ), self::res( 201 ), self::res( 201 ), self::res( 403 ) ) );
		$this->assertThrowsRemote( function () use ( $s ) { $s->put( 'd/k', 'x' ); }, 'HTTP 403' );
		$s = $this->storage( array(), array( self::res( 301, '', array( 'Location' => 'https://evil/' ) ) ) );
		$this->assertThrowsRemote( function () use ( $s ) { $s->put( 'k', 'x' ); }, 'redirects are not followed' );
		$s = $this->storage( array(), array( new class() {
			public function get_error_message() {
				return 'cURL error 28';
			}
		} ) );
		$this->assertThrowsRemote( function () use ( $s ) { $s->put( 'k', 'x' ); }, 'cURL error 28' );
		$s = $this->storage( array(), array( array( 'body' => '' ) ) );
		$this->assertThrowsRemote( function () use ( $s ) { $s->put( 'k', 'x' ); }, 'invalid HTTP response' );
	}

	public function testInvalidKeysAreRejectedWithoutRequests(): void {
		$s = $this->storage();
		foreach ( array( '', '../x', 'a//b', '/a', 'a/', "a\nb", 'a\\b', 'a/./b' ) as $bad ) {
			$this->assertThrowsRemote( function () use ( $s, $bad ) { $s->put( $bad, 'x' ); }, 'Nextcloud' );
		}
		$this->assertSame( array(), $this->calls );
	}

	public function testGet(): void {
		$s = $this->storage( array(), array( self::res( 200, 'hello', array( 'Content-Length' => '5' ) ), self::res( 404 ), self::res( 403 ), self::res( 200, 'hel', array( 'content-length' => '5' ) ) ) );
		$this->assertSame( 'hello', $s->get( 'b/m.json' ) );
		$this->assertSame( 'GET', $this->calls[0]['method'] );
		$this->assertSame( self::ROOT . 'b/m.json', $this->calls[0]['url'] );
		$this->assertSame( NextcloudStorage::MAX_GET_BYTES + 1, $this->calls[0]['args']['limit_response_size'] );
		$this->assertNull( $s->get( 'b/m.json' ) );
		$this->assertThrowsRemote( function () use ( $s ) { $s->get( 'k' ); }, 'HTTP 403' );
		$this->assertThrowsRemote( function () use ( $s ) { $s->get( 'k' ); }, 'truncated' );

		$s = $this->storage( array(), array( self::res( 200, str_repeat( 'x', NextcloudStorage::MAX_GET_BYTES + 1 ) ), self::res( 200, 'x', array( 'Content-Length' => '99999999' ) ) ) );
		$this->assertThrowsRemote( function () use ( $s ) { $s->get( 'k' ); }, 'download limit' );
		$this->assertThrowsRemote( function () use ( $s ) { $s->get( 'k' ); }, 'download limit' );
	}

	public function testStat(): void {
		$s = $this->storage(
			array(),
			array(
				self::res( 200, '', array( 'Content-Length' => '42', 'Last-Modified' => 'Wed, 21 Oct 2026 10:00:00 GMT' ) ),
				self::res( 404 ),
				self::res( 500 ),
				self::res( 200 ),
			)
		);
		$this->assertSame( array( 'size' => 42, 'mtime' => gmmktime( 10, 0, 0, 10, 21, 2026 ) ), $s->stat( 'k' ) );
		$this->assertSame( 'HEAD', $this->calls[0]['method'] );
		$this->assertNull( $s->stat( 'k' ) );
		$this->assertThrowsRemote( function () use ( $s ) { $s->stat( 'k' ); }, 'HTTP 500' );
		$this->assertThrowsRemote( function () use ( $s ) { $s->stat( 'k' ); }, 'no size' );
	}

	private static function multistatus( string $dir, array $files, array $dirs = array() ): string {
		$x = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
			. '<d:response><d:href>' . $dir . '</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
		foreach ( $dirs as $d ) {
			$x .= '<d:response><d:href>' . $dir . $d . '/</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>'
				. '<d:propstat><d:prop><d:getcontentlength/></d:prop><d:status>HTTP/1.1 404 Not Found</d:status></d:propstat></d:response>';
		}
		foreach ( $files as $name => $size ) {
			$x .= '<d:response><d:href>' . $dir . $name . '</d:href><d:propstat><d:prop><d:resourcetype/><d:getcontentlength>' . $size
				. '</d:getcontentlength><d:getlastmodified>Wed, 21 Oct 2026 10:00:00 GMT</d:getlastmodified></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
		}
		return $x . '</d:multistatus>';
	}

	public function testObjectsListsDirectFilesWithFullKeys(): void {
		$dir = '/nc/remote.php/dav/files/jo%20doe%40x/Backups/WP%20site/base/b%201/';
		$xml = self::multistatus( $dir, array( 'm%20a.json' => 10, 'part-1' => 20, 'other' => 5 ), array( 'sub' ) );
		$s   = $this->storage( array(), array( self::res( 207, $xml ), self::res( 207, $xml ), self::res( 404 ) ) );
		$r   = $s->objects( 'base/b 1/' );
		$this->assertNull( $r['next'] );
		$mt = gmmktime( 10, 0, 0, 10, 21, 2026 );
		$this->assertSame(
			array(
				'base/b 1/m a.json' => array( 'size' => 10, 'mtime' => $mt ),
				'base/b 1/other'    => array( 'size' => 5, 'mtime' => $mt ),
				'base/b 1/part-1'   => array( 'size' => 20, 'mtime' => $mt ),
			),
			$r['items']
		);
		$c = $this->calls[0];
		$this->assertSame( 'PROPFIND', $c['method'] );
		$this->assertSame( self::ROOT . 'base/b%201/', $c['url'] );
		$this->assertSame( '1', $c['args']['headers']['Depth'] );
		$this->assertStringContainsString( 'getcontentlength', $c['args']['body'] );

		$this->assertSame( array( 'base/b 1/part-1' ), array_keys( $s->objects( 'base/b 1/part' )['items'] ) );
		$this->assertSame( array( 'items' => array(), 'next' => null ), $s->objects( 'base/missing/' ) );
	}

	public function testObjectsAcceptsAbsoluteHrefs(): void {
		$dir = '/nc/remote.php/dav/files/jo%20doe%40x/Backups/WP%20site/';
		$xml = str_replace( '<d:href>/', '<d:href>https://cloud.example.com/', self::multistatus( $dir, array( 'f' => 1 ) ) );
		$s   = $this->storage( array(), array( self::res( 207, $xml ) ) );
		$this->assertSame( array( 'f' ), array_keys( $s->objects( '' )['items'] ) );
		$this->assertSame( self::ROOT, $this->calls[0]['url'] );
	}

	public function testObjectsErrorsAndLimits(): void {
		$dir   = '/nc/remote.php/dav/files/jo%20doe%40x/Backups/WP%20site/b/';
		$many  = array();
		for ( $i = 0; $i <= NextcloudStorage::LIST_MAX_ITEMS; $i++ ) {
			$many[ 'f' . $i ] = 1;
		}
		$s = $this->storage(
			array(),
			array(
				self::res( 207, self::multistatus( $dir, $many ) ),
				self::res( 207, str_repeat( ' ', NextcloudStorage::LIST_MAX_BYTES + 1 ) ),
				self::res( 207, '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><d:multistatus xmlns:d="DAV:">&e;</d:multistatus>' ),
				self::res( 207, '<not-xml' ),
				self::res( 207, self::multistatus( '/elsewhere/', array( 'f' => 1 ) ) ),
				self::res( 401 ),
				self::res( 207, str_replace( '<d:getcontentlength>1</d:getcontentlength>', '', self::multistatus( $dir, array( 'f' => 1 ) ) ) ),
			)
		);
		$this->assertThrowsRemote( function () use ( $s ) { $s->objects( 'b/' ); }, 'more than' );
		$this->assertThrowsRemote( function () use ( $s ) { $s->objects( 'b/' ); }, 'too many entries' );
		$this->assertThrowsRemote( function () use ( $s ) { $s->objects( 'b/' ); }, 'DTD' );
		$this->assertThrowsRemote( function () use ( $s ) { $s->objects( 'b/' ); }, 'malformed XML' );
		$this->assertThrowsRemote( function () use ( $s ) { $s->objects( 'b/' ); }, 'outside the folder' );
		$this->assertThrowsRemote( function () use ( $s ) { $s->objects( 'b/' ); }, 'HTTP 401' );
		$this->assertThrowsRemote( function () use ( $s ) { $s->objects( 'b/' ); }, 'lacks size' );
		$this->assertThrowsRemote( function () use ( $s ) { $s->objects( 'b/', 'cursor' ); }, 'not paginated' );
		$this->assertThrowsRemote( function () use ( $s ) { $s->objects( '../b/' ); }, 'Invalid' );
		$this->assertCount( 7, $this->calls );
	}

	public function testRemove(): void {
		$s = $this->storage( array(), array( self::res( 204 ), self::res( 404 ), self::res( 423 ) ) );
		$s->remove( 'b/k' );
		$this->assertSame( 'DELETE', $this->calls[0]['method'] );
		$this->assertSame( self::ROOT . 'b/k', $this->calls[0]['url'] );
		$s->remove( 'b/k' );
		$this->assertThrowsRemote( function () use ( $s ) { $s->remove( 'b/k' ); }, 'locked' );
	}

	public function testPublicDropPut(): void {
		$s = $this->publicStorage( array( self::res( 201 ) ) );
		$this->assertSame( array( 'read' => false, 'list' => false, 'delete' => false, 'overwrite' => false ), $s->capabilities() );
		$s->put( 'bk1--g2--db 1.sql.gz', 'payload' );
		$c = $this->calls[0];
		$this->assertSame( 'PUT', $c['method'] );
		$this->assertSame( 'https://cloud.example.com/nc/public.php/dav/files/AbC123xyz/bk1--g2--db%201.sql.gz', $c['url'] );
		$this->assertSame( 'Basic ' . base64_encode( 'anonymous:sh4re' ), $c['args']['headers']['Authorization'] );
		$this->assertSame( 'XMLHttpRequest', $c['args']['headers']['X-Requested-With'] );
		$this->assertSame( 0, $c['args']['redirection'] );

		$s = $this->publicStorage( array( self::res( 204 ) ), '' );
		$s->put( 'x', 'p' );
		$this->assertArrayNotHasKey( 'Authorization', $this->calls[0]['args']['headers'] );
		$this->assertSame( 'https://cloud.example.com/nc/public.php/dav/files/AbC123xyz/x', $this->calls[0]['url'] );
	}

	public function testPublicDropNeverRetriesAndRejectsUnavailableOperations(): void {
		$s = $this->publicStorage( array( self::res( 409 ), self::res( 401 ), self::res( 404 ) ) );
		$this->assertThrowsRemote( function () use ( $s ) { $s->put( 'x', 'p' ); }, 'HTTP 409' );
		$this->assertCount( 1, $this->calls );
		$this->assertThrowsRemote( function () use ( $s ) { $s->put( 'x', 'p' ); }, 'share password' );
		$this->assertThrowsRemote( function () use ( $s ) { $s->put( 'x', 'p' ); }, 'share not found' );
		$s = $this->publicStorage( array( self::res( 503, '<?xml version="1.0"?><d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns"><s:message>Sabre\DAV\Exception\NotFound: </s:message></d:error>' ) ) );
		$this->assertThrowsRemote( function () use ( $s ) { $s->put( 'x', 'p' ); }, 'share not found, expired, or service unavailable. Server: Sabre\DAV\Exception\NotFound:' );
		$this->assertThrowsRemote( function () use ( $s ) { $s->put( 'dir/x', 'p' ); }, 'flat file names' );
		$this->assertThrowsRemote( function () use ( $s ) { $s->get( 'x' ); }, 'unavailable' );
		$this->assertThrowsRemote( function () use ( $s ) { $s->stat( 'x' ); }, 'unavailable' );
		$this->assertThrowsRemote( function () use ( $s ) { $s->objects( '' ); }, 'unavailable' );
		$this->assertThrowsRemote( function () use ( $s ) { $s->remove( 'x' ); }, 'unavailable' );
		$this->assertCount( 1, $this->calls );
	}

	public function testExceptionsRedactShareTokenAndPasswords(): void {
		$tok     = 'AbC123xyz';
		$reflect = '<?xml version="1.0"?><d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns"><s:message>File /public.php/dav/files/' . $tok . '/x not found for abc123XYZ pw sh4re</s:message></d:error>';
		$wp_err  = new class() {
			public function get_error_message() {
				return 'cURL error 7: Failed to connect to https://cloud.example.com/nc/public.php/dav/files/AbC123xyz/x (pw sh4re, c2g0cmU=)';
			}
		};
		$cases   = array(
			array( 'resp' => self::res( 503, $reflect ) ),
			array( 'resp' => $wp_err ),
			array( 'throw' => new \RuntimeException( 'Requests failed for https://cloud.example.com/nc/public.php/dav/files/' . $tok . '/x' ) ),
			array( 'throw' => new RemoteException( 'sender said ' . $tok . ' sh4re' ) ),
		);
		foreach ( $cases as $case ) {
			$s = new NextcloudStorage(
				array(
					'public_url'      => 'https://cloud.example.com/nc/s/' . $tok,
					'public_password' => 'sh4re',
				),
				function () use ( $case ) {
					if ( isset( $case['throw'] ) ) {
						throw $case['throw'];
					}
					return $case['resp'];
				}
			);
			try {
				$s->put( 'bk--g1--x', 'p' );
				$this->fail( 'expected exception' );
			} catch ( RemoteException $e ) {
				$this->assertStringContainsString( '[redacted]', $e->getMessage() );
				$this->assertNull( $e->getPrevious() );
				foreach ( array( $tok, 'abc123xyz', 'sh4re', base64_encode( 'anonymous:sh4re' ) ) as $secret ) {
					$this->assertStringNotContainsStringIgnoringCase( $secret, $e->getMessage() );
				}
			}
		}

		$s = $this->storage( array( 'password' => 'app/pa ss' ), array( self::res( 500, '<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns"><s:message>bad app%2Fpa%20ss app/pa ss</s:message></d:error>' ) ) );
		try {
			$s->get( 'k' );
			$this->fail( 'expected exception' );
		} catch ( RemoteException $e ) {
			$this->assertStringNotContainsString( 'pa ss', $e->getMessage() );
			$this->assertStringNotContainsString( 'pa%20ss', $e->getMessage() );
			$this->assertStringContainsString( 'HTTP 500', $e->getMessage() );
		}
	}

	public function testIdentityEncodingIsForcedOnEveryRequest(): void {
		$xml = self::multistatus( '/nc/remote.php/dav/files/jo%20doe%40x/Backups/WP%20site/', array( 'f' => 1 ) );
		$s   = $this->storage( array(), array( self::res( 409 ), self::res( 201 ), self::res( 201 ), self::res( 201 ), self::res( 201 ), self::res( 200, 'x', array( 'Content-Length' => '1' ) ), self::res( 200, '', array( 'Content-Length' => '1', 'Last-Modified' => 'Wed, 21 Oct 2026 10:00:00 GMT' ) ), self::res( 207, $xml ), self::res( 204 ) ) );
		$s->put( 'd/k', 'x' );
		$s->get( 'd/k' );
		$s->stat( 'd/k' );
		$s->objects( '' );
		$s->remove( 'd/k' );
		$this->assertSame( array( 'PUT', 'MKCOL', 'MKCOL', 'MKCOL', 'PUT', 'GET', 'HEAD', 'PROPFIND', 'DELETE' ), array_column( $this->calls, 'method' ) );
		foreach ( $this->calls as $c ) {
			$this->assertSame( 'identity', $c['args']['headers']['Accept-Encoding'], $c['method'] );
			$this->assertFalse( $c['args']['decompress'], $c['method'] );
			$this->assertSame( 0, $c['args']['redirection'], $c['method'] );
		}
		$p = $this->publicStorage( array( self::res( 201 ) ) );
		$p->put( 'x', 'y' );
		$this->assertSame( 'identity', $this->calls[0]['args']['headers']['Accept-Encoding'] );
		$this->assertFalse( $this->calls[0]['args']['decompress'] );
	}

	public function testNonIdentityContentEncodingIsRejected(): void {
		// Shape observed from the real WordPress HTTP API with default args: body decoded, compressed Content-Length kept.
		$json = str_repeat( '{"a":1}', 26 ) . 'xyz';
		$s    = $this->storage(
			array(),
			array(
				self::res( 200, $json, array( 'Content-Encoding' => 'gzip', 'Content-Length' => '164' ) ),
				self::res( 200, gzencode( $json ), array( 'content-encoding' => 'GZIP' ) ),
				self::res( 200, '', array( 'Content-Encoding' => 'br', 'Content-Length' => '9', 'Last-Modified' => 'Wed, 21 Oct 2026 10:00:00 GMT' ) ),
				self::res( 207, gzencode( '<x/>' ), array( 'Content-Encoding' => 'gzip' ) ),
				self::res( 200, $json, array( 'Content-Encoding' => 'identity', 'Content-Length' => (string) strlen( $json ) ) ),
				self::res( 401, gzencode( '<s:message>zzz</s:message>' ), array( 'Content-Encoding' => 'gzip' ) ),
			)
		);
		$this->assertThrowsRemote( function () use ( $s ) { $s->get( 'i.json' ); }, 'unexpected Content-Encoding "gzip"' );
		$this->assertThrowsRemote( function () use ( $s ) { $s->get( 'i.json' ); }, 'refusing to decode' );
		$this->assertThrowsRemote( function () use ( $s ) { $s->stat( 'i.json' ); }, 'Content-Encoding "br"' );
		$this->assertThrowsRemote( function () use ( $s ) { $s->objects( '' ); }, 'Content-Encoding "gzip"' );
		$this->assertSame( $json, $s->get( 'i.json' ) );
		try {
			$s->get( 'i.json' );
			$this->fail( 'expected exception' );
		} catch ( RemoteException $e ) {
			$this->assertStringContainsString( 'HTTP 401', $e->getMessage() );
			$this->assertStringNotContainsString( 'Server:', $e->getMessage() );
		}
	}
	public function testConfigValidation(): void {
		if ( ! defined( 'WPSSB_ALLOW_INSECURE_REMOTE' ) ) {
			$this->assertThrowsRemote( function () { $this->storage( array( 'url' => 'http://cloud.example.com' ) ); }, 'https' );
			$this->assertThrowsRemote( function () { $this->storage( array( 'public_url' => 'http://cloud.example.com/s/abc' ) ); }, 'https' );
		}
		$this->assertThrowsRemote( function () { $this->storage( array( 'url' => 'https://u:p@cloud.example.com' ) ); }, 'credentials' );
		$this->assertThrowsRemote( function () { $this->storage( array( 'url' => 'https://cloud.example.com/?a=1' ) ); }, 'query' );
		$this->assertThrowsRemote( function () { $this->storage( array( 'password' => '' ) ); }, 'app password' );
		$this->assertThrowsRemote( function () { $this->storage( array( 'path' => 'a/../b' ) ); }, 'remote folder' );
		$this->assertThrowsRemote( function () { $this->storage( array( 'public_url' => 'https://cloud.example.com/apps/files' ) ); }, '/s/TOKEN' );

		$s = $this->storage( array( 'url' => 'https://Cloud.Example.com:8443', 'path' => '' ), array( self::res( 201 ) ) );
		$s->put( 'k', 'x' );
		$this->assertSame( 'https://cloud.example.com:8443/remote.php/dav/files/jo%20doe%40x/k', $this->calls[0]['url'] );
	}
}