<?php
/**
 * Streaming gzip compressor producing complete gzip members.
 *
 * Several members appended to the same file form a valid multi-member gzip
 * stream (RFC 1952), which `gzip -d`, `tar xz` and PHP's gzopen() read as a
 * single continuous stream. This is what makes resumable uploads possible.
 *
 * A new member is also started every $member_bytes of input, so that a
 * reader (restore.php) can resume close to any position without
 * decompressing a huge member from its beginning.
 *
 * @package WPSSB
 */

namespace WPSSB\Stream;

class GzipMemberWriter {

	const DEFAULT_MEMBER_BYTES = 8388608;

	/** @var resource|\DeflateContext|null */
	private $ctx = null;

	/** @var callable(string):void */
	private $sink;

	/** @var int */
	private $level;

	/** @var int */
	private $member_bytes;

	/** @var bool */
	private $finished = false;

	/** @var int */
	private $bytes_in = 0;

	/** @var int Input bytes in the current member. */
	private $member_in = 0;

	/**
	 * @param callable $sink         Receives compressed bytes.
	 * @param int      $level        Compression level 1-9.
	 * @param int      $member_bytes Maximum uncompressed bytes per gzip member.
	 */
	public function __construct( callable $sink, int $level = 6, int $member_bytes = self::DEFAULT_MEMBER_BYTES ) {
		$this->level        = max( 1, min( 9, $level ) );
		$this->member_bytes = max( 65536, $member_bytes );
		$this->sink         = $sink;
		$this->open();
	}

	private function open(): void {
		$this->ctx = deflate_init( ZLIB_ENCODING_GZIP, array( 'level' => $this->level ) );
		if ( false === $this->ctx ) {
			throw new \RuntimeException( 'deflate_init() failed' );
		}
		$this->member_in = 0;
	}

	private function close(): void {
		$out = deflate_add( $this->ctx, '', ZLIB_FINISH );
		if ( false === $out ) {
			throw new \RuntimeException( 'deflate_add() failed' );
		}
		if ( '' !== $out ) {
			( $this->sink )( $out );
		}
		$this->ctx = null;
	}

	public function write( string $data ): void {
		if ( $this->finished ) {
			throw new \LogicException( 'Gzip member already finished' );
		}
		while ( '' !== $data ) {
			if ( $this->member_in >= $this->member_bytes ) {
				$this->close();
				$this->open();
			}
			$room  = $this->member_bytes - $this->member_in;
			$chunk = strlen( $data ) > $room ? substr( $data, 0, $room ) : $data;
			$data  = strlen( $data ) > $room ? substr( $data, $room ) : '';

			$this->bytes_in  += strlen( $chunk );
			$this->member_in += strlen( $chunk );
			$out              = deflate_add( $this->ctx, $chunk, ZLIB_NO_FLUSH );
			if ( false === $out ) {
				throw new \RuntimeException( 'deflate_add() failed' );
			}
			if ( '' !== $out ) {
				( $this->sink )( $out );
			}
		}
	}

	public function finish(): void {
		if ( $this->finished ) {
			return;
		}
		$this->finished = true;
		$this->close();
	}

	public function bytesIn(): int {
		return $this->bytes_in;
	}
}
