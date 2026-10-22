<?php
/**
 * Keeps a running job moving from web requests: non-blocking loopback
 * requests chain the steps, WP-Cron acts as a fallback, and the admin
 * screen can drive steps itself when both are unavailable.
 *
 * @package WPSSB
 */

namespace WPSSB\Job;

use WPSSB\Settings;

class Driver {

	const CRON_CONTINUE = 'wpssb_continue';
	const CRON_SCHEDULE = 'wpssb_scheduled';

	public static function register(): void {
		add_action( 'wp_ajax_wpssb_bg', array( __CLASS__, 'ajaxBackground' ) );
		add_action( 'wp_ajax_nopriv_wpssb_bg', array( __CLASS__, 'ajaxBackground' ) );
		add_action( self::CRON_CONTINUE, array( __CLASS__, 'cronContinue' ) );
		add_action( self::CRON_SCHEDULE, array( __CLASS__, 'cronScheduled' ) );
	}

	/** Seconds of work per web step. */
	public static function budget(): float {
		$configured = (int) Settings::get()['step_time'];
		if ( $configured > 0 ) {
			return (float) $configured;
		}
		$max = (int) ini_get( 'max_execution_time' );
		if ( $max <= 0 ) {
			return 25.0;
		}
		return (float) max( 5, min( 25, floor( $max * 0.6 ) ) );
	}

	/**
	 * Runs one step in a web/cron context and schedules the next one.
	 *
	 * @param bool $background True when nobody waits for the response (loopback/cron).
	 */
	public static function runWebStep( bool $background = false ): array {
		ignore_user_abort( true );
		$budget = self::budget();
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( (int) $budget + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		$result = Runner::step( $budget, 'web' );
		$state  = $result['state'];
		if ( $result['ran'] && $state && 'running' === $state['status'] ) {
			$delay = (int) ( $state['retry_after'] ?? 0 ) - time();
			if ( $delay > 0 ) {
				self::ensureFallback( $delay );
				return $result;
			}
			$errors = (int) $state['errors'];
			if ( $errors > 0 && $background ) {
				// Back off before retrying so that transient network errors can clear.
				sleep( min( 30, 5 * $errors ) );
			}
			if ( 0 === $errors || $background ) {
				self::kick( $state );
			}
			self::ensureFallback( 0 === $errors ? 120 : 60 * $errors );
		}
		return $result;
	}

	/**
	 * Fires a non-blocking request to ourselves to run the next step.
	 */
	public static function kick( array $state ): void {
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array(
					'action' => 'wpssb_bg',
					'job'    => $state['id'],
					'token'  => $state['token'],
				),
			)
		);
	}

	public static function ensureFallback( int $delay ): void {
		$next = wp_next_scheduled( self::CRON_CONTINUE );
		if ( $next && $next <= time() + $delay ) {
			return;
		}
		if ( $next ) {
			wp_clear_scheduled_hook( self::CRON_CONTINUE );
		}
		wp_schedule_single_event( time() + $delay, self::CRON_CONTINUE );
	}

	public static function ajaxBackground(): void {
		// Authenticated by the per-job random token, not by a user session.
		$job   = isset( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$token = isset( $_POST['token'] ) ? (string) wp_unslash( $_POST['token'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$state = State::load();
		if ( ! $state || 'running' !== $state['status'] || ! hash_equals( (string) $state['id'], $job ) || ! hash_equals( (string) $state['token'], $token ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		self::runWebStep( true );
		wp_die( '', '', array( 'response' => 200 ) );
	}

	public static function cronContinue(): void {
		$state = State::load();
		if ( $state && 'running' === $state['status'] ) {
			self::runWebStep( true );
		}
	}

	public static function cronScheduled(): void {
		try {
			$state = Runner::start( 'schedule' );
		} catch ( \Throwable $e ) {
			self::notifyStartFailure( $e->getMessage() );
			return;
		}
		unset( $state );
		self::runWebStep( true );
	}

	private static function notifyStartFailure( string $message ): void {
		$settings = Settings::get();
		if ( 'never' === $settings['notify_on'] ) {
			return;
		}
		$to = '' !== $settings['notify_email'] ? $settings['notify_email'] : get_option( 'admin_email' );
		if ( is_email( $to ) ) {
			wp_mail(
				$to,
				sprintf( '[%s] %s', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), __( 'Scheduled SFTP backup could not start', 'wp-sftp-stream-backup' ) ),
				$message
			);
		}
	}

	/**
	 * (Re)schedules the recurring backup according to the settings.
	 */
	public static function reschedule( array $settings ): void {
		wp_clear_scheduled_hook( self::CRON_SCHEDULE );
		if ( 'off' === $settings['schedule'] ) {
			return;
		}
		$tz = wp_timezone();
		list( $hour, $minute ) = array_map( 'intval', explode( ':', $settings['schedule_time'] ) );
		$next = new \DateTime( 'now', $tz );
		$next->setTime( $hour, $minute, 0 );
		if ( 'hourly' === $settings['schedule'] ) {
			$now = new \DateTime( 'now', $tz );
			$next->setTime( (int) $now->format( 'G' ), $minute, 0 );
			if ( $next->getTimestamp() <= time() ) {
				$next->modify( '+1 hour' );
			}
		} elseif ( $next->getTimestamp() <= time() ) {
			$next->modify( '+1 day' );
		}
		wp_schedule_event( $next->getTimestamp(), $settings['schedule'], self::CRON_SCHEDULE );
	}

	public static function unscheduleAll(): void {
		wp_clear_scheduled_hook( self::CRON_SCHEDULE );
		wp_clear_scheduled_hook( self::CRON_CONTINUE );
	}
}
