<?php
/**
 * PHPUnit bootstrap: loads the plugin classes without WordPress.
 *
 * @package WPSSB
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';
if ( is_readable( dirname( __DIR__ ) . '/vendor-prefixed/autoload.php' ) ) {
	require dirname( __DIR__ ) . '/vendor-prefixed/autoload.php';
}

spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'WPSSB\\' ) || 0 === strpos( $class, 'WPSSB\\Tests\\' ) || 0 === strpos( $class, 'WPSSB\\Vendor\\' ) ) {
			return;
		}
		$file = dirname( __DIR__ ) . '/src/' . str_replace( '\\', '/', substr( $class, 6 ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

// restore.php is loaded as a library, extracting into a temporary directory.
define( 'WPSSB_RESTORE_LIBRARY_ONLY', true );
define( 'RS_DIR', rtrim( str_replace( '\\', '/', sys_get_temp_dir() ), '/' ) . '/wpssb-restore-' . getmypid() );
require dirname( __DIR__ ) . '/restore/restore.php';
