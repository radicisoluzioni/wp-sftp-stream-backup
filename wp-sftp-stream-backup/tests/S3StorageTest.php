<?php
/**
 * @package WPSSB
 */

namespace WPSSB\Tests;

use PHPUnit\Framework\TestCase;
use WPSSB\Remote\RemoteException;
use WPSSB\Remote\S3Storage;

class S3StorageTest extends TestCase {

	private function config( array $extra = array() ): array {
		return array_merge( array(
			'endpoint' => 'https://s3.example.test',
			'region' => 'us-east-1',
			'bucket' => 'examplebucket',
			'access_key' => 'AKIAIOSFODNN7EXAMPLE',
			'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
			'path_style' => true,
		), $extra );
	}

	private function response( int $code = 200, string $body = '', array $headers = array() ): array {
		return array( 'response' => array( 'code' => $code ), 'body' => $body, 'headers' => $headers );
	}

	private function signed( S3Storage $storage, string $method, string $path, string $query, string $body, array $extra = array() ): array {
		$sign = new \ReflectionMethod( $storage, 'signedHeaders' );
		$sign->setAccessible( true );
		return $sign->invoke( $storage, $method, $path, $query, $body, '20130524T000000Z', $extra );
	}

	public function testAwsPublishedGetObjectSigningVector(): void {
		// AWS S3 API Reference: sig-v4-header-based-auth.html, GET Object example.
		$storage = new S3Storage( $this->config( array( 'endpoint' => 'https://s3.amazonaws.com', 'path_style' => false ) ) );
		$headers = $this->signed( $storage, 'GET', '/test.txt', '', '', array( 'range' => 'bytes=0-9' ) );
		$canonical = "GET\n/test.txt\n\nhost:examplebucket.s3.amazonaws.com\nrange:bytes=0-9\nx-amz-content-sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855\nx-amz-date:20130524T000000Z\n\nhost;range;x-amz-content-sha256;x-amz-date\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";
		$this->assertSame( '7344ae5b7ee6c3e7e6b0fe0640412a37625d1fbfff95c48bbb2dc43964946972', hash( 'sha256', $canonical ) );
		$this->assertSame(
			'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
			$headers['authorization']
		);
	}

	public function testPutIsExplicitInMemorySignedBinaryBody(): void {
		$data = "\0\xffbinary\r\n";
		$storage = new S3Storage( $this->config( array( 'session_token' => 'session+/=token' ) ), function ( $url, $args ) use ( $data ) {
			$this->assertSame( 'https://s3.example.test/examplebucket/site/part%20%2B%25%3F%23', $url );
			$this->assertSame( 'PUT', $args['method'] );
			$this->assertSame( $data, $args['body'] );
			$this->assertSame( hash( 'sha256', $data ), $args['headers']['x-amz-content-sha256'] );
			$this->assertSame( 'session+/=token', $args['headers']['x-amz-security-token'] );
			$this->assertStringContainsString( 'host;x-amz-content-sha256;x-amz-date;x-amz-security-token', $args['headers']['authorization'] );
			$this->assertStringNotContainsString( $this->config()['secret_key'], $args['headers']['authorization'] );
			$this->assertSame( 0, $args['redirection'] );
			$this->assertTrue( $args['sslverify'] );
			$this->assertFalse( $args['stream'] );
			$this->assertFalse( $args['decompress'] );
			$this->assertSame( 8388609, $args['limit_response_size'] );
			$this->assertSame( 'body', $args['data_format'] );
			$this->assertSame( 'identity', $args['headers']['accept-encoding'] );
			$this->assertArrayNotHasKey( 'filename', $args );
			return $this->response();
		} );
		$storage->put( 'site/part +%?#', $data );
		$this->assertSame( array( 'read' => true, 'list' => true, 'delete' => true, 'overwrite' => true ), $storage->capabilities() );
		$storage->disconnect();
	}

