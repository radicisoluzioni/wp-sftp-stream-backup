<?php
/**
 * Plugin bootstrap.
 *
 * @package WPSSB
 */

namespace WPSSB;

use WPSSB\Admin\AdminPage;
use WPSSB\Admin\Ajax;
use WPSSB\Job\Driver;

class Plugin {

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'loadTextdomain' ) );
		Driver::register();
		add_filter( 'http_request_args', array( '\WPSSB\Job\Lock', 'httpHeartbeat' ) );
		if ( is_admin() ) {
			AdminPage::register();
			Ajax::register();
			\WPSSB\Admin\RepositoryPage::register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'sftp-backup', '\WPSSB\Cli\Command' );
			\WP_CLI::add_command( 'stream-backup', '\WPSSB\Cli\Command' );
		}
	}

	public static function loadTextdomain(): void {
		load_plugin_textdomain( 'wp-sftp-stream-backup', false, dirname( plugin_basename( WPSSB_FILE ) ) . '/languages' );
	}

	public static function activate(): void {
		Driver::reschedule( Settings::get() );
	}
}
