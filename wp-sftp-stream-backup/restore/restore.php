<?php
/**
 * WP SFTP Stream Backup — standalone restore script.
 *
 * HOW TO USE
 *  1. Download all objects from the selected backup folder, including
 *     manifest.json and this restore.php (keep any public destination prefix).
 *  2. Upload them into the (empty) web root of the destination site.
 *  3. Create an empty MySQL/MariaDB database (or reuse one: tables with the
 *     same names will be replaced).
 *  4a. Browser: set a password below, then open https://your-site/restore.php
 *  4b. Shell:   php restore.php --db-host=localhost --db-name=NAME --db-user=USER
 *               --db-pass=PASS [--url=https://new-site] [--delete-archives] [--self-delete]
 *
 * The restore runs in short resumable steps, so it also works with low PHP
 * time limits. At the end restore.php and its state file delete themselves.
 *
 * Alternative manual restore: `tar xzf files.tar.gz` and
 * `gunzip < database.sql.gz | mysql -u USER -p NAME`, then edit wp-config.php.
 *
 * @package WPSSB
 */

// ============================ CONFIGURATION =============================
// Required for browser use: choose a password (at least 8 characters).
define( 'WPSSB_RESTORE_PASSWORD', '' );
// ========================================================================

define( 'RS_VERSION', '1.0.0' );
if ( ! defined( 'RS_DIR' ) ) {
	define( 'RS_DIR', str_replace( '\\', '/', __DIR__ ) );
}
if ( ! defined( 'RS_STATE' ) ) {
	define( 'RS_STATE', RS_DIR . '/wpssb-restore-state.php' );
}
if ( ! defined( 'RS_LOCK' ) ) {
	define( 'RS_LOCK', RS_DIR . '/wpssb-restore.lock' );
}
if ( ! defined( 'RS_EXTRACTED' ) ) {
	define( 'RS_EXTRACTED', RS_DIR . '/wpssb-restore-extracted.log' );
}
define( 'RS_PROGRESS_TABLE', 'wpssb_restore_progress' );
if ( ! defined( 'RS_CONFIG_ORIG' ) ) {
	define( 'RS_CONFIG_ORIG', 'wp-config.php.wpssb-original' );
}
define( 'RS_FILES', 'files.tar.gz' );
define( 'RS_DB', 'database.sql.gz' );
define( 'RS_MANIFEST_EXPLICIT', defined( 'RS_MANIFEST' ) );
if ( ! defined( 'RS_MANIFEST' ) ) {
	define( 'RS_MANIFEST', 'manifest.json' );
}
define( 'RS_CLI', PHP_SAPI === 'cli' );

error_reporting( E_ALL & ~E_DEPRECATED );
if ( ! RS_CLI ) {
	@ini_set( 'display_errors', '0' );
}

/* ====================================================================== */
/* Multi-member gzip reader with resumable positions                      */
/* ====================================================================== */

class RsFileSource {
	private $fp;
	private $length;

	public function __construct( $path ) {
		$this->fp = @fopen( $path, 'rb' );
		if ( ! $this->fp ) {
			throw new RuntimeException( 'Cannot open ' . basename( $path ) );
		}
		$stat = fstat( $this->fp );
		$this->length = (int) $stat['size'];
	}

	public function __destruct() {
		if ( $this->fp ) {
			fclose( $this->fp );
		}
	}

	public function size() {
		return $this->length;
	}

	public function seek( $offset ) {
		if ( $offset < 0 || $offset > $this->length || 0 !== fseek( $this->fp, $offset ) ) {
			throw new RuntimeException( 'Cannot seek in archive.' );
		}
	}

	public function read( $length ) {
		return fread( $this->fp, $length );
	}
}

/** Backup objects stored as files in a local directory. */
class RsLocalStore {
	private $dir;

	public function __construct( $dir ) {
		$this->dir = rtrim( str_replace( '\\', '/', (string) $dir ), '/' );
	}

	public function local() {
		return true;
	}

	public function dir() {
		return $this->dir;
	}

	public function exists( $name ) {
		return file_exists( $this->dir . '/' . $name );
	}

	public function object( $name, $limit ) {
		return RsPartsSource::object( $this->dir . '/' . $name, $limit );
	}

	public function checkPart( $name, $size ) {
		$file = $this->dir . '/' . $name;
		clearstatcache( true, $file );
		if ( is_link( $file ) || ! is_file( $file ) || ! is_readable( $file ) || filesize( $file ) !== $size ) {
			throw new RuntimeException( 'Missing or truncated backup part: ' . $name );
		}
	}

	public function source( $name ) {
		return new RsFileSource( $this->dir . '/' . $name );
	}
}

/** Store used for archives and manifests: local by default, remote for one-click restores. */
function rs_store() {
	if ( empty( $GLOBALS['rs_store'] ) ) {
		$GLOBALS['rs_store'] = new RsLocalStore( RS_DIR );
	}
	return $GLOBALS['rs_store'];
}

/** Seekable compressed bytes, holding only one catalog page and one payload object. */
class RsPartsSource {
	const PART_BYTES = 8388608;
	const PAGE_PARTS = 256;
	const PAGE_BYTES = 262144;

	private $name;
	private $store;
	private $prefix;
	private $head;
	private $page;
	private $page_no = -1;
	private $position = 0;
	private $part = '';
	private $part_offset = -1;

	public static function flatName( $name ) {
		return is_string( $name ) && strlen( $name ) <= 255 && 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $name );
	}

	public static function object( $path, $limit ) {
		clearstatcache( true, $path );
		if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) || filesize( $path ) > $limit ) {
			throw new RuntimeException( 'Missing or oversized backup object: ' . basename( $path ) );
		}
		$data = @file_get_contents( $path, false, null, 0, $limit );
		clearstatcache( true, $path );
		if ( false === $data || strlen( $data ) !== filesize( $path ) || strlen( $data ) > $limit ) {
			throw new RuntimeException( 'Cannot read bounded backup object: ' . basename( $path ) );
		}
		return $data;
	}

	private static function decode( $json ) {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) {
			throw new RuntimeException( 'Invalid backup catalog JSON.' );
		}
		return $data;
	}

	private static function digest( $value ) {
		return is_string( $value ) && 1 === preg_match( '/\A[0-9a-f]{64}\z/D', $value );
	}

	public function __construct( $path, $prefix = '', $head_hash = null, $store = null ) {
		if ( ! self::flatName( basename( $path ) ) || ( '' !== $prefix && ! self::flatName( $prefix ) ) ) {
			throw new RuntimeException( 'Unsafe backup object name.' );
		}
		$this->name = basename( $path );
		$this->store = $store ? $store : new RsLocalStore( dirname( $path ) );
		$this->prefix = $prefix;
		$json = $this->store->object( $this->name . '.parts.json', 4096 );
		if ( null !== $head_hash && ( ! self::digest( $head_hash ) || ! hash_equals( $head_hash, hash( 'sha256', $json ) ) ) ) {
			throw new RuntimeException( 'Backup head checksum does not match the manifest.' );
		}
		$this->head = self::decode( $json );
		$h = $this->head;
		if ( ! isset( $h['format'], $h['bytes'], $h['count'], $h['sha256'] ) || 2 !== $h['format'] ||
			! is_int( $h['bytes'] ) || ! is_int( $h['count'] ) || $h['bytes'] < 0 || $h['count'] < 0 ||
			$h['count'] > $h['bytes'] || ( 0 === $h['count'] ? ( 0 !== $h['bytes'] || '' !== $h['sha256'] ) : ! self::digest( $h['sha256'] ) ) ) {
			throw new RuntimeException( 'Invalid segmented archive head.' );
		}
		// Authenticate the complete bounded catalog before permitting seeks or extraction.
		$offset = 0;
		$previous = '';
		for ( $i = 0; $i < $this->pageCount(); $i++ ) {
			list( $page, $hash ) = $this->loadPage( $i );
			if ( $page['previous'] !== $previous ) {
				throw new RuntimeException( 'Broken backup catalog page chain.' );
			}
			foreach ( $page['parts'] as $part ) {
				if ( $part['offset'] !== $offset || $part['size'] > $h['bytes'] - $offset ) {
					throw new RuntimeException( 'Invalid backup part offset or size.' );
				}
				$this->store->checkPart( $this->partName( $part ), $part['size'] );
				$offset += $part['size'];
			}
			$previous = $hash;
		}
		if ( $offset !== $h['bytes'] || $previous !== $h['sha256'] ) {
			throw new RuntimeException( 'Backup catalog does not match its head.' );
		}
	}

	private function pageCount() {
		return $this->head['count'] ? intdiv( $this->head['count'] - 1, self::PAGE_PARTS ) + 1 : 0;
	}

	private function pageName( $number ) {
		return $this->name . '.index-' . sprintf( '%08d', $number ) . '.json';
	}

	private function partName( array $part ) {
		$name = $this->prefix . $part['file'];
		if ( ! self::flatName( $name ) ) {
			throw new RuntimeException( 'Unsafe backup part name.' );
		}
		return $name;
	}

	private function loadPage( $number ) {
		$json = $this->store->object( $this->pageName( $number ), self::PAGE_BYTES );
		$page = self::decode( $json );
		$count = min( self::PAGE_PARTS, $this->head['count'] - $number * self::PAGE_PARTS );
		if ( ! isset( $page['previous'], $page['parts'] ) || ! is_string( $page['previous'] ) ||
			( 0 === $number ? '' !== $page['previous'] : ! self::digest( $page['previous'] ) ) ||
			! is_array( $page['parts'] ) || count( $page['parts'] ) !== $count || array_keys( $page['parts'] ) !== range( 0, $count - 1 ) ) {
			throw new RuntimeException( 'Invalid backup catalog page.' );
		}
		foreach ( $page['parts'] as $part ) {
			if ( ! is_array( $part ) || ! isset( $part['file'], $part['size'], $part['offset'], $part['sha256'] ) ||
				! self::flatName( $part['file'] ) || ! is_int( $part['size'] ) || $part['size'] < 1 || $part['size'] > self::PART_BYTES ||
				! is_int( $part['offset'] ) || $part['offset'] < 0 || ! self::digest( $part['sha256'] ) ) {
				throw new RuntimeException( 'Invalid backup part descriptor.' );
			}
			$this->partName( $part );
		}
		return array( $page, hash( 'sha256', $json ) );
	}

	private function selectPage( $number ) {
		list( $page, $hash ) = $this->loadPage( $number );
		if ( $number + 1 < $this->pageCount() ) {
			list( $next ) = $this->loadPage( $number + 1 );
			$expected = $next['previous'];
		} else {
			$expected = $this->head['sha256'];
		}
		if ( ! hash_equals( $expected, $hash ) ) {
			throw new RuntimeException( 'Backup catalog page checksum mismatch.' );
		}
		$this->page = $page;
		$this->page_no = $number;
	}

	public function size() {
		return $this->head['bytes'];
	}

	public function seek( $offset ) {
		if ( ! is_int( $offset ) || $offset < 0 || $offset > $this->size() ) {
			throw new RuntimeException( 'Cannot seek in segmented archive.' );
		}
		$this->position = $offset;
	}

	public function read( $length ) {
		$out = '';
		while ( strlen( $out ) < $length && $this->position < $this->size() ) {
			if ( $this->position < $this->part_offset || $this->position >= $this->part_offset + strlen( $this->part ) ) {
				$number = max( 0, $this->page_no );
				if ( $this->page && $this->position < $this->page['parts'][0]['offset'] ) {
					$number = 0;
				}
				$found = false;
				for ( ; $number < $this->pageCount(); $number++ ) {
					if ( $number !== $this->page_no ) {
						$this->selectPage( $number );
					}
					foreach ( $this->page['parts'] as $part ) {
						if ( $part['offset'] <= $this->position && $this->position - $part['offset'] < $part['size'] ) {
							$this->part = '';
							$data = $this->store->object( $this->partName( $part ), self::PART_BYTES );
							if ( strlen( $data ) !== $part['size'] || ! hash_equals( $part['sha256'], hash( 'sha256', $data ) ) ) {
								throw new RuntimeException( 'Backup part checksum mismatch: ' . $part['file'] );
							}
							$this->part = $data;
							unset( $data );
							$this->part_offset = $part['offset'];
							$found = true;
							break 2;
						}
					}
				}
				if ( ! $found ) {
					throw new RuntimeException( 'Cannot locate backup part.' );
				}
			}
			$chunk = substr( $this->part, $this->position - $this->part_offset, $length - strlen( $out ) );
			$out .= $chunk;
			$this->position += strlen( $chunk );
		}
		return $out;
	}

	public function objectNames() {
		for ( $i = 0; $i < $this->pageCount(); $i++ ) {
			list( $page ) = $this->loadPage( $i );
			foreach ( $page['parts'] as $part ) {
				yield $this->partName( $part );
			}
			yield $this->pageName( $i );
		}
		yield $this->name . '.parts.json';
	}
}

