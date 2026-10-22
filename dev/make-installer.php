<?php
/**
 * Writes the generic migration installer (no embedded code): php make-installer.php <plugin-dir> <output>.
 *
 * @package WPSSB
 */

define( 'WPSSB_DIR', rtrim( $argv[1], '/' ) . '/' );
require WPSSB_DIR . 'src/Restore/OneClick.php';
$source = \WPSSB\Restore\OneClick::installerSource( '' );
if ( false === file_put_contents( $argv[2], $source ) ) {
	fwrite( STDERR, "Cannot write {$argv[2]}\n" );
	exit( 1 );
}
echo 'Installer: ' . strlen( $source ) . " bytes\n";
