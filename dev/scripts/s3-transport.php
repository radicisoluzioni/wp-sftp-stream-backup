<?php
/**
 * Real local S3 transport checks. Environment: S3_ENDPOINT, S3_REGION, S3_BUCKET,
 * S3_ACCESS_KEY, S3_SECRET_KEY. Run with s3-integration.ps1 or an existing WP runtime.
 * Standalone mode injects a bounded cURL sender with the WordPress HTTP API shape;
 * when WordPress is loaded its actual wp_remote_request implementation is used.
 */

namespace WPSSB\Dev;

use WPSSB\Remote\RemoteException;
use WPSSB\Remote\S3Storage;

if ( ! defined( 'WPSSB_ALLOW_INSECURE_REMOTE' ) ) {
	define( 'WPSSB_ALLOW_INSECURE_REMOTE', true );
}
if ( ! interface_exists( 'WPSSB\\Remote\\ObjectStorage' ) ) {
	require dirname( __DIR__, 2 ) . '/wp-sftp-stream-backup/src/Remote/ObjectStorage.php';
}
if ( ! class_exists( 'WPSSB\\Remote\\RemoteException' ) ) {
	require dirname( __DIR__, 2 ) . '/wp-sftp-stream-backup/src/Remote/RemoteException.php';
}
if ( ! class_exists( 'WPSSB\\Remote\\S3Storage' ) ) {
	require dirname( __DIR__, 2 ) . '/wp-sftp-stream-backup/src/Remote/S3Storage.php';
}

/** No response streaming, file handles, automatic decompression or redirects. */
function http_sender( string $url, array $args ): array {
	$curl = curl_init( $url );
	$body = '';
	$headers = array();
	$limited = false;
	$request_headers = array();
	foreach ( $args['headers'] as $name => $value ) {
		$request_headers[] = $name . ': ' . $value;
	}
	curl_setopt_array( $curl, array(
		CURLOPT_CUSTOMREQUEST => $args['method'],
		CURLOPT_NOBODY => 'HEAD' === $args['method'],
		CURLOPT_HTTPHEADER => $request_headers,
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_TIMEOUT => $args['timeout'],
		CURLOPT_PROXY => '',
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
		CURLOPT_HEADERFUNCTION => function ( $handle, $line ) use ( &$headers ) {
			if ( 0 === stripos( $line, 'HTTP/' ) ) {
				$headers = array();
			} elseif ( false !== strpos( $line, ':' ) ) {
				list( $name, $value ) = explode( ':', $line, 2 );
				$headers[ strtolower( trim( $name ) ) ] = trim( $value );
			}
			return strlen( $line );
		},
		CURLOPT_WRITEFUNCTION => function ( $handle, $chunk ) use ( &$body, &$limited, $args ) {
			$remaining = $args['limit_response_size'] - strlen( $body );
			$body .= substr( $chunk, 0, $remaining );
			if ( strlen( $chunk ) > $remaining ) {
				$limited = true;
				return 0;
			}
			return strlen( $chunk );
		},
	) );
	if ( 'PUT' === $args['method'] ) {
		curl_setopt( $curl, CURLOPT_POSTFIELDS, $args['body'] );
	}
	$ok = curl_exec( $curl );
	$code = (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE );
	$error = curl_errno( $curl );
	curl_close( $curl );
	if ( false === $ok && ! $limited ) {
		throw new \RuntimeException( 'Integration cURL transport failed, code ' . $error . '.' );
	}
	return array( 'response' => array( 'code' => $code ), 'headers' => $headers, 'body' => $body );
}

function check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new \RuntimeException( $message );
	}
	echo '[ok] ' . $message . "\n";
}

function failure( callable $operation, string $message, string $expected ): void {
	try {
		$operation();
	} catch ( RemoteException $e ) {
		check( false !== strpos( $e->getMessage(), $expected ), $message . ' (' . $expected . ')' );
		return;
	}
	throw new \RuntimeException( 'Expected failure: ' . $message );
}

