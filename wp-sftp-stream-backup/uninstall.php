<?php
/**
 * Removes all plugin data. Remote backups are left untouched.
 *
 * @package WPSSB
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'wpssb_scheduled' );
wp_clear_scheduled_hook( 'wpssb_continue' );
foreach ( array( 'wpssb_settings', 'wpssb_repositories', 'wpssb_job', 'wpssb_history', 'wpssb_lock', 'wpssb_cancel' ) as $wpssb_option ) {
	delete_option( $wpssb_option );
}
