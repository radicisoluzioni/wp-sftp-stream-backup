<?php
/**
 * Deterministic, resumable directory walker.
 *
 * Entries are produced depth-first with children sorted byte-wise, which is
 * equivalent to ordering relative paths by their component sequence. Because
 * the order is stable, resuming only needs the relative path of the last
 * processed entry: everything up to and including it is skipped without
 * keeping any file list on disk.
 *
 * @package WPSSB
 */

namespace WPSSB\Files;

class Walker {

	/** @var string */
	private $root;

	/** @var Exclusions */
	private $exclusions;

	/** @var array<string,string> Virtual root-level files: name => absolute path. */
	private $extra;

	/** @var callable|null */
	private $warn;

	/**
	 * @param string               $root       Absolute directory.
	 * @param Exclusions           $exclusions Exclusion rules.
	 * @param array<string,string> $extra      Additional files exposed at the archive root.
	 * @param callable|null        $warn       Receives warning messages.
	 */
	public function __construct( string $root, Exclusions $exclusions, array $extra = array(), ?callable $warn = null ) {
		$this->root       = rtrim( str_replace( '\\', '/', $root ), '/' );
		$this->exclusions = $exclusions;
		$this->extra      = $extra;
		$this->warn       = $warn;
	}

	/**
	 * @param string|null $cursor Relative path of the last processed entry.
	 * @return \Generator<int, array{rel:string, abs:string, type:string}>
	 */
	public function walk( ?string $cursor = null ): \Generator {
		$cursor_parts = ( null === $cursor || '' === $cursor ) ? null : explode( '/', $cursor );
		yield from $this->walkDir( $this->root, array(), $cursor_parts, $this->extra );
	}

	/**
	 * Compares two paths given as component arrays in walk order.
	 */
	public static function compare( array $a, array $b ): int {
		$n = min( count( $a ), count( $b ) );
		for ( $i = 0; $i < $n; $i++ ) {
			$c = strcmp( $a[ $i ], $b[ $i ] );
			if ( 0 !== $c ) {
				return $c < 0 ? -1 : 1;
			}
		}
		return count( $a ) <=> count( $b );
	}

	private static function isPrefix( array $prefix, array $path ): bool {
		if ( count( $prefix ) > count( $path ) ) {
			return false;
		}
		foreach ( $prefix as $i => $part ) {
			if ( $path[ $i ] !== $part ) {
				return false;
			}
		}
		return true;
	}

	private function walkDir( string $abs_dir, array $rel_parts, ?array $cursor, array $extra ): \Generator {
		$names = @scandir( $abs_dir );
		if ( false === $names ) {
			$this->warning( sprintf( 'Cannot read directory "%s"', $this->relString( $rel_parts ) ) );
			return;
		}
		$map = array();
		foreach ( $names as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				$map[ $name ] = $abs_dir . '/' . $name;
			}
		}
		foreach ( $extra as $name => $abs ) {
			if ( ! isset( $map[ $name ] ) ) {
				$map[ $name ] = $abs;
			}
		}
		$names = array_keys( $map );
		$names = array_map( 'strval', $names );
		sort( $names, SORT_STRING );

		foreach ( $names as $name ) {
			$parts = $rel_parts;
			$parts[] = $name;
			$rel     = implode( '/', $parts );
			$abs     = $map[ $name ];

			$cmp = null === $cursor ? 1 : self::compare( $parts, $cursor );

			if ( $cmp <= 0 ) {
				// Already processed. Descend only along the cursor's ancestry.
				if ( null !== $cursor && self::isPrefix( $parts, $cursor ) && ! is_link( $abs ) && is_dir( $abs ) && ! $this->exclusions->isExcluded( $rel ) ) {
					yield from $this->walkDir( $abs, $parts, $cursor, array() );
				}
				continue;
			}

			if ( $this->exclusions->isExcluded( $rel ) ) {
				continue;
			}

			if ( is_link( $abs ) ) {
				$target = @readlink( $abs );
				if ( false === $target ) {
					$this->warning( sprintf( 'Cannot read symlink "%s"', $rel ) );
					continue;
				}
				yield array(
					'rel'    => $rel,
					'abs'    => $abs,
					'type'   => 'link',
					'target' => $target,
				);
				continue;
			}

			if ( is_dir( $abs ) ) {
				yield array(
					'rel'  => $rel,
					'abs'  => $abs,
					'type' => 'dir',
				);
				yield from $this->walkDir( $abs, $parts, null, array() );
				continue;
			}

			if ( is_file( $abs ) ) {
				yield array(
					'rel'  => $rel,
					'abs'  => $abs,
					'type' => 'file',
				);
			}
		}
	}

	private function relString( array $parts ): string {
		return $parts ? implode( '/', $parts ) : '.';
	}

	private function warning( string $message ): void {
		if ( $this->warn ) {
			( $this->warn )( $message );
		}
	}
}
