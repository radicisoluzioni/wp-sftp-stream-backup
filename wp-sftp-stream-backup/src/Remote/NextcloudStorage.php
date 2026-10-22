<?php
/**
 * Nextcloud WebDAV implementation of ObjectStorage.
 *
 * Two modes:
 * - Authenticated: user + app password against /remote.php/dav/files/{user}/{path}/.
 *   Full read/list/delete/overwrite. Listing is a single PROPFIND Depth: 1 of the
 *   prefix's parent collection (direct child files only): it is never paginated
 *   ("next" is always null) and fails explicitly instead of truncating when the
 *   folder exceeds LIST_MAX_ITEMS entries or LIST_MAX_BYTES of XML.
 * - Public file drop (Nextcloud >= 29): /public.php/dav/files/{token}/ with
 *   Basic anonymous:{share password}. Upload-only, flat names only. The server
 *   renames on collision ("name (2)"), so a PUT is never retried here: on an
 *   ambiguous failure the caller must restart with a new generation name.
 *
 * @package WPSSB
 */

namespace WPSSB\Remote;

class NextcloudStorage implements ObjectStorage {

	const MAX_GET_BYTES  = 8388608;
	const LIST_MAX_BYTES = 8388608;
	const LIST_MAX_ITEMS = 10000;

	/** @var bool */
	private $public;

	/** @var string Absolute URL of the root collection, with trailing slash. */
	private $root;

	/** @var string[] Encoded segments of the configured remote folder (auth mode). */
	private $base_segments = array();

	/** @var string URL of the DAV user root (auth mode), with trailing slash. */
	private $dav_root = '';

	/** @var array<string,string> */
	private $headers;

	/** @var callable */
	private $sender;

	/** @var int */
	private $timeout;

	/** @var array<string,bool> Collections known to exist (auth mode). */
	private $known_dirs = array();

	/** @var string[] Secret strings (and encodings) removed from every exception message. */
	private $secrets = array();

	/**
	 * @param array         $cfg    url, username, password (app password), path, public_url, public_password, timeout.
	 * @param callable|null $sender function( string $method, string $url, array $args ): array WP HTTP shaped response (or WP_Error).
	 */
	public function __construct( array $cfg, ?callable $sender = null ) {
		$cfg = array_merge(
			array(
				'url'             => '',
				'username'        => '',
				'password'        => '',
				'path'            => '',
				'public_url'      => '',
				'public_password' => '',
				'timeout'         => 60,
			),
			$cfg
		);
		$this->timeout = max( 1, (int) $cfg['timeout'] );
		$this->sender  = $sender ? $sender : array( __CLASS__, 'wpSend' );
		$public_url    = trim( (string) $cfg['public_url'] );
		$this->public  = '' !== $public_url;

		if ( $this->public ) {
			list( $origin, $path ) = self::parseBaseUrl( $public_url, 'Nextcloud public share URL' );
			if ( ! preg_match( '~^(.*?)(?:/index\.php)?/s/([A-Za-z0-9_-]{1,128})$~', $path, $m ) ) {
				throw new RemoteException( 'Nextcloud public share URL must look like https://cloud.example.com/s/TOKEN.' );
			}
			$this->addSecret( $m[2] );
			$this->addSecret( (string) $cfg['public_password'] );
			$this->root    = $origin . $m[1] . '/public.php/dav/files/' . $m[2] . '/';
			$this->headers = array( 'X-Requested-With' => 'XMLHttpRequest' );
			if ( '' !== (string) $cfg['public_password'] ) {
				$this->headers['Authorization'] = 'Basic ' . base64_encode( 'anonymous:' . $cfg['public_password'] );
			}
			return;
		}

		list( $origin, $path ) = self::parseBaseUrl( (string) $cfg['url'], 'Nextcloud URL' );
		$path = (string) preg_replace( '~/index\.php$~', '', $path );
		if ( '' === (string) $cfg['username'] || '' === (string) $cfg['password'] ) {
			throw new RemoteException( 'Nextcloud username and app password are required.' );
		}
		$this->dav_root = $origin . $path . '/remote.php/dav/files/' . rawurlencode( (string) $cfg['username'] ) . '/';
		$folder         = trim( str_replace( '\\', '/', (string) $cfg['path'] ), '/' );
		if ( '' !== $folder ) {
			self::checkSegments( $folder, 'remote folder' );
			$this->base_segments = array_map( 'rawurlencode', explode( '/', $folder ) );
		}
		$this->root    = $this->dav_root . ( $this->base_segments ? implode( '/', $this->base_segments ) . '/' : '' );
		$this->headers = array(
			'Authorization' => 'Basic ' . base64_encode( $cfg['username'] . ':' . $cfg['password'] ),
		);
		$this->addSecret( (string) $cfg['password'] );
	}