	/** @dataProvider addressingCases */
	public function testAddressingAndEndpointBasePaths( array $config, string $key, string $expected ): void {
		$storage = new S3Storage( $this->config( $config ), function ( $url, $args ) use ( $expected ) {
			$this->assertSame( $expected, $url );
			$this->assertSame( parse_url( $url, PHP_URL_HOST ) . ( parse_url( $url, PHP_URL_PORT ) ? ':' . parse_url( $url, PHP_URL_PORT ) : '' ), $args['headers']['host'] );
			return $this->response( 200, 'data', array( 'Content-Length' => '4' ) );
		} );
		$this->assertSame( 'data', $storage->get( $key ) );
	}

	public function addressingCases(): array {
		return array(
			array( array(), '/a//b/', 'https://s3.example.test/examplebucket//a//b/' ),
			array( array( 'endpoint' => 'https://S3.example.test:9443/base%20path/api/' ), 'é/%2F', 'https://s3.example.test:9443/base%20path/api/examplebucket/%C3%A9/%252F' ),
			array( array( 'endpoint' => 'https://s3.example.test/proxy', 'path_style' => false ), 'full/prefix/key', 'https://examplebucket.s3.example.test/proxy/full/prefix/key' ),
			array( array( 'endpoint' => 'https://s3.example.test/base%2Fpath' ), 'x', 'https://s3.example.test/base%2Fpath/examplebucket/x' ),
			array( array( 'endpoint' => 'https://[::1]:9443/api' ), 'x', 'https://[::1]:9443/api/examplebucket/x' ),
		);
	}

	public function testBoundarySizes(): void {
		$body = str_repeat( 'x', 8388608 );
		$calls = 0;
		$storage = new S3Storage( $this->config(), function () use ( &$calls, $body ) {
			++$calls;
			return $calls === 1 ? $this->response() : $this->response( 200, $body, array( 'content-length' => '8388608' ) );
		} );
		$storage->put( 'key', $body );
		$this->assertSame( $body, $storage->get( 'key' ) );
		$this->assertSame( 2, $calls );
		$this->expectException( RemoteException::class );
		$storage->put( 'key', $body . 'x' );
	}

	/** @dataProvider invalidResponseCases */
	public function testRejectsInvalidOrOversizedResponses( string $body, array $headers ): void {
		$storage = new S3Storage( $this->config(), function () use ( $body, $headers ) {
			return $this->response( 200, $body, $headers );
		} );
		$this->expectException( RemoteException::class );
		$storage->get( 'key' );
	}

	public function invalidResponseCases(): array {
		return array(
			array( str_repeat( 'x', 8388609 ), array() ),
			array( 'short', array( 'content-length' => '8388609' ) ),
			array( 'short', array( 'content-length' => '99' ) ),
			array( 'long', array( 'content-length' => '1' ) ),
			array( '', array( 'content-length' => '-1' ) ),
			array( '', array( 'content-length' => '999999999999999999999999999' ) ),
			array( 'compressed', array( 'content-encoding' => 'gzip' ) ),
		);
	}

	public function testGetRequestsRawIdentityBytesWithoutDecompression(): void {
		$raw = "\x1f\x8b\x08\x00raw-gzip-looking-bytes";
		$storage = new S3Storage( $this->config(), function ( $url, $args ) use ( $raw ) {
			$this->assertSame( 'GET', $args['method'] );
			$this->assertSame( 'identity', $args['headers']['accept-encoding'] );
			$this->assertFalse( $args['decompress'] );
			$this->assertFalse( $args['stream'] );
			$this->assertSame( 0, $args['redirection'] );
			$this->assertSame( 8388609, $args['limit_response_size'] );
			return $this->response( 200, $raw, array( 'Content-Length' => (string) strlen( $raw ), 'Content-Encoding' => 'identity' ) );
		} );
		$this->assertSame( $raw, $storage->get( 'key' ) );
	}

	/** @dataProvider nonIdentityEncodings */
	public function testGetRejectsNonIdentityContentEncoding( string $encoding ): void {
		$storage = new S3Storage( $this->config(), function () use ( $encoding ) {
			return $this->response( 200, 'decoded', array( 'Content-Encoding' => $encoding, 'Content-Length' => '7' ) );
		} );
		$this->expectException( RemoteException::class );
		$this->expectExceptionMessage( 'Unexpected S3 response encoding.' );
		$storage->get( 'key' );
	}