class RsGzReader {

	const CHUNK = 16384;

	private $source;
	private $size;
	private $ctx;
	private $member_c;
	private $fed = 0;
	private $produced;
	private $buf = '';
	private $off = 0;
	private $pos;
	private $eof = false;
	private $bounds = array();

	/**
	 * @param string $path     Gzip file.
	 * @param int    $member_c Compressed offset of the member containing $pos.
	 * @param int    $member_u Uncompressed offset at which that member starts.
	 * @param int    $pos      Uncompressed position to resume from.
	 */
	public function __construct( $path, $member_c, $member_u, $pos ) {
		$this->source = is_object( $path ) ? $path : rs_archive_source( $path );
		$this->size = $this->source->size();
		if ( 0 === $this->size || $member_c < 0 || $member_c > $this->size || $member_u < 0 || $pos < $member_u ) {
			throw new RuntimeException( 'Empty archive or invalid gzip checkpoint.' );
		}
		$this->produced = (int) $member_u;
		$this->pos      = (int) $member_u;
		$this->startMember( (int) $member_c, (int) $member_u );
		$this->skip( (int) $pos - (int) $member_u );
	}

	public function compressedSize() {
		return $this->size;
	}

	private function startMember( $c, $u ) {
		if ( $c >= $this->size ) {
			$this->eof = true;
			return;
		}
		$this->source->seek( $c );
		$this->member_c = $c;
		$this->fed      = 0;
		$this->ctx      = inflate_init( ZLIB_ENCODING_GZIP );
		$this->bounds[] = array( $c, $u );
		if ( count( $this->bounds ) > 64 ) {
			array_shift( $this->bounds );
		}
	}

	private function fill() {
		if ( $this->eof ) {
			return false;
		}
		$chunk = $this->source->read( self::CHUNK );
		if ( false === $chunk || '' === $chunk ) {
			throw new RuntimeException( 'Unexpected end of compressed data (archive truncated?)' );
		}
		$this->fed += strlen( $chunk );
		$out        = @inflate_add( $this->ctx, $chunk, ZLIB_SYNC_FLUSH );
		if ( false === $out ) {
			throw new RuntimeException( 'Corrupted gzip data near offset ' . $this->member_c );
		}
		if ( '' !== $out ) {
			$this->buf      .= $out;
			$this->produced += strlen( $out );
		}
		if ( ZLIB_STREAM_END === inflate_get_status( $this->ctx ) ) {
			$this->startMember( $this->member_c + inflate_get_read_len( $this->ctx ), $this->produced );
		}
		return true;
	}

	/** Reads up to $n bytes ('' at end of stream). */
	public function read( $n ) {
		while ( strlen( $this->buf ) - $this->off < $n && $this->fill() ) {
			if ( $this->off > 1048576 ) {
				$this->buf = substr( $this->buf, $this->off );
				$this->off = 0;
			}
		}
		$out        = (string) substr( $this->buf, $this->off, $n );
		$this->off += strlen( $out );
		$this->pos += strlen( $out );
		if ( $this->off > 1048576 ) {
			$this->buf = (string) substr( $this->buf, $this->off );
			$this->off = 0;
		}
		return $out;
	}

	public function skip( $n ) {
		while ( $n > 0 ) {
			$got = strlen( $this->read( min( $n, 1048576 ) ) );
			if ( 0 === $got ) {
				throw new RuntimeException( 'Cannot seek: unexpected end of data' );
			}
			$n -= $got;
		}
	}

	public function position() {
		return $this->pos;
	}

	/** Compressed progress (bytes of the file consumed). */
	public function compressedPosition() {
		return $this->eof ? $this->size : $this->member_c + $this->fed;
	}

	/** [member_c, member_u] of the member containing uncompressed offset $pos. */
	public function checkpoint( $pos ) {
		$best = $this->bounds[0];
		foreach ( $this->bounds as $b ) {
			if ( $b[1] <= $pos ) {
				$best = $b;
			}
		}
		return $best;
	}
}

/* ====================================================================== */
/* Serialization-aware search & replace                                   */
/* ====================================================================== */

class RsSerialized {

	/** @return string|null Rebuilt data, null when $data is not serialized. */
	public static function replace( $data, array $pairs ) {
		$i = 0;
		try {
			$out = self::value( $data, $i, $pairs );
		} catch ( RuntimeException $e ) {
			return null;
		}
		return strlen( $data ) === $i ? $out : null;
	}

	public static function replaceValue( $value, array $pairs ) {
		$hit = false;
		foreach ( $pairs as $from => $to ) {
			if ( false !== strpos( $value, (string) $from ) ) {
				$hit = true;
				break;
			}
		}
		if ( ! $hit ) {
			return $value;
		}
		if ( preg_match( '/^(?:a:\d+:\{|O:\d+:"|s:\d+:"|C:\d+:"|[bid]:|N;)/', $value ) ) {
			$r = self::replace( $value, $pairs );
			if ( null !== $r ) {
				return $r;
			}
		}
		return strtr( $value, $pairs );
	}

	private static function fail() {
		throw new RuntimeException( 'not serialized' );
	}

	private static function expect( $s, &$i, $tok ) {
		if ( substr( $s, $i, strlen( $tok ) ) !== $tok ) {
			self::fail();
		}
		$i += strlen( $tok );
	}

	private static function digits( $s, &$i, $term ) {
		$j = $i;
		$l = strlen( $s );
		while ( $j < $l && ctype_digit( $s[ $j ] ) ) {
			$j++;
		}
		if ( $j === $i || $j >= $l || $s[ $j ] !== $term ) {
			self::fail();
		}
		$n = (int) substr( $s, $i, $j - $i );
		$i = $j + 1;
		return $n;
	}

	private static function str( $s, &$i, $type ) {
		self::expect( $s, $i, $type . ':' );
		$n = self::digits( $s, $i, ':' );
		self::expect( $s, $i, '"' );
		if ( $i + $n > strlen( $s ) ) {
			self::fail();
		}
		$payload = substr( $s, $i, $n );
		$i      += $n;
		return $payload;
	}

	private static function scalar( $s, &$i ) {
		$end = strpos( $s, ';', $i );
		if ( false === $end || ! isset( $s[ $i + 1 ] ) || ':' !== $s[ $i + 1 ] ) {
			self::fail();
		}
		$tok = substr( $s, $i, $end - $i + 1 );
		$i   = $end + 1;
		return $tok;
	}

	private static function key( $s, &$i ) {
		if ( ! isset( $s[ $i ] ) ) {
			self::fail();
		}
		if ( 'i' === $s[ $i ] ) {
			return self::scalar( $s, $i );
		}
		if ( 's' === $s[ $i ] ) {
			$start = $i;
			self::str( $s, $i, 's' );
			self::expect( $s, $i, '";' );
			return substr( $s, $start, $i - $start );
		}
		self::fail();
	}

	private static function value( $s, &$i, array $pairs ) {
		if ( ! isset( $s[ $i ] ) ) {
			self::fail();
		}
		switch ( $s[ $i ] ) {
			case 'N':
				self::expect( $s, $i, 'N;' );
				return 'N;';
			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				return self::scalar( $s, $i );
			case 's':
				$payload = self::str( $s, $i, 's' );
				self::expect( $s, $i, '";' );
				$new = self::inner( $payload, $pairs );
				return 's:' . strlen( $new ) . ':"' . $new . '";';
			case 'a':
				self::expect( $s, $i, 'a:' );
				$n = self::digits( $s, $i, ':' );
				self::expect( $s, $i, '{' );
				$out = 'a:' . $n . ':{';
				for ( $k = 0; $k < $n; $k++ ) {
					$out .= self::key( $s, $i );
					$out .= self::value( $s, $i, $pairs );
				}
				self::expect( $s, $i, '}' );
				return $out . '}';
			case 'O':
				$class = self::str( $s, $i, 'O' );
				self::expect( $s, $i, '":' );
				$n = self::digits( $s, $i, ':' );
				self::expect( $s, $i, '{' );
				$out = 'O:' . strlen( $class ) . ':"' . $class . '":' . $n . ':{';
				for ( $k = 0; $k < $n; $k++ ) {
					$out .= self::key( $s, $i );
					$out .= self::value( $s, $i, $pairs );
				}
				self::expect( $s, $i, '}' );
				return $out . '}';
			case 'C':
				$start = $i;
				self::str( $s, $i, 'C' );
				self::expect( $s, $i, '":' );
				$n = self::digits( $s, $i, ':' );
				self::expect( $s, $i, '{' );
				$i += $n;
				self::expect( $s, $i, '}' );
				return substr( $s, $start, $i - $start );
			case 'E':
				$start = $i;
				self::str( $s, $i, 'E' );
				self::expect( $s, $i, '";' );
				return substr( $s, $start, $i - $start );
		}
		self::fail();
	}

	private static function inner( $payload, array $pairs ) {
		if ( preg_match( '/^(?:a:\d+:\{|O:\d+:"|s:\d+:")/', $payload ) ) {
			$r = self::replace( $payload, $pairs );
			if ( null !== $r ) {
				return $r;
			}
		}
		return strtr( $payload, $pairs );
	}
}

