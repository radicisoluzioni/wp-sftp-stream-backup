<?php
/**
 * Streaming POSIX tar (ustar + pax extensions) writer.
 *
 * Entries are written incrementally through a sink so that file contents
 * never need to be buffered as a whole. An entry can be resumed in a later
 * request (see resumeEntry()) which lets a single file span several steps.
 *
 * @package WPSSB
 */

namespace WPSSB\Archive;

class TarWriter {

	const BLOCK = 512;

	/** @var callable(string):void */
	private $sink;

	/** @var int|null Declared size of the open entry. */
	private $size = null;

	/** @var int Bytes of data written for the open entry. */
	private $written = 0;

	public function __construct( callable $sink ) {
		$this->sink = $sink;
	}

	/**
	 * Writes the header(s) of a new entry.
	 *
	 * @param string $name     Relative path using "/" separators. Directories should end with "/".
	 * @param int    $size     Exact number of data bytes that will follow (0 for dirs/links).
	 * @param int    $mode     Permission bits.
	 * @param int    $mtime    Modification time.
	 * @param string $type     '0' file, '5' directory, '2' symlink.
	 * @param string $linkname Symlink target.
	 */
	public function beginEntry( string $name, int $size, int $mode, int $mtime, string $type = '0', string $linkname = '' ): void {
		if ( null !== $this->size ) {
			throw new \LogicException( 'Previous tar entry not ended' );
		}
		if ( $size < 0 ) {
			throw new \InvalidArgumentException( 'Negative size' );
		}
		$mtime = max( 0, $mtime );

		$pax         = array();
		$ustar_name  = $name;
		$prefix      = '';
		$header_size = $size;

		if ( strlen( $name ) > 100 ) {
			$split = self::splitUstarName( $name );
			if ( null !== $split ) {
				list( $prefix, $ustar_name ) = $split;
			} else {
				$pax['path'] = $name;
				$ustar_name  = substr( $name, 0, 100 );
			}
		}
		if ( strlen( $linkname ) > 100 ) {
			$pax['linkpath'] = $linkname;
			$linkname        = substr( $linkname, 0, 100 );
		}
		if ( $size > 077777777777 ) {
			$pax['size'] = (string) $size;
			$header_size = 0;
		}

		if ( $pax ) {
			$records = '';
			foreach ( $pax as $key => $value ) {
				$records .= self::paxRecord( $key, $value );
			}
			$pax_name = 'PaxHeader/' . substr( basename( $name ), 0, 80 );
			$this->emit( self::header( $pax_name, '', strlen( $records ), 0644, $mtime, 'x', '' ) );
			$this->emit( $records . self::padding( strlen( $records ) ) );
		}

		$this->emit( self::header( $ustar_name, $prefix, $header_size, $mode & 07777, $mtime, $type, $linkname ) );
		$this->size    = $size;
		$this->written = 0;
	}

	/**
	 * Re-opens an entry whose header (and part of its data) was written in a previous step.
	 */
	public function resumeEntry( int $size, int $written ): void {
		if ( null !== $this->size ) {
			throw new \LogicException( 'Previous tar entry not ended' );
		}
		if ( $written < 0 || $written > $size ) {
			throw new \InvalidArgumentException( 'Invalid resume position' );
		}
		$this->size    = $size;
		$this->written = $written;
	}

	/**
	 * Writes entry data. Data exceeding the declared size is silently dropped
	 * (the file grew while being read); returns the number of bytes accepted.
	 */
	public function writeData( string $data ): int {
		if ( null === $this->size ) {
			throw new \LogicException( 'No open tar entry' );
		}
		$room = $this->size - $this->written;
		if ( strlen( $data ) > $room ) {
			$data = substr( $data, 0, $room );
		}
		if ( '' === $data ) {
			return 0;
		}
		$this->written += strlen( $data );
		$this->emit( $data );
		return strlen( $data );
	}

	/**
	 * Closes the open entry. Missing data (file shrank while being read) is
	 * zero-filled so the archive stays consistent. Returns the number of
	 * zero bytes that had to be added.
	 */
	public function endEntry(): int {
		if ( null === $this->size ) {
			throw new \LogicException( 'No open tar entry' );
		}
		$missing = $this->size - $this->written;
		$left    = $missing;
		while ( $left > 0 ) {
			$n = min( $left, 65536 );
			$this->emit( str_repeat( "\0", $n ) );
			$left -= $n;
		}
		$this->emit( self::padding( $this->size ) );
		$this->size    = null;
		$this->written = 0;
		return $missing;
	}

	public function finishArchive(): void {
		if ( null !== $this->size ) {
			throw new \LogicException( 'Tar entry still open' );
		}
		$this->emit( str_repeat( "\0", self::BLOCK * 2 ) );
	}

	public function hasOpenEntry(): bool {
		return null !== $this->size;
	}

	public function entryWritten(): int {
		return $this->written;
	}

	public function entrySize(): int {
		return (int) $this->size;
	}

	private function emit( string $bytes ): void {
		if ( '' !== $bytes ) {
			( $this->sink )( $bytes );
		}
	}

	public static function padding( int $size ): string {
		$rem = $size % self::BLOCK;
		return 0 === $rem ? '' : str_repeat( "\0", self::BLOCK - $rem );
	}

	/**
	 * @return array{0:string,1:string}|null [prefix, name]
	 */
	private static function splitUstarName( string $path ): ?array {
		if ( strlen( $path ) > 256 ) {
			return null;
		}
		$pos = strlen( $path );
		while ( false !== ( $pos = strrpos( substr( $path, 0, $pos ), '/' ) ) ) {
			$prefix = substr( $path, 0, $pos );
			$name   = substr( $path, $pos + 1 );
			if ( strlen( $prefix ) <= 155 && strlen( $name ) <= 100 && '' !== $name ) {
				return array( $prefix, $name );
			}
			if ( 0 === $pos ) {
				break;
			}
		}
		return null;
	}

	private static function paxRecord( string $key, string $value ): string {
		$body = ' ' . $key . '=' . $value . "\n";
		$len  = strlen( $body ) + 1;
		while ( strlen( (string) $len ) + strlen( $body ) !== $len ) {
			$len = strlen( (string) $len ) + strlen( $body );
		}
		return $len . $body;
	}

	private static function header( string $name, string $prefix, int $size, int $mode, int $mtime, string $type, string $linkname ): string {
		$h  = str_pad( substr( $name, 0, 100 ), 100, "\0" );
		$h .= sprintf( '%07o', $mode ) . "\0";
		$h .= sprintf( '%07o', 0 ) . "\0";
		$h .= sprintf( '%07o', 0 ) . "\0";
		$h .= sprintf( '%011o', $size ) . "\0";
		$h .= sprintf( '%011o', min( $mtime, 077777777777 ) ) . "\0";
		$h .= '        ';
		$h .= $type;
		$h .= str_pad( substr( $linkname, 0, 100 ), 100, "\0" );
		$h .= "ustar\0" . '00';
		$h .= str_repeat( "\0", 32 + 32 + 8 + 8 );
		$h .= str_pad( substr( $prefix, 0, 155 ), 155, "\0" );
		$h .= str_repeat( "\0", 12 );

		$sum = 0;
		for ( $i = 0; $i < self::BLOCK; $i++ ) {
			$sum += ord( $h[ $i ] );
		}
		return substr_replace( $h, sprintf( '%06o', $sum ) . "\0 ", 148, 8 );
	}
}