	public function capabilities(): array {
		$all = ! $this->public;
		return array(
			'read'      => $all,
			'list'      => $all,
			'delete'    => $all,
			'overwrite' => $all,
		);
	}

	public function put( string $key, string $data ): void {
		$this->checkKey( $key );
		$url  = $this->root . $this->encodeKey( $key );
		$args = array(
			'body'    => $data,
			'headers' => array( 'Content-Type' => 'application/octet-stream' ),
		);
		$r    = $this->request( 'PUT', $url, $args, $key );
		$code = $r['code'];
		if ( ! $this->public && ( 404 === $code || 409 === $code ) ) {
			// Parent collection missing: create it, then repeat the (idempotent) overwrite once.
			$this->ensureParents( $key );
			$r    = $this->request( 'PUT', $url, $args, $key );
			$code = $r['code'];
		}
		if ( 200 !== $code && 201 !== $code && 204 !== $code ) {
			throw $this->statusError( 'upload', $key, $r );
		}
	}

	public function get( string $key ): ?string {
		$this->requireAuth( 'download' );
		$this->checkKey( $key );
		$r = $this->request( 'GET', $this->root . $this->encodeKey( $key ), array( 'limit_response_size' => self::MAX_GET_BYTES + 1 ), $key );
		if ( 404 === $r['code'] ) {
			return null;
		}
		if ( 200 !== $r['code'] ) {
			throw $this->statusError( 'download', $key, $r );
		}
		$len = isset( $r['headers']['content-length'] ) ? $r['headers']['content-length'] : null;
		if ( strlen( $r['body'] ) > self::MAX_GET_BYTES || ( null !== $len && (int) $len > self::MAX_GET_BYTES ) ) {
			throw $this->err( 'Nextcloud object "' . $key . '" exceeds the ' . self::MAX_GET_BYTES . ' byte download limit.' );
		}
		if ( null !== $len && ctype_digit( $len ) && (int) $len !== strlen( $r['body'] ) ) {
			throw $this->err( 'Nextcloud download of "' . $key . '" was truncated.' );
		}
		return $r['body'];
	}

	public function stat( string $key ): ?array {
		$this->requireAuth( 'stat' );
		$this->checkKey( $key );
		$r = $this->request( 'HEAD', $this->root . $this->encodeKey( $key ), array(), $key );
		if ( 404 === $r['code'] ) {
			return null;
		}
		if ( 200 !== $r['code'] ) {
			throw $this->statusError( 'stat', $key, $r );
		}
		$len   = isset( $r['headers']['content-length'] ) ? $r['headers']['content-length'] : '';
		$mtime = isset( $r['headers']['last-modified'] ) ? strtotime( $r['headers']['last-modified'] ) : false;
		if ( ! ctype_digit( $len ) || false === $mtime ) {
			throw $this->err( 'Nextcloud stat of "' . $key . '" returned no size/modification time (is it a folder?).' );
		}
		return array(
			'size'  => (int) $len,
			'mtime' => (int) $mtime,
		);
	}

	public function objects( string $prefix, ?string $cursor = null ): array {
		$this->requireAuth( 'list' );
		if ( null !== $cursor ) {
			throw $this->err( 'Nextcloud listings are not paginated; cursor must be null.' );
		}
		$slash  = strrpos( $prefix, '/' );
		$dir    = false === $slash ? '' : substr( $prefix, 0, $slash );
		$filter = false === $slash ? $prefix : substr( $prefix, $slash + 1 );
		if ( '' !== $dir ) {
			self::checkSegments( $dir, 'listing prefix' );
		}
		if ( preg_match( '/[\x00-\x1f\x7f\\\\]/', $filter ) ) {
			throw $this->err( 'Invalid Nextcloud listing prefix "' . $prefix . '".' );
		}
		$url  = $this->root . ( '' === $dir ? '' : $this->encodeKey( $dir ) . '/' );
		$body = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/><d:getcontentlength/><d:getlastmodified/></d:prop></d:propfind>';
		$r    = $this->request(
			'PROPFIND',
			$url,
			array(
				'body'                => $body,
				'headers'             => array(
					'Depth'        => '1',
					'Content-Type' => 'application/xml; charset=utf-8',
				),
				'limit_response_size' => self::LIST_MAX_BYTES + 1,
			),
			$prefix
		);
		if ( 404 === $r['code'] ) {
			return array(
				'items' => array(),
				'next'  => null,
			);
		}
		if ( 207 !== $r['code'] ) {
			throw $this->statusError( 'list', $prefix, $r );
		}
		if ( strlen( $r['body'] ) > self::LIST_MAX_BYTES ) {
			throw $this->err( 'Nextcloud listing of "' . $prefix . '" exceeds ' . self::LIST_MAX_BYTES . ' bytes; the folder has too many entries.' );
		}
		$items = array();
		foreach ( $this->parseMultistatus( $r['body'], (string) parse_url( $url, PHP_URL_PATH ) ) as $name => $info ) {
			if ( '' !== $filter && 0 !== strpos( (string) $name, $filter ) ) {
				continue;
			}
			$items[ ( '' === $dir ? '' : $dir . '/' ) . $name ] = $info;
		}
		ksort( $items, SORT_STRING );
		return array(
			'items' => $items,
			'next'  => null,
		);
	}