/* ====================================================================== */
/* Helpers                                                                */
/* ====================================================================== */

function rs_safe_path( $name ) {
	$name = str_replace( '\\', '/', (string) $name );
	if ( '' === $name || false !== strpos( $name, "\0" ) || '/' === $name[0] || preg_match( '#^[A-Za-z]:#', $name ) ) {
		return null;
	}
	$parts = array();
	foreach ( explode( '/', $name ) as $p ) {
		if ( '' === $p || '.' === $p ) {
			continue;
		}
		if ( '..' === $p ) {
			return null;
		}
		$parts[] = $p;
	}
	return $parts ? implode( '/', $parts ) : null;
}

function rs_protected_names() {
	return array( basename( __FILE__ ), basename( RS_STATE ), basename( RS_STATE ) . '.tmp', basename( RS_LOCK ), basename( RS_EXTRACTED ), RS_CONFIG_ORIG, RS_FILES, RS_DB, RS_MANIFEST, basename( rs_manifest_file() ) );
}

/** True when $name equals one of $names (paths relative to RS_DIR) or lies below it. */
function rs_is_protected( $name, array $names, array $sources ) {
	foreach ( $names as $protected ) {
		$protected = (string) $protected;
		if ( '' !== $protected && ( $name === $protected || 0 === strpos( $name, $protected . '/' ) ) ) {
			return true;
		}
	}
	$root = explode( '/', $name, 2 )[0];
	if ( ! rs_store()->local() || is_dir( RS_DIR . '/' . $root ) || ! file_exists( RS_DIR . '/' . $root ) ) {
		return false;
	}
	foreach ( $sources as $source ) {
		foreach ( $source->objectNames() as $object ) {
			if ( $root === $object ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Exclusive, non-blocking lock so that two requests never run restore steps at the same time.
 *
 * @return resource|null Lock handle, null when another process holds it.
 */
function rs_lock() {
	$h = @fopen( RS_LOCK, 'c' );
	if ( ! $h ) {
		throw new RuntimeException( 'Cannot create the lock file in ' . RS_DIR . ' (directory not writable?)' );
	}
	if ( ! flock( $h, LOCK_EX | LOCK_NB ) ) {
		fclose( $h );
		return null;
	}
	return $h;
}

function rs_unlock( $h ) {
	if ( $h ) {
		flock( $h, LOCK_UN );
		fclose( $h );
	}
}

/** Ensures $dir (relative) exists below RS_DIR without traversing symlinks that leave it. */
function rs_ensure_dir( $rel, array &$verified ) {
	if ( '' === $rel || '.' === $rel ) {
		return RS_DIR;
	}
	if ( isset( $verified[ $rel ] ) ) {
		return $verified[ $rel ];
	}
	$abs = RS_DIR . '/' . $rel;
	if ( ! is_dir( $abs ) && ! @mkdir( $abs, 0755, true ) && ! is_dir( $abs ) ) {
		throw new RuntimeException( 'Cannot create directory ' . $rel );
	}
	$real = realpath( $abs );
	$root = realpath( RS_DIR );
	if ( false === $real || false === $root || ( $real !== $root && 0 !== strpos( str_replace( '\\', '/', $real ) . '/', str_replace( '\\', '/', $root ) . '/' ) ) ) {
		throw new RuntimeException( 'Refusing to write outside the restore directory: ' . $rel );
	}
	if ( count( $verified ) > 5000 ) {
		$verified = array();
	}
	$verified[ $rel ] = $abs;
	return $abs;
}

function rs_db_connect( $host, $user, $pass, $name ) {
	if ( ! class_exists( 'mysqli' ) ) {
		throw new RuntimeException( 'The mysqli PHP extension is required.' );
	}
	mysqli_report( MYSQLI_REPORT_OFF );
	$port   = null;
	$socket = null;
	if ( preg_match( '/^(.+):(\d+)$/', $host, $m ) ) {
		$host = $m[1];
		$port = (int) $m[2];
	} elseif ( false !== strpos( $host, ':/' ) ) {
		list( $host, $socket ) = explode( ':', $host, 2 );
	}
	$db = mysqli_init();
	if ( ! @$db->real_connect( $host, $user, $pass, $name, $port, $socket ) ) {
		throw new RuntimeException( 'Database connection failed: ' . mysqli_connect_error() );
	}
	return $db;
}

function rs_manifest_file() {
	if ( ! RsPartsSource::flatName( RS_MANIFEST ) ) {
		throw new RuntimeException( 'Unsafe manifest filename.' );
	}
	if ( RS_MANIFEST_EXPLICIT || ! rs_store()->local() ) {
		return RS_DIR . '/' . RS_MANIFEST;
	}
	$script = basename( __FILE__ );
	$suffix = 'restore.php';
	if ( strlen( $script ) > strlen( $suffix ) && substr( $script, -strlen( $suffix ) ) === $suffix ) {
		$name = substr( $script, 0, -strlen( $suffix ) ) . 'manifest.json';
		if ( RsPartsSource::flatName( $name ) && is_file( RS_DIR . '/' . $name ) ) {
			return RS_DIR . '/' . $name;
		}
	}
	if ( is_file( RS_DIR . '/' . RS_MANIFEST ) ) {
		return RS_DIR . '/' . RS_MANIFEST;
	}
	$matches = glob( RS_DIR . '/*manifest.json' );
	$matches = false === $matches ? array() : $matches;
	if ( count( $matches ) > 1 ) {
		throw new RuntimeException( 'Multiple backup manifests: select one with RS_MANIFEST.' );
	}
	return $matches ? $matches[0] : RS_DIR . '/' . RS_MANIFEST;
}

/** Raw manifest bytes, null when the selected manifest does not exist. */
function rs_manifest_raw() {
	$name = basename( rs_manifest_file() );
	$store = rs_store();
	if ( ! $store->exists( $name ) ) {
		return null;
	}
	return $store->object( $name, 1048576 );
}

function rs_manifest() {
	$raw = rs_manifest_raw();
	if ( null === $raw ) {
		return array();
	}
	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ||
		( isset( $data['format'] ) && ! in_array( $data['format'], array( 1, 2 ), true ) ) ) {
		throw new RuntimeException( 'Invalid or unsupported backup manifest.' );
	}
	if ( isset( $data['object_prefix'] ) && ( ! is_string( $data['object_prefix'] ) ||
		( '' !== $data['object_prefix'] && ! RsPartsSource::flatName( $data['object_prefix'] ) ) ) ) {
		throw new RuntimeException( 'Unsafe backup object prefix.' );
	}
	return $data;
}

function rs_archive_source( $path ) {
	$name = basename( $path );
	if ( str_replace( '\\', '/', dirname( $path ) ) !== str_replace( '\\', '/', RS_DIR ) || ! in_array( $name, array( RS_FILES, RS_DB ), true ) ) {
		return new RsFileSource( $path );
	}
	$manifest = rs_manifest();
	$prefix = isset( $manifest['object_prefix'] ) ? $manifest['object_prefix'] : '';
	if ( 2 !== ( isset( $manifest['format'] ) ? $manifest['format'] : 1 ) ) {
		return rs_store()->local() ? new RsFileSource( $path ) : rs_store()->source( $prefix . $name );
	}
	$key = RS_FILES === $name ? 'files' : 'database';
	if ( ! isset( $manifest[ $key ]['parts_sha256'] ) ) {
		throw new RuntimeException( 'Format 2 manifest is missing the ' . $key . ' head checksum.' );
	}
	$source = new RsPartsSource( RS_DIR . '/' . $prefix . $name, $prefix, $manifest[ $key ]['parts_sha256'], rs_store() );
	if ( isset( $manifest[ $key ]['bytes'] ) && $manifest[ $key ]['bytes'] !== $source->size() ) {
		throw new RuntimeException( 'Archive size does not match the manifest.' );
	}
	return $source;
}

function rs_parts_sources() {
	$sources = array();
	$manifest = rs_manifest();
	if ( ! isset( $manifest['format'] ) || 2 !== $manifest['format'] ) {
		return $sources;
	}
	foreach ( array( RS_FILES, RS_DB ) as $name ) {
		$source = rs_archive_source( RS_DIR . '/' . $name );
		if ( $source instanceof RsPartsSource ) {
			$sources[] = $source;
		}
	}
	return $sources;
}

function rs_load_state() {
	if ( ! is_readable( RS_STATE ) ) {
		return null;
	}
	$raw = (string) file_get_contents( RS_STATE );
	$pos = strpos( $raw, "\n" );
	$data = json_decode( false === $pos ? '' : substr( $raw, $pos + 1 ), true );
	return is_array( $data ) ? $data : null;
}

function rs_save_state( array $state ) {
	$data = "<?php exit; ?>\n" . json_encode( $state, JSON_UNESCAPED_SLASHES );
	if ( false === file_put_contents( RS_STATE . '.tmp', $data, LOCK_EX ) || ! rename( RS_STATE . '.tmp', RS_STATE ) ) {
		throw new RuntimeException( 'Cannot write the state file in ' . RS_DIR . ' (directory not writable?)' );
	}
	@chmod( RS_STATE, 0600 );
}

function rs_log( array &$state, $message ) {
	$state['log'][] = gmdate( 'H:i:s' ) . ' ' . $message;
	if ( count( $state['log'] ) > 200 ) {
		$state['log'] = array_slice( $state['log'], -200 );
	}
}

function rs_requirements() {
	$checks   = array();
	$checks[] = array( 'PHP >= 7.4', version_compare( PHP_VERSION, '7.4', '>=' ), PHP_VERSION );
	$checks[] = array( 'zlib (inflate_init)', function_exists( 'inflate_init' ) && function_exists( 'inflate_get_read_len' ), '' );
	$checks[] = array( 'mysqli', class_exists( 'mysqli' ), '' );
	$checks[] = array( 'Directory writable', is_writable( RS_DIR ), RS_DIR );
	try {
		$manifest = rs_manifest();
		foreach ( array( RS_FILES, RS_DB ) as $name ) {
			try {
				$source = rs_archive_source( RS_DIR . '/' . $name );
				$checks[] = array( $name, $source->size() > 0, rs_size( $source->size() ) );
			} catch ( RuntimeException $e ) {
				$checks[] = array( $name, false, $e->getMessage() );
			}
		}
	} catch ( RuntimeException $e ) {
		$manifest = array();
		$checks[] = array( 'Backup manifest', false, $e->getMessage() );
	}
	if ( ! empty( $manifest['files']['raw_bytes'] ) && function_exists( 'disk_free_space' ) ) {
		$free     = @disk_free_space( RS_DIR );
		$need     = (int) $manifest['files']['raw_bytes'];
		$checks[] = array( 'Free disk space', false === $free || $free > $need * 1.05, ( false === $free ? '?' : rs_size( $free ) ) . ' free, ' . rs_size( $need ) . ' needed' );
	}
	return $checks;
}

function rs_size( $bytes ) {
	$u = array( 'B', 'KB', 'MB', 'GB', 'TB' );
	$i = 0;
	$b = (float) $bytes;
	while ( $b >= 1024 && $i < 4 ) {
		$b /= 1024;
		$i++;
	}
	return round( $b, 1 ) . ' ' . $u[ $i ];
}

/* ====================================================================== */
/* Restore phases                                                         */
/* ====================================================================== */

function rs_new_state( array $opts ) {
	$manifest = rs_manifest();
	$old_home = isset( $manifest['home_url'] ) ? rtrim( $manifest['home_url'], '/' ) : '';
	$old_site = isset( $manifest['site_url'] ) ? rtrim( $manifest['site_url'], '/' ) : $old_home;
	$new_home = rtrim( (string) $opts['url'], '/' );

	$pairs = array();
	if ( '' !== $old_home && '' !== $new_home && $old_home !== $new_home ) {
		$pairs[ $old_home ] = $new_home;
		if ( $old_site !== $old_home ) {
			$pairs[ $old_site ] = 0 === strpos( $old_site, $old_home ) ? $new_home . substr( $old_site, strlen( $old_home ) ) : $new_home;
		}
		$old_rel = preg_replace( '#^https?:#i', '', $old_home );
		$new_rel = preg_replace( '#^https?:#i', '', $new_home );
		if ( $old_rel !== $new_rel ) {
			$pairs[ $old_rel ] = $new_rel;
		}
	}
	$old_path = isset( $manifest['abspath'] ) ? rtrim( str_replace( '\\', '/', $manifest['abspath'] ), '/' ) : '';
	if ( '' !== $old_path && $old_path !== RS_DIR ) {
		$pairs[ $old_path ] = RS_DIR;
	}
	foreach ( $pairs as $from => $to ) {
		$json = str_replace( '/', '\\/', $from );
		if ( $json !== $from && ! isset( $pairs[ $json ] ) ) {
			$pairs[ $json ] = str_replace( '/', '\\/', $to );
		}
		$enc = rawurlencode( $from );
		if ( $enc !== $from && ! isset( $pairs[ $enc ] ) ) {
			$pairs[ $enc ] = rawurlencode( $to );
		}
	}

	$keep = array();
	foreach ( isset( $opts['keep'] ) ? (array) $opts['keep'] : array() as $path ) {
		$path = rs_safe_path( $path );
		if ( null !== $path ) {
			$keep[] = $path;
		}
	}
	$rollback = isset( $opts['mode'] ) && 'rollback' === $opts['mode'];

	return array(
		'version'  => RS_VERSION,
		'mode'     => $rollback ? 'rollback' : 'restore',
		'manifest_file' => basename( rs_manifest_file() ),
		'manifest_sha256' => $manifest ? hash( 'sha256', (string) rs_manifest_raw() ) : '',
		'phase'    => 'extract',
		'db'       => array(
			'host' => (string) $opts['db_host'],
			'name' => (string) $opts['db_name'],
			'user' => (string) $opts['db_user'],
			'pass' => (string) $opts['db_pass'],
		),
		'prefix'   => isset( $manifest['table_prefix'] ) ? (string) $manifest['table_prefix'] : '',
		'url'      => $new_home,
		'pairs'    => $pairs,
		'delete_archives' => ! empty( $opts['delete_archives'] ),
		'keep'     => $keep,
		'keep_config' => $rollback || ! empty( $opts['keep_config'] ),
		'preserve_options' => $rollback && isset( $opts['preserve_options'] ) ? (array) $opts['preserve_options'] : array(),
		'sweep'    => $rollback ? array(
			'exclude'        => array_values( array_filter( array_map( 'strval', isset( $opts['exclude'] ) ? (array) $opts['exclude'] : array() ), 'strlen' ) ),
			'exclude_tables' => array_values( array_map( 'strval', isset( $opts['exclude_tables'] ) ? (array) $opts['exclude_tables'] : array() ) ),
			'files_done'     => false,
			'files'          => 0,
			'tables'         => 0,
		) : null,
		'tar'      => array(
			'c'        => 0,
			'u'        => 0,
			'pos'      => 0,
			'entry'    => null,
			'pax'      => null,
			'longname' => null,
			'count'    => 0,
			'skipped'  => 0,
			'logged'   => 0,
		),
		'sql'      => array(
			'c'          => 0,
			'u'          => 0,
			'pos'        => 0,
			'statements' => 0,
			'charset'    => 'utf8mb4',
			'table'      => null,
			'dirty'      => false,
			'tables'     => array(),
		),
		'replace'  => array(
			'tables' => null,
			'i'      => 0,
			'key'    => null,
			'rows'   => 0,
		),
		'progress' => 0,
		'log'      => array(),
		'started'  => time(),
	);
}

function rs_step( array &$state, $deadline ) {
	rs_assert_manifest( $state );
	switch ( $state['phase'] ) {
		case 'extract':
			if ( rs_extract( $state, $deadline ) ) {
				rs_log( $state, sprintf( 'Files extracted: %d entries (%d skipped).', $state['tar']['count'], $state['tar']['skipped'] ) );
				$state['phase'] = 'import';
			}
			break;
		case 'import':
			if ( rs_import( $state, $deadline ) ) {
				rs_log( $state, sprintf( 'Database imported: %d statements.', $state['sql']['statements'] ) );
				$state['phase'] = $state['pairs'] ? 'replace' : ( empty( $state['sweep'] ) ? 'config' : 'sweep' );
			}
			break;
		case 'replace':
			if ( rs_replace( $state, $deadline ) ) {
				rs_log( $state, sprintf( 'URLs/paths updated in %d rows.', $state['replace']['rows'] ) );
				$state['phase'] = empty( $state['sweep'] ) ? 'config' : 'sweep';
			}
			break;
		case 'sweep':
			if ( rs_sweep( $state, $deadline ) ) {
				rs_log( $state, sprintf( 'Removed %d files/directories and %d tables not present in the backup.', $state['sweep']['files'], $state['sweep']['tables'] ) );
				$state['phase'] = 'config';
			}
			break;
		case 'config':
			rs_config( $state );
			$state['phase'] = 'done';
			rs_log( $state, 'Restore completed.' );
			break;
	}
}

function rs_assert_manifest( array $state ) {
	if ( ! empty( $state['manifest_sha256'] ) &&
		( basename( rs_manifest_file() ) !== $state['manifest_file'] ||
			! hash_equals( $state['manifest_sha256'], hash( 'sha256', (string) rs_manifest_raw() ) ) ) ) {
		throw new RuntimeException( 'Selected backup manifest changed; reset the restore before selecting another backup.' );
	}
}

function rs_parse_octal( $field ) {
	if ( '' !== $field && ( ord( $field[0] ) & 0x80 ) ) {
		$n = 0;
		for ( $i = 1, $l = strlen( $field ); $i < $l; $i++ ) {
			$n = $n * 256 + ord( $field[ $i ] );
		}
		return $n;
	}
	$field = trim( $field, " \0" );
	return '' === $field ? 0 : (int) octdec( $field );
}

function rs_extract( array &$state, $deadline ) {
	$t        = &$state['tar'];
	$archive  = RS_DIR . '/' . RS_FILES;
	$reader   = new RsGzReader( $archive, $t['c'], $t['u'], $t['pos'] );
	$verified = array();
	$protect  = array_merge( rs_protected_names(), isset( $state['keep'] ) ? (array) $state['keep'] : array() );
	$sources  = rs_store()->local() ? rs_parts_sources() : array();
	$total    = max( 1, $reader->compressedSize() );
	$done     = false;
	$handle   = null;
	$log      = null;
	if ( ! empty( $state['sweep'] ) ) {
		$log = @fopen( RS_EXTRACTED, 'ab' );
		if ( ! $log ) {
			throw new RuntimeException( 'Cannot write the list of restored paths.' );
		}
	}

	try {
		$first = true;
		while ( $first || microtime( true ) < $deadline ) {
			$first = false;
			if ( ! empty( $t['draining'] ) ) {
				if ( '' === $reader->read( 1048576 ) ) {
					$done = true;
					break;
				}
				$t['pos'] = $reader->position();
				list( $t['c'], $t['u'] ) = $reader->checkpoint( $t['pos'] );
				continue;
			}
			$e     = $t['entry'];
			if ( null !== $e ) {
				if ( $e['remaining'] > 0 ) {
					$data = $reader->read( (int) min( $e['remaining'], 1048576 ) );
					if ( '' === $data ) {
						throw new RuntimeException( 'Archive truncated inside ' . $e['path'] );
					}
					if ( 'file' === $e['type'] ) {
						if ( null === $handle ) {
							$abs    = RS_DIR . '/' . $e['path'];
							$mode   = $e['remaining'] === $e['size'] ? 'wb' : 'cb';
							$handle = @fopen( $abs, $mode );
							// A file owned by another user (e.g. left by a root CLI run) can still be replaced when its directory is writable.
							if ( ! $handle && 'wb' === $mode && is_file( $abs ) && ! is_link( $abs ) && @unlink( $abs ) ) {
								$handle = @fopen( $abs, 'wb' );
							}
							if ( ! $handle ) {
								throw new RuntimeException( 'Cannot write ' . $e['path'] );
							}
							fseek( $handle, $e['size'] - $e['remaining'] );
						}
						if ( fwrite( $handle, $data ) !== strlen( $data ) ) {
							throw new RuntimeException( 'Write failed for ' . $e['path'] . ' (disk full?)' );
						}
					}
					$t['entry']['remaining'] -= strlen( $data );
					continue;
				}
				if ( 'file' === $e['type'] ) {
					if ( null === $handle ) {
						$handle = @fopen( RS_DIR . '/' . $e['path'], 'cb' );
					}
					if ( $handle ) {
						ftruncate( $handle, $e['size'] );
						fclose( $handle );
						$handle = null;
					}
					@touch( RS_DIR . '/' . $e['path'], $e['mtime'] );
					@chmod( RS_DIR . '/' . $e['path'], ( $e['mode'] & 0777 ) | 0600 );
				}
				$reader->read( ( 512 - $e['size'] % 512 ) % 512 );
				$t['entry'] = null;
				$t['count']++;
				$t['pos'] = $reader->position();
				list( $t['c'], $t['u'] ) = $reader->checkpoint( $t['pos'] );
				continue;
			}

			$header = $reader->read( 512 );
			if ( strlen( $header ) < 512 ) {
				if ( '' === $header ) {
					$done = true;
					break;
				}
				throw new RuntimeException( 'Archive truncated (incomplete header).' );
			}
			if ( str_repeat( "\0", 512 ) === $header ) {
				$t['draining'] = true;
				$t['pos'] = $reader->position();
				list( $t['c'], $t['u'] ) = $reader->checkpoint( $t['pos'] );
				continue;
			}
			$sum = 0;
			for ( $i = 0; $i < 512; $i++ ) {
				$sum += ( $i >= 148 && $i < 156 ) ? 32 : ord( $header[ $i ] );
			}
			if ( $sum !== rs_parse_octal( substr( $header, 148, 8 ) ) ) {
				throw new RuntimeException( 'Corrupted tar header at offset ' . ( $reader->position() - 512 ) );
			}

			$name   = rtrim( substr( $header, 0, 100 ), "\0" );
			$mode   = rs_parse_octal( substr( $header, 100, 8 ) );
			$size   = rs_parse_octal( substr( $header, 124, 12 ) );
			$mtime  = rs_parse_octal( substr( $header, 136, 12 ) );
			$type   = $header[156];
			$link   = rtrim( substr( $header, 157, 100 ), "\0" );
			$prefix = 'ustar' === substr( $header, 257, 5 ) ? rtrim( substr( $header, 345, 155 ), "\0" ) : '';
			if ( '' !== $prefix ) {
				$name = $prefix . '/' . $name;
			}
			$pad = ( 512 - $size % 512 ) % 512;

			if ( 'x' === $type || 'g' === $type ) {
				$data = $reader->read( $size + $pad );
				if ( 'x' === $type ) {
					$pax = array();
					$p   = 0;
					$len = $size;
					while ( $p < $len && preg_match( '/^(\d+) /', substr( $data, $p, 24 ), $m ) ) {
						$rec = substr( $data, $p + strlen( $m[0] ), (int) $m[1] - strlen( $m[0] ) - 1 );
						$eq  = strpos( $rec, '=' );
						if ( false !== $eq ) {
							$pax[ substr( $rec, 0, $eq ) ] = substr( $rec, $eq + 1 );
						}
						$p += (int) $m[1];
					}
					$t['pax'] = $pax;
				}
				$t['pos'] = $reader->position();
				list( $t['c'], $t['u'] ) = $reader->checkpoint( $t['pos'] );
				continue;
			}
			if ( 'L' === $type || 'K' === $type ) {
				$data = $reader->read( $size + $pad );
				if ( 'L' === $type ) {
					$t['longname'] = rtrim( substr( $data, 0, $size ), "\0" );
				}
				continue;
			}

			if ( ! empty( $t['pax']['path'] ) ) {
				$name = $t['pax']['path'];
			} elseif ( null !== $t['longname'] ) {
				$name = $t['longname'];
			}
			if ( ! empty( $t['pax']['linkpath'] ) ) {
				$link = $t['pax']['linkpath'];
			}
			if ( isset( $t['pax']['size'] ) ) {
				$size = (int) $t['pax']['size'];
				$pad  = ( 512 - $size % 512 ) % 512;
			}
			$t['pax']      = null;
			$t['longname'] = null;

			$rel   = rs_safe_path( $name );
			$entry = array(
				'path'      => (string) $rel,
				'size'      => $size,
				'remaining' => $size,
				'mode'      => $mode,
				'mtime'     => $mtime,
				'type'      => 'skip',
			);

			if ( null === $rel ) {
				rs_log( $state, 'Skipped unsafe path: ' . $name );
				$t['skipped']++;
			} elseif ( rs_is_protected( $rel, $protect, $sources ) ) {
				rs_log( $state, 'Skipped (would overwrite restore files): ' . $rel );
				$t['skipped']++;
			} elseif ( '5' === $type ) {
				rs_ensure_dir( $rel, $verified );
				$entry['type'] = 'dir';
			} elseif ( '2' === $type ) {
				rs_ensure_dir( dirname( $rel ) === '.' ? '' : dirname( $rel ), $verified );
				$abs = RS_DIR . '/' . $rel;
				if ( is_link( $abs ) || is_file( $abs ) ) {
					@unlink( $abs );
				}
				if ( ! function_exists( 'symlink' ) || ! @symlink( $link, $abs ) ) {
					rs_log( $state, 'Could not create symlink ' . $rel . ' -> ' . $link );
				}
				$entry['type'] = 'link';
			} elseif ( '0' === $type || "\0" === $type || '7' === $type ) {
				$dir = dirname( $rel );
				rs_ensure_dir( '.' === $dir ? '' : $dir, $verified );
				$abs = RS_DIR . '/' . $rel;
				if ( is_link( $abs ) ) {
					@unlink( $abs );
				}
				$entry['type'] = 'file';
			} else {
				$t['skipped']++;
			}
			if ( $log && 'skip' !== $entry['type'] ) {
				if ( false === fwrite( $log, $rel . "\n" ) ) {
					throw new RuntimeException( 'Cannot write the list of restored paths.' );
				}
				$t['logged'] = ( isset( $t['logged'] ) ? $t['logged'] : 0 ) + 1;
			}
			$t['entry'] = $entry;
		}
	} finally {
		if ( $handle ) {
			fclose( $handle );
		}
		if ( $log ) {
			fclose( $log );
		}
	}

	if ( ! $done ) {
		$t['pos'] = $reader->position();
		list( $t['c'], $t['u'] ) = $reader->checkpoint( $t['pos'] );
	}
	$state['progress'] = $done ? 100 : (int) floor( 100 * $reader->compressedPosition() / $total );
	return $done;
}

function rs_db( array $state ) {
	$db = rs_db_connect( $state['db']['host'], $state['db']['user'], $state['db']['pass'], $state['db']['name'] );
	$db->set_charset( preg_replace( '/[^a-z0-9_]/i', '', $state['sql']['charset'] ) ?: 'utf8mb4' );
	return $db;
}

function rs_exec( $db, $sql ) {
	if ( ! $db->query( $sql ) ) {
		throw new RuntimeException( 'SQL error ' . $db->errno . ': ' . $db->error . ' in: ' . substr( $sql, 0, 200 ) );
	}
}

function rs_import( array &$state, $deadline ) {
	$s = &$state['sql'];

	// Statements commit before the checkpoint is saved: if the previous step did not
	// end cleanly, replay from the start of the current table (DROP + CREATE are idempotent).
	if ( ! empty( $s['dirty'] ) ) {
		$table    = is_array( $s['table'] ) ? $s['table'] : array( 0, 0, 0 );
		$s['c']   = $table[0];
		$s['u']   = $table[1];
		$s['pos'] = $table[2];
		rs_log( $state, 'Previous import step was interrupted: replaying the current table.' );
	}
	$s['dirty'] = true;
	rs_save_state( $state );

	$file   = RS_DIR . '/' . RS_DB;
	$reader = new RsGzReader( $file, $s['c'], $s['u'], $s['pos'] );
	$total  = max( 1, $reader->compressedSize() );
	$db     = rs_db( $state );
	rs_exec( $db, 'SET FOREIGN_KEY_CHECKS=0' );
	rs_exec( $db, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'" );
	rs_exec( $db, "SET time_zone='+00:00'" );

	$buf    = '';
	$start  = $s['pos'];
	$search = 0;
	$done   = false;
	$worked = false;

	while ( ! $worked || microtime( true ) < $deadline ) {
		$p = strpos( $buf, ";\n", $search );
		if ( false === $p ) {
			$chunk = $reader->read( 1048576 );
			if ( '' === $chunk ) {
				$rest = trim( preg_replace( '/^\s*--[^\n]*$/m', '', $buf ) );
				if ( '' !== $rest ) {
					rs_exec( $db, $rest );
					$s['statements']++;
				}
				$start += strlen( $buf );
				$buf    = '';
				$done   = true;
				break;
			}
			$search = max( 0, strlen( $buf ) - 1 );
			$buf   .= $chunk;
			continue;
		}
		$stmt     = substr( $buf, 0, $p + 1 );
		$stripped = preg_replace( '/\\\\./s', '', $stmt );
		if ( 0 !== substr_count( $stripped, "'" ) % 2 ) {
			$search = $p + 2;
			continue;
		}
		$sql = trim( preg_replace( '/\A(\s*--[^\n]*\n|\s+)+/', '', $stmt ) );
		if ( '' !== $sql && ';' !== $sql ) {
			if ( preg_match( '/^SET NAMES\s+([a-z0-9_]+)/i', $sql, $m ) ) {
				$s['charset'] = $m[1];
				$db->set_charset( $m[1] );
			} else {
				if ( 0 === stripos( $sql, 'DROP TABLE IF EXISTS ' ) ) {
					$s['table'] = array_merge( $reader->checkpoint( $start ), array( $start ) );
					if ( preg_match( '/^DROP TABLE IF EXISTS `((?:[^`]|``)+)`/i', $sql, $tm ) ) {
						$tname = str_replace( '``', '`', $tm[1] );
						if ( ! isset( $s['tables'] ) ) {
							$s['tables'] = array();
						}
						if ( ! in_array( $tname, $s['tables'], true ) ) {
							$s['tables'][] = $tname;
						}
					}
				}
				rs_exec( $db, $sql );
			}
			$s['statements']++;
		}
		$buf    = (string) substr( $buf, $p + 2 );
		$start += $p + 2;
		$search = 0;
		$worked = true;
	}

	if ( ! $done ) {
		$s['pos'] = $start;
		list( $s['c'], $s['u'] ) = $reader->checkpoint( $start );
	} else {
		rs_exec( $db, 'DROP TABLE IF EXISTS `' . RS_PROGRESS_TABLE . '`' );
		$prefix = $state['prefix'];
		if ( '' !== $prefix ) {
			$table = '`' . str_replace( '`', '``', $prefix . 'options' ) . '`';
			@$db->query( "DELETE FROM {$table} WHERE option_name IN ('wpssb_job','wpssb_lock','wpssb_cancel')" );
			// Rollback: the plugin keeps its current settings and the history of newer backups.
			foreach ( isset( $state['preserve_options'] ) ? (array) $state['preserve_options'] : array() as $name => $row ) {
				$name = $db->real_escape_string( (string) $name );
				rs_exec( $db, "DELETE FROM {$table} WHERE option_name = '{$name}'" );
				rs_exec( $db, "INSERT INTO {$table} (option_name, option_value, autoload) VALUES ('{$name}', '" . $db->real_escape_string( (string) $row[0] ) . "', '" . $db->real_escape_string( (string) $row[1] ) . "')" );
			}
		}
	}
	$db->close();
	$s['dirty']        = false;
	$state['progress'] = $done ? 100 : (int) floor( 100 * $reader->compressedPosition() / $total );
	return $done;
}

function rs_replace( array &$state, $deadline ) {
	$r     = &$state['replace'];
	$db    = rs_db( $state );
	$pairs = $state['pairs'];
	uksort(
		$pairs,
		function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		}
	);

	if ( null === $r['tables'] ) {
		$r['tables'] = array();
		$res         = $db->query( "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'" );
		while ( $res && ( $row = $res->fetch_row() ) ) {
			if ( RS_PROGRESS_TABLE !== $row[0] && ( '' === $state['prefix'] || 0 === strpos( $row[0], $state['prefix'] ) ) ) {
				$r['tables'][] = $row[0];
			}
		}
		rs_exec( $db, 'CREATE TABLE IF NOT EXISTS `' . RS_PROGRESS_TABLE . '` (id TINYINT NOT NULL PRIMARY KEY, i INT NOT NULL, k LONGTEXT NULL, n INT NOT NULL) ENGINE=InnoDB' );
		rs_exec( $db, 'INSERT IGNORE INTO `' . RS_PROGRESS_TABLE . '` VALUES (1, 0, NULL, 0)' );
	}

	// The progress row is updated in the same transaction as the rows, so a batch is never
	// applied twice (that would corrupt URLs when the new URL contains the old one).
	$res = $db->query( 'SELECT i, k, n FROM `' . RS_PROGRESS_TABLE . '` WHERE id = 1' );
	$row = $res ? $res->fetch_assoc() : null;
	if ( ! $row ) {
		throw new RuntimeException( 'Search/replace progress table is missing: ' . $db->error );
	}
	$r['i']    = (int) $row['i'];
	$r['key']  = null === $row['k'] ? null : json_decode( $row['k'], true );
	$r['rows'] = (int) $row['n'];
	$commit    = function () use ( $db, &$r ) {
		$k = null === $r['key'] ? 'NULL' : "'" . $db->real_escape_string( json_encode( $r['key'] ) ) . "'";
		rs_exec( $db, 'UPDATE `' . RS_PROGRESS_TABLE . '` SET i = ' . (int) $r['i'] . ', k = ' . $k . ', n = ' . (int) $r['rows'] . ' WHERE id = 1' );
		if ( ! $db->commit() ) {
			throw new RuntimeException( 'Commit failed: ' . $db->error );
		}
	};

	$first = true;
	while ( $r['i'] < count( $r['tables'] ) && ( $first || microtime( true ) < $deadline ) ) {
		$first = false;
		$db->begin_transaction();
		$table = $r['tables'][ $r['i'] ];
		$qt    = '`' . str_replace( '`', '``', $table ) . '`';
		$cols  = array();
		$pk    = array();
		$res   = $db->query( 'SHOW COLUMNS FROM ' . $qt );
		while ( $res && ( $c = $res->fetch_assoc() ) ) {
			if ( 'PRI' === $c['Key'] ) {
				$pk[] = $c['Field'];
			}
			$is_guid = 'guid' === $c['Field'] && substr( $table, -5 ) === 'posts';
			if ( ! $is_guid && preg_match( '/^(var)?char|text|json/i', $c['Type'] ) ) {
				$cols[] = $c['Field'];
			}
		}
		if ( ! $pk || ! $cols ) {
			if ( ! $pk && $cols ) {
				rs_log( $state, 'Search/replace skipped for ' . $table . ' (no primary key).' );
			}
			$r['i']++;
			$r['key'] = null;
			$commit();
			continue;
		}

		$q    = function ( $n ) {
			return '`' . str_replace( '`', '``', $n ) . '`';
		};
		$like = array();
		foreach ( $cols as $col ) {
			foreach ( array_keys( $pairs ) as $from ) {
				$like[] = $q( $col ) . " LIKE '%" . $db->real_escape_string( str_replace( array( '\\', '_', '%' ), array( '\\\\', '\\_', '\\%' ), $from ) ) . "%'";
			}
		}
		$order = implode( ',', array_map( $q, $pk ) );
		$where = '(' . implode( ' OR ', $like ) . ')';
		if ( null !== $r['key'] ) {
			$vals  = array_map(
				function ( $v ) use ( $db ) {
					return "'" . $db->real_escape_string( $v ) . "'";
				},
				$r['key']
			);
			$where = '(' . $order . ') > (' . implode( ',', $vals ) . ') AND ' . $where;
		}
		$select = array_unique( array_merge( $pk, $cols ) );
		$res    = $db->query( 'SELECT ' . implode( ',', array_map( $q, $select ) ) . ' FROM ' . $qt . ' WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT 200' );
		if ( ! $res ) {
			throw new RuntimeException( 'Search/replace query failed on ' . $table . ': ' . $db->error );
		}
		$n = 0;
		while ( $row = $res->fetch_assoc() ) {
			$n++;
			$set = array();
			foreach ( $cols as $col ) {
				if ( null === $row[ $col ] ) {
					continue;
				}
				$new = RsSerialized::replaceValue( $row[ $col ], $pairs );
				if ( $new !== $row[ $col ] ) {
					$set[] = $q( $col ) . "='" . $db->real_escape_string( $new ) . "'";
				}
			}
			$key = array();
			foreach ( $pk as $col ) {
				$key[] = $row[ $col ];
			}
			if ( $set ) {
				$cond = array();
				foreach ( $pk as $col ) {
					$cond[] = $q( $col ) . "='" . $db->real_escape_string( $row[ $col ] ) . "'";
				}
				rs_exec( $db, 'UPDATE ' . $qt . ' SET ' . implode( ',', $set ) . ' WHERE ' . implode( ' AND ', $cond ) );
				$r['rows']++;
			}
			$r['key'] = $key;
		}
		if ( $n < 200 ) {
			$r['i']++;
			$r['key'] = null;
		}
		$commit();
	}
	$finished = $r['i'] >= count( $r['tables'] );
	$db->close();
	$count             = max( 1, count( $r['tables'] ) );
	$state['progress'] = (int) floor( 100 * $r['i'] / $count );
	return $finished;
}

/** Rollback only: removes files and prefixed tables that are not part of the restored backup. */
function rs_sweep( array &$state, $deadline ) {
	$w = &$state['sweep'];
	if ( empty( $w['files_done'] ) ) {
		$set = array();
		$fh = @fopen( RS_EXTRACTED, 'rb' );
		if ( ! $fh ) {
			throw new RuntimeException( 'The list of restored paths is missing: refusing to remove files.' );
		}
		$lines = 0;
		while ( false !== ( $line = fgets( $fh ) ) ) {
			$rel = rtrim( $line, "\r\n" );
			if ( '' !== $rel ) {
				$set[ $rel ] = true;
				$lines++;
			}
		}
		fclose( $fh );
		if ( ! $set || $lines < (int) $state['tar']['logged'] ) {
			throw new RuntimeException( 'The list of restored paths is incomplete: refusing to remove files.' );
		}
		foreach ( array_keys( $set ) as $rel ) {
			while ( false !== ( $p = strrpos( $rel, '/' ) ) ) {
				$rel = substr( $rel, 0, $p );
				$set[ $rel ] = true;
			}
		}
		$skip = array_merge( rs_protected_names(), (array) $state['keep'] );
		@set_time_limit( 600 );
		$w['files'] += rs_sweep_dir( '', $set, $skip, $w['exclude'] );
		$w['files_done'] = true;
		rs_save_state( $state );
	}

	$dumped = isset( $state['sql']['tables'] ) ? (array) $state['sql']['tables'] : array();
	$prefix = (string) $state['prefix'];
	if ( '' === $prefix || ! in_array( $prefix . 'options', $dumped, true ) ) {
		rs_log( $state, 'WARNING: table list of the backup unavailable; extra tables were not removed.' );
		return true;
	}
	$db  = rs_db( $state );
	$res = $db->query( "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'" );
	$drop = array();
	while ( $res && ( $row = $res->fetch_row() ) ) {
		$name = (string) $row[0];
		if ( 0 === strpos( $name, $prefix ) && RS_PROGRESS_TABLE !== $name && ! in_array( $name, $dumped, true ) && ! in_array( $name, $w['exclude_tables'], true ) ) {
			$drop[] = $name;
		}
	}
	foreach ( $drop as $name ) {
		rs_exec( $db, 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', $name ) . '`' );
		rs_log( $state, 'Dropped table not present in the backup: ' . $name );
		$w['tables']++;
	}
	$db->close();
	$state['progress'] = 100;
	return true;
}

function rs_sweep_excluded( $rel, array $patterns ) {
	foreach ( $patterns as $pattern ) {
		$pattern = trim( str_replace( '\\', '/', (string) $pattern ), '/' );
		if ( '' === $pattern ) {
			continue;
		}
		if ( $rel === $pattern || 0 === strpos( $rel, $pattern . '/' ) ) {
			return true;
		}
		if ( false !== strpbrk( $pattern, '*?[' ) && fnmatch( $pattern, $rel ) ) {
			return true;
		}
	}
	return false;
}

/** @return int Number of removed entries below $dir (relative to RS_DIR). */
function rs_sweep_dir( $dir, array $set, array $skip, array $exclude ) {
	$abs_dir = '' === $dir ? RS_DIR : RS_DIR . '/' . $dir;
	$names = @scandir( $abs_dir );
	if ( false === $names ) {
		return 0;
	}
	$removed = 0;
	foreach ( $names as $name ) {
		if ( '.' === $name || '..' === $name ) {
			continue;
		}
		$rel = '' === $dir ? $name : $dir . '/' . $name;
		if ( ( '' === $dir && '.maintenance' === $name ) || rs_is_protected( $rel, $skip, array() ) || rs_sweep_excluded( $rel, $exclude ) ) {
			continue;
		}
		$abs = $abs_dir . '/' . $name;
		if ( is_link( $abs ) || ! is_dir( $abs ) ) {
			if ( ! isset( $set[ $rel ] ) && @unlink( $abs ) ) {
				$removed++;
			}
			continue;
		}
		$removed += rs_sweep_dir( $rel, $set, $skip, $exclude );
		if ( ! isset( $set[ $rel ] ) && @rmdir( $abs ) ) {
			$removed++;
		}
	}
	return $removed;
}

function rs_config( array &$state ) {
	// The search/replace progress table is kept until here so that a replayed final
	// replace step still finds its "finished" marker.
	$db = rs_db( $state );
	rs_exec( $db, 'DROP TABLE IF EXISTS `' . RS_PROGRESS_TABLE . '`' );
	$db->close();

	if ( ! empty( $state['keep_config'] ) ) {
		rs_log( $state, 'Current wp-config.php kept unchanged.' );
		return;
	}
	$file = RS_DIR . '/wp-config.php';
	if ( ! is_file( $file ) ) {
		rs_log( $state, 'WARNING: wp-config.php not found in the archive; create it manually.' );
		return;
	}
	// Always start from the archived original, so that a replayed step stays idempotent.
	$orig = RS_DIR . '/' . RS_CONFIG_ORIG;
	if ( ! is_file( $orig ) && ! copy( $file, $orig ) ) {
		throw new RuntimeException( 'Cannot back up wp-config.php' );
	}
	$config = (string) file_get_contents( $orig );
	$map    = array(
		'DB_NAME'     => $state['db']['name'],
		'DB_USER'     => $state['db']['user'],
		'DB_PASSWORD' => $state['db']['pass'],
		'DB_HOST'     => $state['db']['host'],
	);
	foreach ( $map as $const => $value ) {
		$pattern = '/define\(\s*([\'"])' . $const . '\1\s*,\s*(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*\)/';
		$repl    = "define( '" . $const . "', " . var_export( (string) $value, true ) . ' )';
		$count   = 0;
		$config  = preg_replace_callback(
			$pattern,
			function () use ( $repl ) {
				return $repl;
			},
			$config,
			1,
			$count
		);
		if ( 0 === $count ) {
			rs_log( $state, 'WARNING: ' . $const . ' not found in wp-config.php.' );
		}
	}
	if ( $state['pairs'] ) {
		$config = strtr( $config, $state['pairs'] );
	}
	if ( false === file_put_contents( $file, $config ) ) {
		throw new RuntimeException( 'Cannot update wp-config.php' );
	}
	rs_log( $state, 'wp-config.php updated with the new database credentials.' );
}

function rs_cleanup( array $state, $self_delete ) {
	rs_assert_manifest( $state );
	if ( ! empty( $state['delete_archives'] ) && rs_store()->local() ) {
		$sources = rs_parts_sources();
		if ( $sources ) {
			foreach ( $sources as $source ) {
				foreach ( $source->objectNames() as $name ) {
					@unlink( RS_DIR . '/' . $name );
				}
			}
		} else {
			@unlink( RS_DIR . '/' . RS_FILES );
			@unlink( RS_DIR . '/' . RS_DB );
		}
		@unlink( rs_manifest_file() );
	}
	@unlink( RS_STATE );
	@unlink( RS_STATE . '.tmp' );
	@unlink( RS_LOCK );
	@unlink( RS_EXTRACTED );
	@unlink( RS_DIR . '/' . RS_CONFIG_ORIG );
	if ( $self_delete ) {
		@unlink( __FILE__ );
	}
	if ( function_exists( 'opcache_reset' ) ) {
		@opcache_reset();
	}
}

/* ====================================================================== */
/* CLI                                                                    */
/* ====================================================================== */

if ( defined( 'WPSSB_RESTORE_LIBRARY_ONLY' ) ) {
	return;
}

if ( RS_CLI ) {
	$opts = getopt( '', array( 'db-host:', 'db-name:', 'db-user:', 'db-pass::', 'url::', 'delete-archives', 'self-delete', 'step::' ) );
	try {
		$rs_lock = rs_lock();
	} catch ( Exception $e ) {
		fwrite( STDERR, $e->getMessage() . "\n" );
		exit( 1 );
	}
	if ( ! $rs_lock ) {
		fwrite( STDERR, "Another restore process is running in this directory.\n" );
		exit( 1 );
	}
	$state = rs_load_state();
	if ( ! $state ) {
		foreach ( array( 'db-host', 'db-name', 'db-user' ) as $req ) {
			if ( empty( $opts[ $req ] ) ) {
				fwrite( STDERR, "Usage: php restore.php --db-host=HOST --db-name=NAME --db-user=USER --db-pass=PASS [--url=https://new-site] [--delete-archives] [--self-delete]\n" );
				exit( 2 );
			}
		}
		foreach ( rs_requirements() as $check ) {
			echo ( $check[1] ? '[ok]   ' : '[FAIL] ' ) . $check[0] . ( '' !== $check[2] ? ' (' . $check[2] . ')' : '' ) . "\n";
			if ( ! $check[1] ) {
				exit( 1 );
			}
		}
		$manifest = rs_manifest();
		try {
			rs_db_connect( $opts['db-host'], $opts['db-user'], isset( $opts['db-pass'] ) ? (string) $opts['db-pass'] : '', $opts['db-name'] )->close();
		} catch ( Exception $e ) {
			fwrite( STDERR, $e->getMessage() . "\n" );
			exit( 1 );
		}
		$state = rs_new_state(
			array(
				'db_host'         => $opts['db-host'],
				'db_name'         => $opts['db-name'],
				'db_user'         => $opts['db-user'],
				'db_pass'         => isset( $opts['db-pass'] ) ? (string) $opts['db-pass'] : '',
				'url'             => ! empty( $opts['url'] ) ? $opts['url'] : ( isset( $manifest['home_url'] ) ? $manifest['home_url'] : '' ),
				'delete_archives' => isset( $opts['delete-archives'] ),
			)
		);
		rs_save_state( $state );
	} else {
		echo "Resuming previous restore (phase {$state['phase']}).\n";
	}

	$step_seconds = isset( $opts['step'] ) ? max( 1, (int) $opts['step'] ) : 60;
	$printed      = 0;
	try {
		while ( 'done' !== $state['phase'] ) {
			$phase = $state['phase'];
			rs_step( $state, microtime( true ) + $step_seconds );
			rs_save_state( $state );
			echo sprintf( "%-8s %3d%%\n", $phase, $state['progress'] );
			for ( $i = $printed; $i < count( $state['log'] ); $i++ ) {
				echo '  ' . $state['log'][ $i ] . "\n";
			}
			$printed = count( $state['log'] );
		}
	} catch ( Exception $e ) {
		fwrite( STDERR, 'ERROR: ' . $e->getMessage() . "\nRun the same command again to resume.\n" );
		exit( 1 );
	}
	rs_unlock( $rs_lock );
	rs_cleanup( $state, isset( $opts['self-delete'] ) );
	echo "Restore completed. Site URL: {$state['url']}\n";
	exit( 0 );
}

/* ====================================================================== */
/* Web UI                                                                 */
/* ====================================================================== */

header( 'X-Robots-Tag: noindex, nofollow' );
header( 'Cache-Control: no-store' );
header( 'X-Frame-Options: DENY' );

function rs_h( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}

function rs_page( $title, $body ) {
	echo '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . rs_h( $title ) . '</title>';
	echo '<style>
		*{box-sizing:border-box}body{font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f1f3ee;color:#28332d;margin:0;padding:32px 16px}
		main{max-width:820px;margin:0 auto;background:#fff;border:1px solid #dce3da;border-top:4px solid #c4d72f;border-radius:14px;box-shadow:0 8px 30px #1c2a1808;overflow:hidden}
		header{display:flex;justify-content:space-between;align-items:center;gap:24px;padding:24px 32px;border-bottom:1px solid #dce3da}
		.eyebrow{margin:0 0 4px;font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#59665e}header strong{font-size:17px}
		.brand{display:block;flex-shrink:0}.brand img{display:block;width:130px;height:48px}
		.content{padding:24px 32px}h1{font-size:26px;line-height:1.3;margin:0 0 24px}
		a{color:#375526}a:hover{color:#1e3611}label{display:block;margin:16px 0 6px;font-weight:600}
		input[type=text],input[type=password],input[type=url]{width:100%;padding:10px 12px;border:1px solid #9da99c;border-radius:6px;font:inherit;color:inherit;background:#fff}
		input[type=checkbox]{accent-color:#354c29}button{background:#354c29;color:#fff;border:1px solid #354c29;border-radius:6px;padding:10px 20px;margin-top:16px;cursor:pointer;font:inherit;font-weight:600}button:hover{background:#263d1c}.secondary{background:#fff;color:#354c29}.secondary:hover{background:#eef3e7}
		:is(a,button,input,summary,[tabindex]):focus-visible{outline:2px solid #354c29;outline-offset:3px}
		.ok{color:#2e571d}.fail{color:#a02323}.err,.note{padding:14px 16px;border-radius:6px;overflow-wrap:anywhere}.err{background:#fcecec;border-left:4px solid #a02323}.note{background:#f1f5e8;border-left:4px solid #859a20}
		pre{background:#f4f6f1;border:1px solid #dce3da;border-radius:8px;padding:14px;max-height:280px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;font-size:12px}
		progress{display:block;width:100%;height:14px;border:0;border-radius:8px;overflow:hidden;accent-color:#354c29;background:#edf0e8}
		progress::-webkit-progress-bar{background:#edf0e8}progress::-webkit-progress-value{background:#354c29}progress::-moz-progress-bar{background:#354c29}
		table{border-collapse:collapse;width:100%;margin:16px 0}td{padding:10px 8px;border-bottom:1px solid #dce3da;overflow-wrap:anywhere}td:first-child{width:32px}code{overflow-wrap:anywhere}
		footer{padding:18px 32px;background:#f8faf5;border-top:1px solid #dce3da;color:#59665e;font-size:12px}footer a{text-decoration:none}
		@media(max-width:600px){body{padding:16px 10px}header,.content{padding:20px}header{gap:12px}header strong{font-size:14px}h1{font-size:22px}footer{padding:16px 20px}td{font-size:13px;padding:8px 4px}}
		</style>';
	echo '</head><body><main><header><div><p class="eyebrow">Standalone restore</p><strong>WP SFTP Stream Backup</strong></div><a class="brand" href="https://radici.biz" aria-label="Radici Soluzioni Informatiche"><img width="130" height="48" alt="" src="data:image/png;base64,';
	echo 'iVBORw0KGgoAAAANSUhEUgAAAIIAAAAwCAYAAADdAaKDAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAA/JpVFh0WE1MOmNvbS5hZG9iZS54bXAAAAAAADw/eHBhY2tldCBiZWdpbj0i77u/IiBpZD0iVzVNME1wQ2VoaUh6cmVTek5UY3prYzlkIj8+IDx4OnhtcG1ldGEgeG1sbnM6eD0iYWRvYmU6bnM6bWV0YS8iIHg6eG1wdGs9IkFkb2JlIFhNUCBDb3JlIDUuMy1jMDExIDY2LjE0NTY2MSwgMjAxMi8wMi8wNi0xNDo1NjoyNyAgICAgICAgIj4gPHJkZjpSREYgeG1sbnM6cmRmPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5LzAyLzIyLXJkZi1zeW50YXgtbnMjIj4gPHJkZjpEZXNjcmlwdGlvbiByZGY6YWJvdXQ9IiIgeG1sbnM6eG1wTU09Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC9tbS8iIHhtbG5zOnN0UmVmPSJodHRwOi8vbnMuYWRvYmUuY29tL3hhcC8xLjAvc1R5cGUvUmVzb3VyY2VSZWYjIiB4bWxuczp4bXA9Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC8iIHhtbG5zOmRjPSJodHRwOi8vcHVybC5vcmcvZGMvZWxlbWVudHMvMS4xLyIgeG1wTU06T3JpZ2luYWxEb2N1bWVudElEPSJ1dWlkOjVlZWEwNzdlLTM4Y2EtNGE1MC04Y2EzLTFhMWVlNDVkYzZkMyIgeG1wTU06RG9jdW1lbnRJRD0ieG1wLmRpZDoxNzgzMUJCRjQyREIxMUU2QTlBRUUzQUY0MjEzMDZDOSIgeG1wTU06SW5zdGFuY2VJRD0ieG1wLmlpZDoxNzgzMUJCRTQyREIxMUU2QTlBRUUzQUY0MjEzMDZDOSIgeG1wOkNyZWF0b3JUb29sPSJBZG9iZSBJbGx1c3RyYXRvciBDUzUuMSI+IDx4bXBNTTpEZXJpdmVkRnJvbSBzdFJlZjppbnN0YW5jZUlEPSJ1dWlkOjNhMzc3NzY1LWU2MTUtNDJlZC1hYTE1LTA5ZmQ0NjljYWZmNyIgc3RSZWY6ZG9jdW1lbnRJRD0ieG1wLmRpZDoyMzlFM0JCMTJE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';
	echo '"></a></header><div class="content"><h1>' . rs_h( $title ) . '</h1>' . $body . '</div><footer>Coded by <a href="https://radici.biz"><strong>Radici Soluzioni Informatiche</strong></a></footer></main></body></html>';
	exit;
}

if ( strlen( (string) WPSSB_RESTORE_PASSWORD ) < 8 ) {
	rs_page(
		'Restore — set a password first',
		'<p class="err">For security this script is locked. Edit <code>' . rs_h( basename( __FILE__ ) ) . '</code> and set <code>WPSSB_RESTORE_PASSWORD</code> (at least 8 characters) near the top of the file, then reload this page.</p>' .
		'<p>Alternatively run it from a shell: <code>php ' . rs_h( basename( __FILE__ ) ) . ' --db-host=… --db-name=… --db-user=… --db-pass=… --url=…</code></p>'
	);
}

if ( PHP_SESSION_ACTIVE !== session_status() ) {
	session_name( 'wpssb_restore' );
	session_start(
		array(
			'cookie_httponly' => true,
			'cookie_samesite' => 'Strict',
			'cookie_secure'   => ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'],
		)
	);
}
if ( empty( $_SESSION['rs_csrf'] ) ) {
	$_SESSION['rs_csrf'] = bin2hex( random_bytes( 16 ) );
}
$rs_csrf   = $_SESSION['rs_csrf'];
$rs_action = isset( $_POST['action'] ) ? (string) $_POST['action'] : '';
$rs_self   = rs_h( basename( __FILE__ ) );

if ( 'login' === $rs_action ) {
	if ( hash_equals( (string) WPSSB_RESTORE_PASSWORD, (string) ( isset( $_POST['password'] ) ? $_POST['password'] : '' ) ) ) {
		session_regenerate_id( true );
		$_SESSION['rs_auth'] = true;
		header( 'Location: ' . basename( __FILE__ ) );
		exit;
	}
	sleep( 2 );
	$rs_login_error = true;
}

if ( empty( $_SESSION['rs_auth'] ) ) {
	rs_page(
		'Restore — login',
		( ! empty( $rs_login_error ) ? '<p class="err">Wrong password.</p>' : '' ) .
		'<form method="post"><input type="hidden" name="action" value="login"><label for="p">Restore password</label><input type="password" id="p" name="password" autofocus><button type="submit">Log in</button></form>'
	);
}

if ( '' !== $rs_action && ( ! isset( $_POST['csrf'] ) || ! hash_equals( $rs_csrf, (string) $_POST['csrf'] ) ) ) {
	http_response_code( 403 );
	rs_page( 'Restore', '<p class="err">Invalid request token, reload the page.</p>' );
}

if ( 'step' === $rs_action ) {
	header( 'Content-Type: application/json' );
	session_write_close();
	ignore_user_abort( true );
	@set_time_limit( 120 );
	$max    = (int) ini_get( 'max_execution_time' );
	$budget = $max > 0 ? max( 3, min( 20, $max * 0.5 ) ) : 20;
	try {
		$rs_lock = rs_lock();
	} catch ( Exception $e ) {
		echo json_encode( array( 'error' => $e->getMessage() ) );
		exit;
	}
	if ( ! $rs_lock ) {
		echo json_encode( array( 'busy' => true ) );
		exit;
	}
	$state = rs_load_state();
	if ( ! $state ) {
		rs_unlock( $rs_lock );
		echo json_encode( array( 'error' => 'No restore in progress.' ) );
		exit;
	}
	try {
		if ( 'done' !== $state['phase'] ) {
			rs_step( $state, microtime( true ) + $budget );
			rs_save_state( $state );
		}
		$out = array(
			'phase'    => $state['phase'],
			'progress' => $state['progress'],
			'log'      => $state['log'],
		);
		rs_unlock( $rs_lock );
		if ( 'done' === $state['phase'] ) {
			$out['url'] = $state['url'];
			rs_cleanup( $state, true );
		}
		echo json_encode( $out );
	} catch ( Exception $e ) {
		rs_unlock( $rs_lock );
		echo json_encode(
			array(
				'error' => $e->getMessage(),
				'phase' => $state['phase'],
				'log'   => $state['log'],
			)
		);
	}
	exit;
}

if ( 'reset' === $rs_action ) {
	$rs_lock = rs_lock();
	if ( ! $rs_lock ) {
		rs_page( 'Restore', '<p class="err">A restore step is running, try again in a few seconds.</p>' );
	}
	@unlink( RS_STATE );
	rs_unlock( $rs_lock );
	header( 'Location: ' . basename( __FILE__ ) );
	exit;
}

$rs_error = '';
if ( 'begin' === $rs_action && ! rs_load_state() ) {
	$opts = array(
		'db_host'         => trim( (string) $_POST['db_host'] ),
		'db_name'         => trim( (string) $_POST['db_name'] ),
		'db_user'         => trim( (string) $_POST['db_user'] ),
		'db_pass'         => (string) $_POST['db_pass'],
		'url'             => trim( (string) $_POST['url'] ),
		'delete_archives' => ! empty( $_POST['delete_archives'] ),
	);
	try {
		if ( ! filter_var( $opts['url'], FILTER_VALIDATE_URL ) ) {
			throw new RuntimeException( 'Invalid site URL.' );
		}
		foreach ( rs_requirements() as $check ) {
			if ( ! $check[1] ) {
				throw new RuntimeException( $check[0] . ': ' . $check[2] );
			}
		}
		rs_db_connect( $opts['db_host'], $opts['db_user'], $opts['db_pass'], $opts['db_name'] )->close();
		rs_save_state( rs_new_state( $opts ) );
		header( 'Location: ' . basename( __FILE__ ) );
		exit;
	} catch ( Exception $e ) {
		$rs_error = $e->getMessage();
	}
}

$state = rs_load_state();
if ( $state ) {
	$csrf = rs_h( $rs_csrf );
	rs_page(
		'Restoring…',
		'<p aria-live="polite">Phase: <strong id="phase">' . rs_h( $state['phase'] ) . '</strong></p><progress id="bar" aria-label="Restore progress" max="100" value="' . (int) $state['progress'] . '"></progress>' .
		'<div id="msg" aria-live="polite"></div><pre id="log" aria-label="Restore log"></pre>' .
		'<form method="post" onsubmit="return confirm(\'Discard the restore progress and start over?\')"><input type="hidden" name="action" value="reset"><input type="hidden" name="csrf" value="' . $csrf . '"><button type="submit" class="secondary">Start over</button></form>' .
		'<script>(function(){var csrf=' . json_encode( $rs_csrf ) . ',stop=false;function step(){var fd=new FormData();fd.append("action","step");fd.append("csrf",csrf);' .
		'fetch(' . json_encode( basename( __FILE__ ) ) . ',{method:"POST",body:fd,credentials:"same-origin"}).then(function(r){return r.json();}).then(function(d){' .
		'if(d.busy){setTimeout(step,2000);return;}' .
		'if(d.log){var l=document.getElementById("log");l.textContent=d.log.join("\\n");l.scrollTop=l.scrollHeight;}' .
		'if(d.error){document.getElementById("msg").innerHTML="";var p=document.createElement("p");p.className="err";p.textContent="Error: "+d.error;var b=document.createElement("button");b.textContent="Retry";b.onclick=function(){p.remove();b.remove();step();};document.getElementById("msg").append(p,b);return;}' .
		'document.getElementById("phase").textContent=d.phase;document.getElementById("bar").value=d.progress;' .
		'if(d.phase==="done"){var m=document.getElementById("msg");m.innerHTML="";var p=document.createElement("p");p.className="note";p.textContent="Restore completed. restore.php and its state file have been deleted. ";var a=document.createElement("a");a.href=d.url+"/wp-login.php";a.textContent="Log in to the restored site";p.append(a);m.append(p);document.getElementById("bar").value=100;return;}' .
		'setTimeout(step,200);}).catch(function(e){var p=document.createElement("p");p.className="err";p.textContent="Request failed ("+e.message+"), retrying in 5 s…";document.getElementById("msg").replaceChildren(p);setTimeout(step,5000);});}step();})();</script>'
	);
}

try {
	$manifest = rs_manifest();
} catch ( RuntimeException $e ) {
	$manifest = array();
	$rs_error = $e->getMessage();
}
$checks   = rs_requirements();
$all_ok   = true;
$rows     = '';
foreach ( $checks as $c ) {
	$all_ok = $all_ok && $c[1];
	$rows  .= '<tr><td class="' . ( $c[1] ? 'ok' : 'fail' ) . '">' . ( $c[1] ? '✔' : '✘' ) . '</td><td>' . rs_h( $c[0] ) . '</td><td>' . rs_h( $c[2] ) . '</td></tr>';
}
$scheme  = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) ? 'https' : 'http';
$host    = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'localhost';
$path    = rtrim( str_replace( '\\', '/', dirname( isset( $_SERVER['SCRIPT_NAME'] ) ? $_SERVER['SCRIPT_NAME'] : '/' ) ), '/' );
$def_url = $scheme . '://' . $host . $path;
$info    = '';
if ( $manifest ) {
	$info = '<p class="note">Backup <code>' . rs_h( isset( $manifest['id'] ) ? $manifest['id'] : '' ) . '</code> of <strong>' . rs_h( isset( $manifest['home_url'] ) ? $manifest['home_url'] : '' ) . '</strong>, created ' . rs_h( isset( $manifest['finished_utc'] ) ? $manifest['finished_utc'] : '' ) . ' (WordPress ' . rs_h( isset( $manifest['wp_version'] ) ? $manifest['wp_version'] : '?' ) . ', table prefix <code>' . rs_h( isset( $manifest['table_prefix'] ) ? $manifest['table_prefix'] : '?' ) . '</code>).</p>';
} else {
	$info = '<p class="note">manifest.json not found: URLs will not be rewritten.</p>';
}
$p = function ( $k, $d = '' ) {
	return rs_h( isset( $_POST[ $k ] ) ? $_POST[ $k ] : $d );
};

rs_page(
	'Restore WordPress backup',
	$info .
	'<table>' . $rows . '</table>' .
	( $rs_error ? '<p class="err">' . rs_h( $rs_error ) . '</p>' : '' ) .
	( $all_ok ?
		'<form method="post"><input type="hidden" name="action" value="begin"><input type="hidden" name="csrf" value="' . rs_h( $rs_csrf ) . '">' .
		'<label for="db_host">Database host</label><input type="text" id="db_host" name="db_host" value="' . $p( 'db_host', 'localhost' ) . '">' .
		'<label for="db_name">Database name</label><input type="text" id="db_name" name="db_name" value="' . $p( 'db_name' ) . '" required>' .
		'<label for="db_user">Database user</label><input type="text" id="db_user" name="db_user" value="' . $p( 'db_user' ) . '" required>' .
		'<label for="db_pass">Database password</label><input type="password" id="db_pass" name="db_pass">' .
		'<label for="url">Site URL</label><input type="url" id="url" name="url" value="' . $p( 'url', $def_url ) . '" required>' .
		'<label><input type="checkbox" name="delete_archives" value="1"> Delete the backup archives when finished</label>' .
		'<p>Existing files with the same names will be overwritten and tables with the same names will be replaced.</p>' .
		'<button type="submit">Start restore</button></form>'
		: '<p class="err">Fix the failed requirements above and reload the page.</p>' )
);
