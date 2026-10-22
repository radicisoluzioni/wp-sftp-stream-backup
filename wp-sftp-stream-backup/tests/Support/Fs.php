<?php
/**
 * Filesystem helpers for tests.
 *
 * @package WPSSB
 */

namespace WPSSB\Tests\Support;

class Fs {

	public static function tmp( string $name ): string {
		$dir = rtrim( str_replace( '\\', '/', sys_get_temp_dir() ), '/' ) . '/wpssb-test-' . $name . '-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir, 0777, true );
		return $dir;
	}

	public static function rmrf( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( scandir( $path ) as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				self::rmrf( $path . '/' . $name );
			}
		}
		rmdir( $path );
	}

	public static function write( string $path, string $data ): void {
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}
		file_put_contents( $path, $data );
	}

	/**
	 * Map of relative path => 'dir' | 'link:<target>' | sha1 of contents.
	 *
	 * @param string[] $ignore Relative paths (and their children) to ignore.
	 */
	public static function snapshot( string $root, array $ignore = array() ): array {
		$out = array();
		$it  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $it as $file ) {
			$rel = substr( str_replace( '\\', '/', $file->getPathname() ), strlen( $root ) + 1 );
			foreach ( $ignore as $skip ) {
				if ( $rel === $skip || 0 === strpos( $rel, $skip . '/' ) ) {
					continue 2;
				}
			}
			if ( $file->isLink() ) {
				$out[ $rel ] = 'link:' . readlink( $file->getPathname() );
			} elseif ( $file->isDir() ) {
				$out[ $rel ] = 'dir';
			} else {
				$out[ $rel ] = sha1_file( $file->getPathname() );
			}
		}
		ksort( $out );
		return $out;
	}

	/** Builds a sample WordPress-like tree. */
	public static function sampleTree( string $root ): void {
		self::write( $root . '/index.php', '<?php // index' );
		self::write( $root . '/wp-config.php', "<?php define( 'DB_NAME', 'x' );" );
		self::write( $root . '/wp-content/uploads/2026/10/photo.jpg', random_bytes( 3 * 1024 * 1024 + 123 ) );
		self::write( $root . '/wp-content/uploads/2026/10/empty.txt', '' );
		self::write( $root . '/wp-content/cache/page.html', 'cached' );
		self::write( $root . '/wp-content/debug.log', 'log' );
		self::write( $root . '/wp-content/plugins/a-b/x.php', 'ab' );
		self::write( $root . '/wp-content/plugins/a/b.php', 'a/b' );
		self::write( $root . '/wp-content/plugins/a-c.php', 'a-c' );
		$long = $root . '/wp-content/themes/' . str_repeat( 'very-long-directory-name-', 6 ) . '/' . str_repeat( 'f', 120 ) . '.php';
		self::write( $long, 'long name' );
		self::write( $root . '/wp-content/themes/accentué/ñ.txt', 'unicode' );
		mkdir( $root . '/wp-content/upgrade-empty', 0777, true );
		for ( $i = 0; $i < 40; $i++ ) {
			self::write( sprintf( '%s/wp-includes/file-%02d.php', $root, $i ), str_repeat( "line $i\n", 200 + $i ) );
		}
		if ( function_exists( 'symlink' ) ) {
			@symlink( 'wp-content/plugins/a/b.php', $root . '/link-to-b.php' );
		}
	}
}
