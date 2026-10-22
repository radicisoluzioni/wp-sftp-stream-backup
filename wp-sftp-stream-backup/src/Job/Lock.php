<?php
/**
 * Cross-process mutex stored as a row in the options table.
 *
 * INSERT IGNORE on the unique option_name is atomic, unlike add_option().
 *
 * @package WPSSB
 */

namespace WPSSB\Job;

class Lock {

	const NAME = 'wpssb_lock';
	private static $active_token = null;
	private static $active_ttl = 120;

	public static function begin( string $token, int $ttl ): void {
		self::$active_token = $token;
		self::$active_ttl = $ttl;
	}

	public static function end(): void {
		self::$active_token = null;
	}

	/** Refresh before each bounded network operation and before committing state. */
	public static function heartbeat(): void {
		if ( null === self::$active_token ) {
			return;
		}
		global $wpdb;
		$value = ( time() + self::$active_ttl ) . '|' . self::$active_token;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s",
				$value, self::NAME, '%|' . $wpdb->esc_like( self::$active_token )
			)
		);
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::NAME ) );
		if ( $current !== $value ) {
			throw new LostLockException( 'Backup lock ownership was lost; this worker stopped without overwriting another worker.' );
		}
	}

	public static function httpHeartbeat( array $args ): array {
		self::heartbeat();
		return $args;
	}

	/**
	 * @param int $ttl Seconds after which the lock is considered abandoned.
	 * @return string|null Token to pass to release(), or null when held by someone else.
	 */
	public static function acquire( int $ttl ): ?string {
		global $wpdb;
		$token = wp_generate_password( 20, false, false );
		$value = ( time() + $ttl ) . '|' . $token;

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					self::NAME,
					$value
				)
			);
			if ( 1 === (int) $inserted ) {
				return $token;
			}
			$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::NAME ) );
			if ( null === $current ) {
				continue;
			}
			$expires = (int) strtok( (string) $current, '|' );
			if ( $expires >= time() ) {
				return null;
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::NAME, $current ) );
		}
		return null;
	}

	public static function release( string $token ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
				self::NAME,
				'%|' . $wpdb->esc_like( $token )
			)
		);
	}

	public static function isLocked(): bool {
		global $wpdb;
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::NAME ) );
		return null !== $current && (int) strtok( (string) $current, '|' ) >= time();
	}
}
