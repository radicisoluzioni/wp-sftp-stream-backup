<?php
/**
 * Buffers a small amount of data in memory and appends it to a remote file
 * at increasing offsets. Nothing is ever written to the local disk.
 *
 * @package WPSSB
 */

namespace WPSSB\Remote;

class RemoteAppender {

	/** @var RemoteStorage */
	private $storage;

	/** @var string */
	private $path;

	/** @var int Remote offset where the buffer will be written. */
	private $offset;

	/** @var string */
	private $buffer = '';

	/** @var int */
	private $threshold;

	public function __construct( RemoteStorage $storage, string $path, int $offset, int $threshold = 1048576 ) {
		$this->storage   = $storage;
		$this->path      = $path;
		$this->offset    = $offset;
		$this->threshold = $storage instanceof SegmentedStorage ? SegmentedStorage::PART_BYTES : max( 4096, $threshold );
	}

	public function write( string $data ): void {
		$this->buffer .= $data;
		if ( strlen( $this->buffer ) >= $this->threshold ) {
			$this->flush();
		}
	}

	public function flush(): void {
		if ( '' === $this->buffer ) {
			return;
		}
		$this->storage->writeAt( $this->path, $this->buffer, $this->offset );
		$this->offset += strlen( $this->buffer );
		$this->buffer  = '';
	}

	/** Remote offset after everything written so far (including the unflushed buffer). */
	public function position(): int {
		return $this->offset + strlen( $this->buffer );
	}
}