	public function nonIdentityEncodings(): array {
		return array( array( 'gzip' ), array( 'deflate' ), array( 'br' ), array( 'gzip, identity' ) );
	}

	public function testListingRejectsNonIdentityContentEncoding(): void {
		$storage = new S3Storage( $this->config(), function ( $url, $args ) {
			$this->assertSame( 'identity', $args['headers']['accept-encoding'] );
			$this->assertFalse( $args['decompress'] );
			return $this->response( 200, $this->listing(), array( 'Content-Encoding' => 'gzip' ) );
		} );
		$this->expectException( RemoteException::class );
		$storage->objects( '' );
	}

	public function testHeadAcceptsStoredContentEncodingMetadata(): void {
		$storage = new S3Storage( $this->config(), function ( $url, $args ) {
			$this->assertSame( 'identity', $args['headers']['accept-encoding'] );
			$this->assertFalse( $args['decompress'] );
			return $this->response( 200, '', array( 'Content-Length' => '10', 'Content-Encoding' => 'gzip', 'Last-Modified' => 'Fri, 24 May 2013 00:00:00 GMT' ) );
		} );
		$this->assertSame( array( 'size' => 10, 'mtime' => 1369353600 ), $storage->stat( 'key' ) );
	}

	public function testHeadDoesNotDownloadObjectAndAllowsLargeObjectSize(): void {
		$storage = new S3Storage( $this->config(), function ( $url, $args ) {
			$this->assertSame( 'HEAD', $args['method'] );
			$this->assertSame( '', $args['body'] );
			$response = $this->response();
			$response['headers'] = new \ArrayIterator( array( 'Content-Length' => '123456789', 'Last-Modified' => 'Fri, 24 May 2013 00:00:00 GMT' ) );
			return $response;
		} );
		$this->assertSame( array( 'size' => 123456789, 'mtime' => 1369353600 ), $storage->stat( 'full/key' ) );
	}

	public function testMissingObjectsAreNullAndDeleteIsIdempotent(): void {
		$methods = array();
		$storage = new S3Storage( $this->config(), function ( $url, $args ) use ( &$methods ) {
			$methods[] = $args['method'];
			return $this->response( 404 );
		} );
		$this->assertNull( $storage->get( 'key' ) );
		$this->assertNull( $storage->stat( 'key' ) );
		$storage->remove( 'key' );
		$this->assertSame( array( 'GET', 'HEAD', 'DELETE' ), $methods );
	}

	public function testDeleteRequest(): void {
		$storage = new S3Storage( $this->config(), function ( $url, $args ) {
			$this->assertSame( 'DELETE', $args['method'] );
			$this->assertSame( '', $args['body'] );
			$this->assertSame( hash( 'sha256', '' ), $args['headers']['x-amz-content-sha256'] );
			return $this->response( 204 );
		} );
		$storage->remove( 'key' );
	}

	/** @dataProvider httpFailureCases */
	public function testHttpFailuresAreNotMissing( int $status, string $method ): void {
		$calls = 0;
		$storage = new S3Storage( $this->config(), function () use ( $status, &$calls ) {
			++$calls;
			return $this->response( $status, 'server secrets must not be exposed', array( 'Location' => 'https://evil.test/' ) );
		} );
		try {
			if ( 'put' === $method ) {
				$storage->put( 'key', 'data' );
			} else {
				$storage->$method( 'key' );
			}
			$this->fail( 'Expected an HTTP error.' );
		} catch ( RemoteException $e ) {
			$this->assertStringContainsString( (string) $status, $e->getMessage() );
			$this->assertStringNotContainsString( 'server secrets', $e->getMessage() );
			if ( in_array( $status, array( 401, 403 ), true ) ) {
				$this->assertStringContainsString( 'authorization', $e->getMessage() );
			}
		}
		$this->assertSame( 1, $calls );
	}

