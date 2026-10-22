<?php
/**
 * E-mail notifications about finished jobs.
 *
 * @package WPSSB
 */

namespace WPSSB\Notify;

use WPSSB\Settings;

class Notifier {

	public static function jobFinished( array $state ): void {
		$settings = Settings::get();
		$mode     = $settings['notify_on'];
		if ( 'never' === $mode || ( 'failure' === $mode && 'done' === $state['status'] ) ) {
			return;
		}
		if ( 'cancelled' === $state['status'] ) {
			return;
		}
		$to = '' !== $settings['notify_email'] ? $settings['notify_email'] : get_option( 'admin_email' );
		if ( ! is_email( $to ) ) {
			return;
		}

		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$ok      = 'done' === $state['status'];
		$subject = sprintf(
			/* translators: 1: site name, 2: status */
			__( '[%1$s] SFTP backup %2$s', 'wp-sftp-stream-backup' ),
			$site,
			$ok ? __( 'completed', 'wp-sftp-stream-backup' ) : __( 'FAILED', 'wp-sftp-stream-backup' )
		);

		$lines   = array();
		$lines[] = sprintf( __( 'Site: %s', 'wp-sftp-stream-backup' ), home_url() );
		$lines[] = sprintf( __( 'Backup: %s', 'wp-sftp-stream-backup' ), $state['id'] );
		$lines[] = sprintf( __( 'Remote folder: %s', 'wp-sftp-stream-backup' ), $state['remote_dir'] );
		$lines[] = sprintf( __( 'Started: %s UTC', 'wp-sftp-stream-backup' ), gmdate( 'Y-m-d H:i:s', (int) $state['started_at'] ) );
		$lines[] = sprintf( __( 'Database: %s', 'wp-sftp-stream-backup' ), size_format( (int) $state['db']['offset'], 1 ) );
		$lines[] = sprintf( __( 'Files: %1$d entries, %2$s', 'wp-sftp-stream-backup' ), (int) $state['files']['count'], size_format( (int) $state['files']['offset'], 1 ) );
		$lines[] = sprintf( __( 'Warnings: %d', 'wp-sftp-stream-backup' ), (int) $state['warnings'] );
		foreach ( $state['repositories'] ?? array() as $repository ) {
			$lines[] = $repository['name'] . ': ' . $repository['status'] . ( $repository['error'] ? ' - ' . $repository['error'] : '' );
		}
		if ( ! $ok ) {
			$lines[] = '';
			$lines[] = sprintf( __( 'Error: %s', 'wp-sftp-stream-backup' ), $state['last_error'] );
		}
		$lines[] = '';
		$lines[] = __( 'Recent log:', 'wp-sftp-stream-backup' );
		foreach ( array_slice( $state['log'], -20 ) as $entry ) {
			$lines[] = gmdate( 'H:i:s', (int) $entry[0] ) . ' [' . $entry[1] . '] ' . $entry[2];
		}

		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}
}
