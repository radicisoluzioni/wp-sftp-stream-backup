<?php
/**
 * Bounded, memory-only S3 requests using the WordPress HTTP API and SigV4.
 *
 * @package WPSSB
 */

namespace WPSSB\Remote;

class S3Storage implements ObjectStorage {

	private const MAX_BYTES = 8388608;

	/** @var array */
	private $cfg;
	/** @var callable */
	private $sender;
	/** @var string */
	private $origin;
	/** @var string */
	private $host;
	/** @var string */
	private $path;

	/** @param callable|null $sender A wp_remote_request-compatible HTTP sender. */
	public function __construct( array $cfg, ?callable $sender = null ) {
		$this->cfg = array_merge( array( 'session_token' => '', 'path_style' => true ), $cfg );
		foreach ( array( 'endpoint', 'region', 'bucket', 'access_key', 'secret_key' ) as $field ) {
			if ( ! isset( $cfg[ $field ] ) || ! is_string( $cfg[ $field ] ) || '' === $cfg[ $field ] || preg_match( '/[\x00-\x20\x7f]/', $cfg[ $field ] ) ) {
				throw new RemoteException( 'Invalid S3 configuration: ' . $field . '.' );
			}
		}
		if ( ! is_bool( $this->cfg['path_style'] ) || ! is_string( $this->cfg['session_token'] ) || preg_match( '/[\x00-\x20\x7f]/', $this->cfg['session_token'] ) ||
			! preg_match( '/^[a-z0-9-]+$/D', $cfg['region'] ) ||
			! preg_match( '/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $cfg['bucket'] ) ) {
			throw new RemoteException( 'Invalid S3 configuration.' );
		}
		$url = parse_url( $cfg['endpoint'] );
		if ( false === $url || empty( $url['host'] ) || isset( $url['user'] ) || isset( $url['pass'] ) || isset( $url['query'] ) || isset( $url['fragment'] ) ||
			! isset( $url['scheme'] ) || ! in_array( strtolower( $url['scheme'] ), array( 'https', 'http' ), true ) ) {
			throw new RemoteException( 'Invalid S3 endpoint.' );
		}
		$scheme = strtolower( $url['scheme'] );
		if ( 'https' !== $scheme && ! ( defined( 'WPSSB_ALLOW_INSECURE_REMOTE' ) && true === WPSSB_ALLOW_INSECURE_REMOTE ) ) {
			throw new RemoteException( 'S3 requires HTTPS.' );
		}
		$host = strtolower( $url['host'] );
		if ( ! preg_match( '/^(?:[a-z0-9.-]+|\[[a-f0-9:]+\])$/D', $host ) || ( ! $this->cfg['path_style'] && ( false !== strpos( $host, ':' ) || false !== strpos( $cfg['bucket'], '..' ) ) ) ) {
			throw new RemoteException( 'Invalid S3 endpoint host or bucket.' );
		}
		if ( ! $this->cfg['path_style'] ) {
			$host = $cfg['bucket'] . '.' . $host;
		}
		$this->host   = $host . ( isset( $url['port'] ) ? ':' . $url['port'] : '' );
		$this->origin = $scheme . '://' . $this->host;
		$base = isset( $url['path'] ) ? $url['path'] : '';
		$this->path = rtrim( self::encodePath( $base, true ), '/' ) . ( $this->cfg['path_style'] ? '/' . rawurlencode( $cfg['bucket'] ) : '' );
		$this->sender = $sender ?? 'wp_remote_request';
	}

	public function capabilities(): array {
		return array( 'read' => true, 'list' => true, 'delete' => true, 'overwrite' => true );
	}

	public function put( string $key, string $data ): void {
		if ( strlen( $data ) > self::MAX_BYTES ) {
			throw new RemoteException( 'S3 object exceeds the 8 MiB limit.' );
		}
		$this->request( 'PUT', $this->objectPath( $key ), array(), $data );
	}

	public function get( string $key ): ?string {
		$response = $this->request( 'GET', $this->objectPath( $key ) );
		return null === $response ? null : $response['body'];
	}

	public function stat( string $key ): ?array {
		$response = $this->request( 'HEAD', $this->objectPath( $key ) );
		if ( null === $response ) {
			return null;
		}
		$headers = $response['headers'];
		return array(
			'size'  => self::size( $headers['content-length'] ?? '' ),
			'mtime' => self::mtime( $headers['last-modified'] ?? '' ),
		);
	}

	public function objects( string $prefix, ?string $cursor = null ): array {
		if ( strlen( $prefix ) > 1024 ) {
			throw new RemoteException( 'Invalid S3 listing prefix.' );
		}
		$query = array( 'list-type' => '2', 'encoding-type' => 'url', 'max-keys' => '1000', 'prefix' => $prefix );
		if ( null !== $cursor ) {
			if ( '' === $cursor || strlen( $cursor ) > self::MAX_BYTES ) {
				throw new RemoteException( 'Invalid S3 continuation token.' );
			}
			$query['continuation-token'] = $cursor;
		}
		$response = $this->request( 'GET', $this->path . '/', $query );
		$body = $response['body'];
		// Never enable entity substitution or DTD loading, including on PHP 7.4.
		if ( false !== strpos( $body, "\0" ) || preg_match( '/<!\s*(?:DOCTYPE|ENTITY)/i', $body ) || ! function_exists( 'simplexml_load_string' ) ) {
			throw new RemoteException( 'Invalid or unsupported S3 listing XML.' );
		}
		$previous = libxml_use_internal_errors( true );
		libxml_clear_errors();
		try {
			$xml = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET );
			if ( false === $xml || 'ListBucketResult' !== $xml->getName() || libxml_get_errors() ) {
				throw new RemoteException( 'Invalid S3 listing XML.' );
			}
			if ( ! in_array( (string) $xml->IsTruncated, array( 'true', 'false' ), true ) || 'url' !== (string) $xml->EncodingType || count( $xml->Contents ) > 1000 ) {
				throw new RemoteException( 'Invalid S3 listing metadata.' );
			}
			$items = array();
			foreach ( $xml->Contents as $entry ) {
				// S3 escapes literal plus as %2B; MinIO also uses '+' for spaces.
				$key = urldecode( (string) $entry->Key );
				if ( '' === $key || 0 !== strpos( $key, $prefix ) || isset( $items[ $key ] ) ) {
					throw new RemoteException( 'Invalid S3 listing key.' );
				}
				$items[ $key ] = array( 'size' => self::size( (string) $entry->Size ), 'mtime' => self::mtime( (string) $entry->LastModified ) );
			}
			$next = 'true' === (string) $xml->IsTruncated ? (string) $xml->NextContinuationToken : null;
			if ( null !== $next && ( '' === $next || $next === $cursor ) ) {
				throw new RemoteException( 'Invalid S3 listing continuation token.' );
			}
			return array( 'items' => $items, 'next' => $next );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
	}

	public function remove( string $key ): void {
		$this->request( 'DELETE', $this->objectPath( $key ) );
	}

	public function disconnect(): void {
		// WordPress owns HTTP connections; no persistent transport resources.
	}

	private function objectPath( string $key ): string {
		if ( '' === $key || strlen( $key ) > 1024 ) {
			throw new RemoteException( 'Invalid S3 object key.' );
		}
		return $this->path . '/' . self::encodePath( $key );
	}

	private static function encodePath( string $path, bool $decode = false ): string {
		$parts = explode( '/', $path );
		foreach ( $parts as &$part ) {
			if ( $decode ) {
				$part = rawurldecode( $part );
			}
			// HTTP clients normalize these segments, which would change the signed key.
			if ( '.' === $part || '..' === $part ) {
				throw new RemoteException( 'S3 dot path segments are not supported.' );
			}
			$part = rawurlencode( $part );
		}
		unset( $part );
		return implode( '/', $parts );
	}

	private static function queryString( array $query ): string {
		$encoded = array();
		foreach ( $query as $name => $value ) {
			$encoded[ rawurlencode( $name ) ] = rawurlencode( $value );
		}
		ksort( $encoded, SORT_STRING );
		$pairs = array();
		foreach ( $encoded as $name => $value ) {
			$pairs[] = $name . '=' . $value;
		}
		return implode( '&', $pairs );
	}

	/** The explicit body hash is used for every request, including empty bodies. */
	private function signedHeaders( string $method, string $path, string $query, string $body, string $date, array $extra = array() ): array {
		$hash = hash( 'sha256', $body );
		$headers = array_merge( $extra, array( 'host' => $this->host, 'x-amz-content-sha256' => $hash, 'x-amz-date' => $date ) );
		if ( '' !== $this->cfg['session_token'] ) {
			$headers['x-amz-security-token'] = $this->cfg['session_token'];
		}
		ksort( $headers, SORT_STRING );
		$canonical = '';
		foreach ( $headers as $name => &$value ) {
			$value = preg_replace( '/\s+/', ' ', trim( $value ) );
			$canonical .= $name . ':' . $value . "\n";
		}
		unset( $value );
		$names = implode( ';', array_keys( $headers ) );
		$request = $method . "\n" . $path . "\n" . $query . "\n" . $canonical . "\n" . $names . "\n" . $hash;
		$day = substr( $date, 0, 8 );
		$scope = $day . '/' . $this->cfg['region'] . '/s3/aws4_request';
		$key = hash_hmac( 'sha256', $day, 'AWS4' . $this->cfg['secret_key'], true );
		foreach ( array( $this->cfg['region'], 's3', 'aws4_request' ) as $part ) {
			$key = hash_hmac( 'sha256', $part, $key, true );
		}
		$signature = hash_hmac( 'sha256', "AWS4-HMAC-SHA256\n" . $date . "\n" . $scope . "\n" . hash( 'sha256', $request ), $key );
		$headers['authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $this->cfg['access_key'] . '/' . $scope . ', SignedHeaders=' . $names . ', Signature=' . $signature;
		return $headers;
	}

	/** @return array|null Only object GET/HEAD and DELETE may treat 404 as missing. */
	private function request( string $method, string $path, array $query = array(), string $body = '' ): ?array {
		$qs = self::queryString( $query );
		$headers = $this->signedHeaders( $method, $path, $qs, $body, gmdate( 'Ymd\THis\Z' ) );
		$headers['accept-encoding'] = 'identity';
		if ( 'PUT' === $method ) {
			$headers['content-type'] = 'application/octet-stream';
		}
		try {
			$response = call_user_func( $this->sender, $this->origin . $path . ( '' !== $qs ? '?' . $qs : '' ), array(
				'method' => $method,
				'headers' => $headers,
				'body' => $body,
				'timeout' => 60,
				'redirection' => 0,
				'sslverify' => true,
				'stream' => false,
				'decompress' => false,
				'limit_response_size' => self::MAX_BYTES + 1,
				'cookies' => array(),
				'data_format' => 'body',
			) );
		} catch ( \Throwable $e ) {
			// Only the restore runtime's fixed cURL errno message is safe to surface.
			throw new RemoteException( preg_match( '/\AHTTP request failed \(cURL error (\d+)\)\.\z/', $e->getMessage(), $m ) ? 'S3 HTTP transport failed (cURL error ' . $m[1] . ').' : 'S3 HTTP transport failed.' );
		}
		if ( $response instanceof \WP_Error || ! is_array( $response ) || ! isset( $response['response']['code'] ) ) {
			throw new RemoteException( 'S3 HTTP transport failed.' );
		}
		$status = (int) $response['response']['code'];
		if ( 404 === $status && ! $query && in_array( $method, array( 'GET', 'HEAD', 'DELETE' ), true ) ) {
			return null;
		}
		if ( 401 === $status || 403 === $status ) {
			throw new RemoteException( 'S3 authorization failed (HTTP ' . $status . ').' );
		}
		$expected = 'DELETE' === $method ? array( 200, 204 ) : array( 200 );
		if ( ! in_array( $status, $expected, true ) ) {
			throw new RemoteException( 'S3 ' . $method . ' failed (HTTP ' . $status . ').' );
		}
		$body = $response['body'] ?? '';
		if ( ! is_string( $body ) || strlen( $body ) > self::MAX_BYTES ) {
			throw new RemoteException( 'S3 response exceeds the 8 MiB limit or is invalid.' );
		}
		$headers = array();
		$raw_headers = $response['headers'] ?? array();
		if ( ! is_array( $raw_headers ) && ! $raw_headers instanceof \Traversable ) {
			throw new RemoteException( 'Invalid S3 response headers.' );
		}
		foreach ( $raw_headers as $name => $value ) {
			$headers[ strtolower( $name ) ] = is_array( $value ) ? implode( ',', $value ) : (string) $value;
		}
		// GET bodies must be the raw stored bytes so length checks and byte caps stay exact.
		if ( 'GET' === $method && isset( $headers['content-encoding'] ) && 'identity' !== strtolower( trim( $headers['content-encoding'] ) ) ) {
			throw new RemoteException( 'Unexpected S3 response encoding.' );
		}
		if ( 'HEAD' !== $method && isset( $headers['content-length'] ) && self::size( $headers['content-length'] ) !== strlen( $body ) ) {
			throw new RemoteException( 'S3 response is truncated or has an invalid length.' );
		}
		return array( 'body' => $body, 'headers' => $headers );
	}

	private static function size( string $value ): int {
		$max = (string) PHP_INT_MAX;
		if ( ! preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) || strlen( $value ) > strlen( $max ) || ( strlen( $value ) === strlen( $max ) && strcmp( $value, $max ) > 0 ) ) {
			throw new RemoteException( 'Invalid S3 object size.' );
		}
		return (int) $value;
	}

	private static function mtime( string $value ): int {
		$time = '' !== $value ? strtotime( $value ) : false;
		if ( false === $time ) {
			throw new RemoteException( 'Invalid S3 object modification time.' );
		}
		return $time;
	}
}