	public function httpFailureCases(): array {
		$cases = array();
		foreach ( array( 0, 206, 301, 302, 307, 308, 400, 401, 403, 429, 500, 503 ) as $status ) {
			foreach ( array( 'put', 'get', 'stat', 'objects', 'remove' ) as $method ) {
				$cases[] = array( $status, $method );
			}
		}
		$cases[] = array( 404, 'put' );
		$cases[] = array( 404, 'objects' );
		return $cases;
	}

	public function testThrownTransportErrorsAreSanitized(): void {
		$storage = new S3Storage( $this->config(), function () {
			throw new \RuntimeException( 'secret connection details' );
		} );
		$this->expectException( RemoteException::class );
		$this->expectExceptionMessage( 'S3 HTTP transport failed.' );
		$storage->get( 'key' );
	}

	public function testMalformedTransportResponse(): void {
		$storage = new S3Storage( $this->config(), function () {
			return new \stdClass();
		} );
		$this->expectException( RemoteException::class );
		$storage->get( 'key' );
	}

	public function testWpErrorMessagesAreNotReflected(): void {
		if ( ! class_exists( '\\WP_Error' ) ) {
			eval( 'class WP_Error { private $message; public function __construct( $code = "", $message = "" ) { $this->message = $message; } public function get_error_message() { return $this->message; } }' );
		}
		$secret = $this->config()['secret_key'];
		$storage = new S3Storage( $this->config( array( 'session_token' => 'session-token-secret' ) ), function ( $url, $args ) use ( $secret ) {
			return new \WP_Error( 'http_request_failed', $secret . ' session-token-secret ' . $args['headers']['authorization'] );
		} );
		try {
			$storage->get( 'key' );
			$this->fail( 'Expected a transport error.' );
		} catch ( RemoteException $e ) {
			$this->assertSame( 'S3 HTTP transport failed.', $e->getMessage() );
			$this->assertNull( $e->getPrevious() );
		}
	}

	private function listing( string $contents = '', string $more = 'false', string $token = '' ): string {
		return '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><EncodingType>url</EncodingType><IsTruncated>' . $more . '</IsTruncated>' . $contents . $token . '</ListBucketResult>';
	}

	public function testListV2PaginationEncodingAndCompletePrefix(): void {
		$calls = 0;
		$storage = new S3Storage( $this->config( array( 'endpoint' => 'https://s3.example.test/base' ) ), function ( $url, $args ) use ( &$calls ) {
			++$calls;
			$this->assertSame( 'GET', $args['method'] );
			$query = 'encoding-type=url&list-type=2&max-keys=1000&prefix=site%2F%2B%20';
			if ( 1 === $calls ) {
				$this->assertSame( 'https://s3.example.test/base/examplebucket/?' . $query, $url );
				return $this->response( 200, $this->listing( '<Contents><Key>site%2F%2B%20a%26b</Key><Size>42</Size><LastModified>2013-05-24T00:00:00.000Z</LastModified></Contents>', 'true', '<NextContinuationToken>a+/=&amp;token</NextContinuationToken>' ) );
			}
			$this->assertSame( 'https://s3.example.test/base/examplebucket/?continuation-token=a%2B%2F%3D%26token&' . $query, $url );
			return $this->response( 200, $this->listing() );
		} );
		$page = $storage->objects( 'site/+ ' );
		$this->assertSame( array( 'items' => array( 'site/+ a&b' => array( 'size' => 42, 'mtime' => 1369353600 ) ), 'next' => 'a+/=&token' ), $page );
		$this->assertSame( array( 'items' => array(), 'next' => null ), $storage->objects( 'site/+ ', $page['next'] ) );
		$this->assertSame( 2, $calls );
	}

	/** @dataProvider badListingCases */
	public function testRejectsUnsafeAndMalformedListing( string $xml ): void {
		$storage = new S3Storage( $this->config(), function () use ( $xml ) {
			return $this->response( 200, $xml );
		} );
		$this->expectException( RemoteException::class );
		$storage->objects( 'prefix/' );
	}

