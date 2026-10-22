<?php
/**
 * Path exclusion rules (relative to the WordPress root, "/" separated).
 *
 * @package WPSSB
 */

namespace WPSSB\Files;

class Exclusions {

	/** @var string[] */
	private $patterns = array();

	/**
	 * @param string[] $patterns Relative paths or fnmatch() patterns.
	 */
	public function __construct( array $patterns ) {
		foreach ( $patterns as $pattern ) {
			$pattern = trim( str_replace( '\\', '/', (string) $pattern ) );
			$pattern = trim( $pattern, '/' );
			if ( '' !== $pattern && '#' !== $pattern[0] ) {
				$this->patterns[] = $pattern;
			}
		}
		$this->patterns = array_values( array_unique( $this->patterns ) );
	}

	public static function fromText( string $text ): self {
		return new self( preg_split( '/\r\n|\r|\n/', $text ) ?: array() );
	}

	public function isExcluded( string $rel ): bool {
		foreach ( $this->patterns as $pattern ) {
			if ( $rel === $pattern || 0 === strpos( $rel, $pattern . '/' ) ) {
				return true;
			}
			if ( false !== strpbrk( $pattern, '*?[' ) && fnmatch( $pattern, $rel ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return string[] */
	public function patterns(): array {
		return $this->patterns;
	}
}
