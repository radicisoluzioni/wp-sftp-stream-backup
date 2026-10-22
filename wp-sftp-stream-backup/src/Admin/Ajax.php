<?php
/**
 * Admin AJAX endpoints (all require manage_options + nonce).
 *
 * @package WPSSB
 */

namespace WPSSB\Admin;

use WPSSB\Job\Driver;
use WPSSB\Job\Lock;
use WPSSB\Job\Retention;
use WPSSB\Job\Runner;
use WPSSB\Job\State;
use WPSSB\Remote\HostKeyException;
use WPSSB\Remote\SftpStorage;
use WPSSB\Settings;

class Ajax {

	public static function register(): void {
		$map = array(
			'start'  => 'start',
			'status' => 'status',
			'cancel' => 'cancel',
			'test'   => 'test',
			'list'   => 'listBackups',
		);
		foreach ( $map as $action => $method ) {
			add_action( 'wp_ajax_wpssb_' . $action, array( __CLASS__, $method ) );
		}
	}

	private static function guard(): void {
		if ( ! current_user_can( AdminPage::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'wp-sftp-stream-backup' ) ), 403 );
		}
		if ( ! check_ajax_referer( 'wpssb', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session expired, please reload the page.', 'wp-sftp-stream-backup' ) ), 403 );
		}
	}

	public static function start(): void {
		self::guard();
		try {
			$state = Runner::start( 'manual' );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
		Driver::kick( $state );
		Driver::ensureFallback( 120 );
		wp_send_json_success( State::summary( $state ) );
	}

	/**
	 * Returns the job status. When the background chain looks stalled
	 * (no update for a while and nobody holding the lock) the browser
	 * drives a step itself.
	 */
	public static function status(): void {
		self::guard();
		$state = State::load();
		$drive = ! empty( $_POST['drive'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		if ( $drive && $state && 'running' === $state['status'] && time() - (int) $state['updated_at'] > 10 && ! Lock::isLocked() ) {
			$result = Driver::runWebStep();
			$state  = $result['state'];
		}
		wp_send_json_success( State::summary( $state ) );
	}

	public static function cancel(): void {
		self::guard();
		Runner::cancel();
		wp_send_json_success( State::summary( State::load() ) );
	}

	/**
	 * Tests the connection with the values currently in the form (empty
	 * secret fields fall back to the stored ones).
	 */
	public static function test(): void {
		self::guard();
		$form  = isset( $_POST['wpssb'] ) && is_array( $_POST['wpssb'] ) ? wp_unslash( $_POST['wpssb'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$saved = Settings::get();
		$s     = $saved;
		foreach ( array( 'host', 'port', 'username', 'auth', 'fingerprint', 'remote_path' ) as $key ) {
			if ( ! Settings::isConstant( $key ) && isset( $form[ $key ] ) ) {
				$s[ $key ] = sanitize_text_field( (string) $form[ $key ] );
			}
		}
		foreach ( Settings::SECRET_FIELDS as $key ) {
			if ( ! Settings::isConstant( $key ) && ! empty( $form[ 'clear_' . $key ] ) ) {
				$s[ $key ] = '';
			} elseif ( ! Settings::isConstant( $key ) && isset( $form[ $key ] ) && '' !== (string) $form[ $key ] ) {
				$s[ $key ] = 'private_key' === $key ? trim( str_replace( "\r\n", "\n", (string) $form[ $key ] ) ) : (string) $form[ $key ];
			}
		}
		$s['host'] = preg_replace( '#^s?ftp://#i', '', trim( (string) $s['host'] ) );
		$s['port'] = (int) $s['port'] > 0 ? (int) $s['port'] : 22;

		$storage = new SftpStorage( Settings::connection( $s ) );
		try {
			$fingerprint = $storage->connect();
			$base        = Settings::remoteBase( $s );
			$storage->mkdir( $base );
			$probe = $base . '/.wpssb-write-test-' . wp_generate_password( 8, false, false );
			$storage->putContents( $probe, 'ok' );
			$storage->delete( $probe );
			$storage->disconnect();
		} catch ( HostKeyException $e ) {
			wp_send_json_error(
				array(
					'message'     => $e->getMessage(),
					'fingerprint' => $e->fingerprint(),
					'mismatch'    => '' !== trim( (string) $s['fingerprint'] ),
				)
			);
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}

		wp_send_json_success(
			array(
				'message'     => sprintf(
					/* translators: 1: folder */
					__( 'Connection OK. The remote folder "%1$s" is writable.', 'wp-sftp-stream-backup' ),
					$base
				),
				'fingerprint' => $fingerprint,
			)
		);
	}

	public static function listBackups(): void {
		self::guard();
		try {
			if ( \WPSSB\Job\Replication::configured() ) {
				$all = \WPSSB\Repositories::get();
				$id = isset( $_POST['repository'] ) ? sanitize_key( wp_unslash( $_POST['repository'] ) ) : $all['primary'];
				wp_send_json_success( \WPSSB\Repositories::listing( $id ) );
			}
			$storage = Runner::storage();
			if ( $storage instanceof SftpStorage ) {
				$storage->connect();
			}
			$base    = Settings::remoteBase();
			$backups = Retention::listBackups( $storage, $base, Runner::siteSlug() );
			$out     = array();
			foreach ( $backups as $backup ) {
				$dir   = $base . '/' . $backup['name'];
				$out[] = array(
					'name'     => $backup['name'],
					'date'     => $backup['time'] ? wp_date( 'Y-m-d H:i', $backup['time'] ) : '',
					'complete' => $backup['complete'],
					'path'     => $dir,
					'db'       => size_format( (int) $storage->size( $dir . '/database.sql.gz' ), 1 ),
					'files'    => size_format( (int) $storage->size( $dir . '/files.tar.gz' ), 1 ),
				);
			}
			$storage->disconnect();
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
		wp_send_json_success( $out );
	}
}