	public function badListingCases(): array {
		$entry = '<Contents><Key>prefix%2Fkey</Key><Size>1</Size><LastModified>2013-05-24T00:00:00Z</LastModified></Contents>';
		return array(
			array( '' ),
			array( '<ListBucketResult>' ),
			array( '<Error><Code>AccessDenied</Code></Error>' ),
			array( '<ListBucketResult><IsTruncated>false</IsTruncated></ListBucketResult>' ),
			array( $this->listing( '', 'true' ) ),
			array( $this->listing( '', 'maybe' ) ),
			array( $this->listing( str_replace( '<Size>1</Size>', '<Size>-1</Size>', $entry ) ) ),
			array( $this->listing( str_replace( '2013-05-24T00:00:00Z', 'invalid', $entry ) ) ),
			array( $this->listing( str_replace( 'prefix%2Fkey', 'outside', $entry ) ) ),
			array( $this->listing( $entry . $entry ) ),
			array( $this->listing( str_repeat( $entry, 1001 ) ) ),
			array( '<!DOCTYPE ListBucketResult [<!ENTITY external SYSTEM "file:///must-not-read">]>' . $this->listing( '&external;' ) ),
			array( '<!DOCTYPE ListBucketResult SYSTEM "https://must-not-contact.test/evil.dtd">' . $this->listing() ),
			array( "\0" . $this->listing() ),
		);
	}

	public function testRepeatedContinuationTokenRejected(): void {
		$storage = new S3Storage( $this->config(), function () {
			return $this->response( 200, $this->listing( '', 'true', '<NextContinuationToken>repeat</NextContinuationToken>' ) );
		} );
		$this->expectException( RemoteException::class );
		$storage->objects( '', 'repeat' );
	}

	public function testMinioListingPlusEncodingKeepsLiteralPlusDistinctFromSpace(): void {
		$storage = new S3Storage( $this->config(), function () {
			return $this->response( 200, $this->listing( '<Contents><Key>prefix%2Fspace+and%2Bplus</Key><Size>0</Size><LastModified>2013-05-24T00:00:00Z</LastModified></Contents>' ) );
		} );
		$this->assertSame( array( 'prefix/space and+plus' => array( 'size' => 0, 'mtime' => 1369353600 ) ), $storage->objects( 'prefix/' )['items'] );
	}

	/** @dataProvider invalidConfigCases */
	public function testInvalidConfigurationRejectedBeforeSendingCredentials( array $cfg ): void {
		$this->expectException( RemoteException::class );
		new S3Storage( $this->config( $cfg ), function () {
			$this->fail( 'Invalid configuration must not send a request.' );
		} );
	}

	public function invalidConfigCases(): array {
		return array(
			array( array( 'endpoint' => 'http://localhost:9000' ) ),
			array( array( 'endpoint' => 'ftp://example.test' ) ),
			array( array( 'endpoint' => 'https://user:password@example.test' ) ),
			array( array( 'endpoint' => 'https://example.test/?query=value' ) ),
			array( array( 'endpoint' => 'https://example.test/#fragment' ) ),
			array( array( 'endpoint' => 'https://example.test/../path' ) ),
			array( array( 'endpoint' => 'https://example.test/%2e%2e/path' ) ),
			array( array( 'endpoint' => "https://example.test\r\nx: injected" ) ),
			array( array( 'access_key' => "key\nx" ) ),
			array( array( 'secret_key' => '' ) ),
			array( array( 'session_token' => "token\nx" ) ),
			array( array( 'bucket' => 'bucket/path' ) ),
			array( array( 'region' => 'region/bad' ) ),
			array( array( 'path_style' => 'false' ) ),
		);
	}

	/** @dataProvider invalidKeyCases */
	public function testInvalidKeysRejected( string $key ): void {
		$storage = new S3Storage( $this->config(), function () {
			$this->fail( 'Invalid key must not reach the HTTP sender.' );
		} );
		$this->expectException( RemoteException::class );
		$storage->get( $key );
	}

	public function invalidKeyCases(): array {
		return array( array( '' ), array( str_repeat( 'x', 1025 ) ), array( '../key' ), array( 'a/./b' ), array( 'a/..' ) );
	}
}