	public function remove( string $key ): void {
		$this->requireAuth( 'delete' );
		$this->checkKey( $key );
		$r = $this->request( 'DELETE', $this->root . $this->encodeKey( $key ), array(), $key );
		if ( 200 !== $r['code'] && 204 !== $r['code'] && 404 !== $r['code'] ) {
			throw $this->statusError( 'delete', $key, $r );
		}
	}

	public function disconnect(): void {
		$this->known_dirs = array();
	}

	/**
	 * Default sender based on the WordPress HTTP API.
	 *
	 * @return array|object
	 */
	public static function wpSend( string $method, string $url, array $args ) {
		if ( ! function_exists( 'wp_remote_request' ) ) {
			throw new RemoteException( 'WordPress HTTP API is not available.' );
		}
		$args['method'] = $method;
		return wp_remote_request( $url, $args );
	}

	/**
	 * @return array{0:string,1:string} origin (scheme://host[:port]) and path without trailing slash.
	 */
	private static function parseBaseUrl( string $url, string $label ): array {
		$p = parse_url( trim( $url ) );
		if ( ! is_array( $p ) || empty( $p['scheme'] ) || empty( $p['host'] ) ) {
			throw new RemoteException( $label . ' is not a valid URL.' );
		}
		$scheme   = strtolower( $p['scheme'] );
		$insecure = defined( 'WPSSB_ALLOW_INSECURE_REMOTE' ) && constant( 'WPSSB_ALLOW_INSECURE_REMOTE' );
		if ( 'https' !== $scheme && ! ( 'http' === $scheme && $insecure ) ) {
			throw new RemoteException( $label . ' must use https://.' );
		}
		if ( isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['query'] ) || isset( $p['fragment'] ) ) {
			throw new RemoteException( $label . ' must not contain credentials, query or fragment.' );
		}
		$path = isset( $p['path'] ) ? rtrim( $p['path'], '/' ) : '';
		if ( preg_match( '/[\x00-\x20\x7f"<>\\\\^`{|}]/', $path ) ) {
			throw new RemoteException( $label . ' contains invalid characters.' );
		}
		return array( $scheme . '://' . strtolower( $p['host'] ) . ( isset( $p['port'] ) ? ':' . (int) $p['port'] : '' ), $path );
	}

	private static function checkSegments( string $path, string $what ): void {
		foreach ( explode( '/', $path ) as $seg ) {
			if ( '' === $seg || '.' === $seg || '..' === $seg || preg_match( '/[\x00-\x1f\x7f\\\\]/', $seg ) ) {
				throw new RemoteException( 'Invalid Nextcloud ' . $what . ' "' . $path . '".' );
			}
		}
	}

	private function addSecret( string $secret ): void {
		if ( '' === $secret ) {
			return;
		}
		foreach ( array( $secret, rawurlencode( $secret ), urlencode( $secret ), htmlspecialchars( $secret, ENT_QUOTES | ENT_XML1 ) ) as $v ) {
			$this->secrets[ $v ] = strlen( $v );
		}
		if ( isset( $this->headers['Authorization'] ) ) {
			$b64                   = substr( $this->headers['Authorization'], 6 );
			$this->secrets[ $b64 ] = strlen( $b64 );
		}
		arsort( $this->secrets );
	}

	/**
	 * Builds an exception whose message never contains the share token, passwords or auth header.
	 */
	private function err( string $message ): RemoteException {
		foreach ( array_keys( $this->secrets ) as $s ) {
			$message = str_ireplace( (string) $s, '[redacted]', $message );
		}
		return new RemoteException( $message );
	}

	private function checkKey( string $key ): void {
		if ( '' === $key ) {
			throw $this->err( 'Empty Nextcloud object key.' );
		}
		if ( $this->public && false !== strpos( $key, '/' ) ) {
			throw $this->err( 'Nextcloud file drop accepts flat file names only, got "' . $key . '".' );
		}
		self::checkSegments( $key, 'object key' );
	}

	private function encodeKey( string $key ): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );
	}

	private function requireAuth( string $op ): void {
		if ( $this->public ) {
			throw $this->err( 'Nextcloud public file drop is upload-only: ' . $op . ' is unavailable.' );
		}
	}

	private function ensureParents( string $key ): void {
		$segments = array_merge( $this->base_segments, explode( '/', $this->encodeKey( $key ) ) );
		array_pop( $segments );
		$url = $this->dav_root;
		foreach ( $segments as $seg ) {
			$url .= $seg . '/';
			if ( isset( $this->known_dirs[ $url ] ) ) {
				continue;
			}
			$r = $this->request( 'MKCOL', $url, array(), rawurldecode( $seg ) );
			// 405: the collection already exists.
			if ( 201 !== $r['code'] && 405 !== $r['code'] ) {
				throw $this->statusError( 'create folder for', $key, $r );
			}
			$this->known_dirs[ $url ] = true;
		}
	}

	/**
	 * @return array{code:int,body:string,headers:array<string,string>}
	 */
	private function request( string $method, string $url, array $args, string $subject ): array {
		// Content-Length checks and the memory caps need the raw identity body: WordPress
		// otherwise negotiates gzip and decodes it while keeping the compressed Content-Length.
		$args['headers']     = array_merge( $this->headers, isset( $args['headers'] ) ? $args['headers'] : array(), array( 'Accept-Encoding' => 'identity' ) );
		$args['decompress']  = false;
		$args['timeout']     = $this->timeout;
		$args['redirection'] = 0;
		$args['sslverify']   = true;
		try {
			$res = call_user_func( $this->sender, $method, $url, $args );
		} catch ( \Exception $e ) {
			// Not chained: the original message may contain the request URL (share token).
			throw $this->err( 'Nextcloud ' . $method . ' "' . $subject . '" failed: ' . $e->getMessage() );
		}
		if ( is_object( $res ) && method_exists( $res, 'get_error_message' ) ) {
			throw $this->err( 'Nextcloud ' . $method . ' "' . $subject . '" failed: ' . $res->get_error_message() );
		}
		if ( ! is_array( $res ) || ! isset( $res['response']['code'] ) || ! is_numeric( $res['response']['code'] ) ) {
			throw $this->err( 'Nextcloud ' . $method . ' "' . $subject . '" returned an invalid HTTP response.' );
		}
		$headers = array();
		if ( isset( $res['headers'] ) && ( is_array( $res['headers'] ) || $res['headers'] instanceof \Traversable ) ) {
			foreach ( $res['headers'] as $k => $v ) {
				$headers[ strtolower( (string) $k ) ] = trim( (string) ( is_array( $v ) ? end( $v ) : $v ) );
			}
		}
		$code     = (int) $res['response']['code'];
		$body     = isset( $res['body'] ) ? (string) $res['body'] : '';
		$encoding = isset( $headers['content-encoding'] ) ? strtolower( $headers['content-encoding'] ) : '';
		if ( '' !== $encoding && 'identity' !== $encoding ) {
			if ( $code >= 200 && $code < 300 ) {
				throw $this->err( 'Nextcloud ' . $method . ' "' . $subject . '" returned unexpected Content-Encoding "' . substr( $encoding, 0, 40 ) . '" despite Accept-Encoding: identity; refusing to decode.' );
			}
			$body = '';
		}
		if ( $code >= 300 && $code < 400 ) {
			throw $this->err(
				'Nextcloud ' . $method . ' "' . $subject . '" was redirected (HTTP ' . $code . '); redirects are not followed, check the configured URL.'
			);
		}
		return array(
			'code'    => $code,
			'body'    => $body,
			'headers' => $headers,
		);
	}

	private function statusError( string $op, string $subject, array $r ): RemoteException {
		$code = $r['code'];
		$map  = array(
			400 => 'bad request',
			401 => $this->public ? 'share password missing or wrong' : 'authentication failed, check username and app password',
			403 => 'forbidden (missing permission, or name blocked by the server)',
			404 => $this->public ? 'share not found or expired' : 'not found',
			405 => 'method not allowed on this resource',
			409 => 'conflict (parent folder missing or not a folder)',
			412 => 'precondition failed',
			413 => 'payload too large for the server',
			415 => 'unsupported media type',
			423 => 'resource is locked',
			429 => 'rate limited by the server',
			500 => 'internal server error',
			502 => 'bad gateway',
			503 => $this->public ? 'share not found, expired, or service unavailable' : 'service unavailable (maintenance mode?)',
			504 => 'gateway timeout',
			507 => 'insufficient storage, quota exceeded',
		);
		$why  = isset( $map[ $code ] ) ? $map[ $code ] : ( $code >= 500 ? 'server error' : 'unexpected status' );
		$detail = '';
		if ( preg_match( '~<s:message>([^<]{1,300})</s:message>~', substr( $r['body'], 0, 4096 ), $m ) ) {
			$detail = ' Server: ' . trim( preg_replace( '/[\x00-\x1f\x7f]+/', ' ', html_entity_decode( $m[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ) ) );
		}
		return $this->err( 'Nextcloud ' . $op . ' "' . $subject . '" failed: HTTP ' . $code . ' ' . $why . '.' . $detail );
	}

	/**
	 * Parses a Depth: 1 multistatus, returning direct child files only.
	 *
	 * @return array<string,array{size:int,mtime:int}>
	 */
	private function parseMultistatus( string $xml, string $collection_path ): array {
		if ( preg_match( '/<!DOCTYPE|<!ENTITY/i', $xml ) ) {
			throw $this->err( 'Nextcloud listing contains a forbidden DTD.' );
		}
		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$ok   = '' !== $xml && $doc->loadXML( $xml, LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok || ! $doc->documentElement || 'multistatus' !== $doc->documentElement->localName || 'DAV:' !== $doc->documentElement->namespaceURI ) {
			throw $this->err( 'Nextcloud listing returned malformed XML.' );
		}
		$xp = new \DOMXPath( $doc );
		$xp->registerNamespace( 'd', 'DAV:' );
		$base      = rtrim( rawurldecode( $collection_path ), '/' ) . '/';
		$responses = $xp->query( '/d:multistatus/d:response' );
		if ( $responses->length > self::LIST_MAX_ITEMS + 1 ) {
			throw $this->err( 'Nextcloud folder has more than ' . self::LIST_MAX_ITEMS . ' entries; listing refused instead of truncating.' );
		}
		$out = array();
		foreach ( $responses as $resp ) {
			$path = parse_url( trim( (string) $xp->evaluate( 'string(d:href)', $resp ) ), PHP_URL_PATH );
			if ( ! is_string( $path ) ) {
				throw $this->err( 'Nextcloud listing contains an invalid href.' );
			}
			$path = rawurldecode( $path );
			if ( rtrim( $path, '/' ) . '/' === $base ) {
				continue;
			}
			$name = 0 === strpos( $path, $base ) ? rtrim( substr( $path, strlen( $base ) ), '/' ) : '';
			if ( '' === $name || false !== strpos( $name, '/' ) ) {
				throw $this->err( 'Nextcloud listing contains an unexpected href outside the folder.' );
			}
			$prop = null;
			foreach ( $xp->query( 'd:propstat', $resp ) as $ps ) {
				if ( preg_match( '~^HTTP/\S+\s+200\b~', trim( (string) $xp->evaluate( 'string(d:status)', $ps ) ) ) ) {
					$prop = $xp->query( 'd:prop', $ps )->item( 0 );
					break;
				}
			}
			if ( ! $prop ) {
				throw $this->err( 'Nextcloud listing entry "' . $name . '" has no readable properties.' );
			}
			if ( $xp->query( 'd:resourcetype/d:collection', $prop )->length > 0 ) {
				continue;
			}
			$size  = trim( (string) $xp->evaluate( 'string(d:getcontentlength)', $prop ) );
			$mtime = strtotime( trim( (string) $xp->evaluate( 'string(d:getlastmodified)', $prop ) ) );
			if ( ! ctype_digit( $size ) || false === $mtime ) {
				throw $this->err( 'Nextcloud listing entry "' . $name . '" lacks size or modification time.' );
			}
			$out[ $name ] = array(
				'size'  => (int) $size,
				'mtime' => (int) $mtime,
			);
		}
		return $out;
	}
}