$cfg = array();
foreach ( array( 'endpoint', 'region', 'bucket', 'access_key', 'secret_key' ) as $name ) {
	$value = getenv( 'S3_' . strtoupper( $name ) );
	if ( false === $value || '' === $value ) {
		throw new \RuntimeException( 'Missing local integration environment: S3_' . strtoupper( $name ) );
	}
	$cfg[ $name ] = $value;
}
$cfg['path_style'] = true;
$sender = function_exists( 'wp_remote_request' ) ? null : __NAMESPACE__ . '\\http_sender';
echo 'HTTP sender: ' . ( null === $sender ? 'WordPress HTTP API' : 'injected bounded cURL (no WordPress runtime)' ) . "\n";
$storage = new S3Storage( $cfg, $sender );
$prefix = 'integration/run-' . bin2hex( random_bytes( 6 ) ) . '/';
$keys = array();
try {
	foreach ( array( 'plain', 'Unicode-é-日本語', 'space + percent% question?# amp& equals=', 'nested/slash/preserved', 'literal%2F' ) as $name ) {
		$key = $prefix . $name;
		$payload = "\0\xff" . $name;
		$storage->put( $key, $payload );
		$keys[] = $key;
		check( $payload === $storage->get( $key ), 'PUT/GET binary, Unicode and reserved key: ' . $name );
		$stat = $storage->stat( $key );
		check( null !== $stat && strlen( $payload ) === $stat['size'] && $stat['mtime'] > 0, 'HEAD metadata: ' . $name );
	}
	$storage->put( $keys[0], 'overwritten' );
	check( 'overwritten' === $storage->get( $keys[0] ), 'PUT overwrites existing objects' );
	$page = $storage->objects( $prefix );
	check( count( $keys ) === count( $page['items'] ) && null === $page['next'] && ! array_diff( $keys, array_keys( $page['items'] ) ), 'ListObjectsV2 decodes complete Unicode/reserved keys' );

	$large_key = $prefix . '8MiB';
	$large = str_repeat( 'b', 8388608 );
	$storage->put( $large_key, $large );
	$keys[] = $large_key;
	check( hash( 'sha256', $large ) === hash( 'sha256', $storage->get( $large_key ) ), '8 MiB PUT/GET boundary' );
	failure( function () use ( $storage, $large_key, $large ) {
		$storage->put( $large_key, $large . 'x' );
	}, 'Oversize PUT rejected before network', '8 MiB' );
	unset( $large );
	failure( function () use ( $storage ) {
		$storage->get( 'integration/oversized' );
	}, 'Oversize remote GET bounded and rejected', '8 MiB' );

	$missing = $prefix . 'missing';
	check( null === $storage->get( $missing ) && null === $storage->stat( $missing ), '404 alone represents a missing object' );
	$storage->remove( $missing );
	$bad = new S3Storage( array_merge( $cfg, array( 'secret_key' => 'deliberately-wrong-local-test-secret' ) ), $sender );
	failure( function () use ( $bad, $keys ) {
		$bad->get( $keys[0] );
	}, 'Invalid credentials are an explicit authorization error', 'HTTP 403' );
	$absent_bucket = new S3Storage( array_merge( $cfg, array( 'bucket' => 'missing-' . bin2hex( random_bytes( 6 ) ) ) ), $sender );
	failure( function () use ( $absent_bucket ) {
		$absent_bucket->objects( '' );
	}, 'Missing bucket listing is an error, not an empty catalog', 'HTTP 404' );

	for ( $i = 0; $i < 1001; ++$i ) {
		$key = $prefix . 'pages/' . sprintf( '%04d', $i );
		$storage->put( $key, '' );
		$keys[] = $key;
	}
	$page1 = $storage->objects( $prefix . 'pages/' );
	check( 1000 === count( $page1['items'] ) && null !== $page1['next'], 'Real ListObjectsV2 first page returns 1000 keys and a continuation token' );
	$page2 = $storage->objects( $prefix . 'pages/', $page1['next'] );
	check( 1 === count( $page2['items'] ) && null === $page2['next'] && ! array_intersect_key( $page1['items'], $page2['items'] ), 'Real ListObjectsV2 continuation has no gaps or duplicate keys' );
	check( memory_get_peak_usage( true ) < 96 * 1024 * 1024, 'Bounded client peak memory: ' . memory_get_peak_usage( true ) . ' bytes (96 MiB process limit)' );
} finally {
	foreach ( $keys as $key ) {
		$storage->remove( $key );
	}
	$storage->remove( 'integration/oversized' );
	$storage->disconnect();
}
check( array() === $storage->objects( $prefix )['items'], 'DELETE removes all test objects' );
echo "Local S3-compatible transport integration passed; this is not AWS certification.\n";
