<?php
/**
 * Plugin Name:       WP SFTP Stream Backup
 * Description:       WordPress backups streamed to SFTP, S3 and Nextcloud, with automatic replicas and no local backup archives.
 * Version:           1.1.1
 * Requires at least: 5.4
 * Requires PHP:      7.4
 * Author:            Radici Soluzioni Informatiche
 * Author URI:        https://radici.biz
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-sftp-stream-backup
 * Domain Path:       /languages
 *
 * @package WPSSB
 */

defined( 'ABSPATH' ) || exit;

define( 'WPSSB_VERSION', '1.1.1' );
define( 'WPSSB_FILE', __FILE__ );
define( 'WPSSB_DIR', plugin_dir_path( __FILE__ ) );

if ( version_compare( PHP_VERSION, '7.4', '<' ) || ! function_exists( 'deflate_init' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'WP SFTP Stream Backup requires PHP 7.4+ with the zlib extension.', 'wp-sftp-stream-backup' ) . '</p></div>';
		}
	);
	return;
}

if ( is_readable( WPSSB_DIR . 'vendor-prefixed/autoload.php' ) ) {
	require_once WPSSB_DIR . 'vendor-prefixed/autoload.php';
}

spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'WPSSB\\' ) || 0 === strpos( $class, 'WPSSB\\Vendor\\' ) ) {
			return;
		}
		$file = WPSSB_DIR . 'src/' . str_replace( '\\', '/', substr( $class, 6 ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

\WPSSB\Plugin::boot();

register_deactivation_hook( __FILE__, array( '\WPSSB\Job\Driver', 'unscheduleAll' ) );
register_activation_hook( __FILE__, array( '\WPSSB\Plugin', 'activate' ) );
