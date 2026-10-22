<?php
/**
 * Shared presentation for the plugin's admin pages.
 *
 * @package WPSSB
 */

namespace WPSSB\Admin;

class Branding {

	public static function header( string $title, string $active ): void {
		?>
		<header class="wpssb-header">
			<div class="wpssb-heading">
				<p class="wpssb-eyebrow"><?php esc_html_e( 'Database + files. Direct to remote storage.', 'wp-sftp-stream-backup' ); ?></p>
				<h1><?php echo esc_html( $title ); ?></h1>
				<p><?php esc_html_e( 'SFTP / S3 / Nextcloud', 'wp-sftp-stream-backup' ); ?></p>
			</div>
			<a class="wpssb-brand" href="https://radici.biz">
				<img src="<?php echo esc_url( plugins_url( 'assets/radici-logo.png', WPSSB_FILE ) ); ?>" width="130" height="48" alt="">
				<span>Coded by <strong>Radici Soluzioni Informatiche</strong></span>
			</a>
		</header>
		<nav class="wpssb-nav" aria-label="<?php esc_attr_e( 'Backup navigation', 'wp-sftp-stream-backup' ); ?>">
			<a href="<?php echo esc_url( AdminPage::url() ); ?>" <?php echo 'backup' === $active ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'Backup & settings', 'wp-sftp-stream-backup' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'tools.php?page=wpssb-repositories' ) ); ?>" <?php echo 'repositories' === $active ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'Repositories', 'wp-sftp-stream-backup' ); ?></a>
		</nav>
		<?php
	}

	public static function footer(): void {
		?>
		<footer class="wpssb-footer">
			<span>WP SFTP Stream Backup <span class="wpssb-version"><?php echo esc_html( WPSSB_VERSION ); ?></span></span>
			<a href="https://radici.biz">Coded by <strong>Radici Soluzioni Informatiche</strong></a>
		</footer>
		<?php
	}
}
