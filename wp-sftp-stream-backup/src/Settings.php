<?php
/**
 * Plugin settings storage, sanitization and wp-config.php constant overrides.
 *
 * @package WPSSB
 */

namespace WPSSB;

use WPSSB\Security\Secrets;

class Settings {

	const OPTION = 'wpssb_settings';

	/** Secret fields stored encrypted. */
	const SECRET_FIELDS = array( 'password', 'private_key', 'passphrase' );

	/** Setting => wp-config.php constant that overrides it. */
	const CONSTANTS = array(
		'host'        => 'WPSSB_SFTP_HOST',
		'port'        => 'WPSSB_SFTP_PORT',
		'username'    => 'WPSSB_SFTP_USERNAME',
		'auth'        => 'WPSSB_SFTP_AUTH',
		'password'    => 'WPSSB_SFTP_PASSWORD',
		'private_key' => 'WPSSB_SFTP_PRIVATE_KEY',
		'passphrase'  => 'WPSSB_SFTP_PASSPHRASE',
		'fingerprint' => 'WPSSB_SFTP_FINGERPRINT',
		'remote_path' => 'WPSSB_SFTP_PATH',
	);

	const SCHEDULES = array( 'off', 'hourly', 'twicedaily', 'daily', 'weekly' );

	public static function defaultExcludes(): string {
		return implode(
			"\n",
			array(
				'wp-content/cache',
				'wp-content/et-cache',
				'wp-content/upgrade',
				'wp-content/upgrade-temp-backup',
				'wp-content/backups',
				'wp-content/backup-db',
				'wp-content/backups-dup-lite',
				'wp-content/backups-dup-pro',
				'wp-content/updraft',
				'wp-content/ai1wm-backups',
				'wp-content/wpvivid*',
				'wp-content/uploads/backwpup*',
				'wp-content/debug.log',
			)
		);
	}

	public static function defaults(): array {
		return array(
			'host'           => '',
			'port'           => 22,
			'username'       => '',
			'auth'           => 'password',
			'password'       => '',
			'private_key'    => '',
			'passphrase'     => '',
			'fingerprint'    => '',
			'remote_path'    => 'wp-backups',
			'schedule'       => 'off',
			'schedule_time'  => '03:00',
			'retention'      => 7,
			'exclude_paths'  => self::defaultExcludes(),
			'exclude_tables' => array(),
			'all_tables'     => false,
			'compression'    => 6,
			'step_time'      => 0,
			'notify_on'      => 'failure',
			'notify_email'   => '',
		);
	}

	/** Raw stored settings (secrets still encrypted). */
	public static function raw(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	public static function isConstant( string $key ): bool {
		return isset( self::CONSTANTS[ $key ] ) && defined( self::CONSTANTS[ $key ] );
	}

	/**
	 * Effective settings with secrets decrypted and constants applied.
	 */
	public static function get(): array {
		$s = self::raw();
		foreach ( self::SECRET_FIELDS as $field ) {
			$plain       = Secrets::decrypt( (string) $s[ $field ] );
			$s[ $field ] = null === $plain ? '' : $plain;
		}
		foreach ( self::CONSTANTS as $key => $constant ) {
			if ( defined( $constant ) ) {
				$s[ $key ] = constant( $constant );
			}
		}
		if ( ! self::isConstant( 'private_key' ) && defined( 'WPSSB_SFTP_PRIVATE_KEY_FILE' ) && is_readable( WPSSB_SFTP_PRIVATE_KEY_FILE ) ) {
			$s['private_key'] = (string) file_get_contents( WPSSB_SFTP_PRIVATE_KEY_FILE );
		}
		$s['port'] = (int) $s['port'];
		return $s;
	}

	/** Secrets that exist but cannot be decrypted anymore (e.g. salts changed). */
	public static function brokenSecrets(): array {
		$s      = self::raw();
		$broken = array();
		foreach ( self::SECRET_FIELDS as $field ) {
			if ( ! self::isConstant( $field ) && '' !== (string) $s[ $field ] && null === Secrets::decrypt( (string) $s[ $field ] ) ) {
				$broken[] = $field;
			}
		}
		return $broken;
	}

	/** Connection array for SftpStorage. */
	public static function connection( ?array $s = null ): array {
		$s = $s ? $s : self::get();
		return array(
			'host'        => (string) $s['host'],
			'port'        => (int) $s['port'],
			'username'    => (string) $s['username'],
			'auth'        => (string) $s['auth'],
			'password'    => (string) $s['password'],
			'private_key' => (string) $s['private_key'],
			'passphrase'  => (string) $s['passphrase'],
			'fingerprint' => (string) $s['fingerprint'],
			'timeout'     => 30,
		);
	}

	public static function remoteBase( ?array $s = null ): string {
		$s    = $s ? $s : self::get();
		$path = trim( str_replace( '\\', '/', (string) $s['remote_path'] ) );
		$path = rtrim( $path, '/' );
		if ( '' === $path ) {
			return '.';
		}
		return $path;
	}

	/**
	 * Builds the new stored settings from submitted input.
	 *
	 * Empty secret fields keep the stored value unless "clear_<field>" is set.
	 *
	 * @param array $input Unslashed form input.
	 */
	public static function sanitize( array $input ): array {
		$old = self::raw();
		$new = $old;

		$new['host']     = self::cleanHost( isset( $input['host'] ) ? (string) $input['host'] : '' );
		$new['port']     = max( 1, min( 65535, (int) ( isset( $input['port'] ) ? $input['port'] : 22 ) ) );
		$new['username'] = sanitize_text_field( isset( $input['username'] ) ? (string) $input['username'] : '' );
		$new['auth']     = ( isset( $input['auth'] ) && 'key' === $input['auth'] ) ? 'key' : 'password';

		foreach ( self::SECRET_FIELDS as $field ) {
			if ( ! empty( $input[ 'clear_' . $field ] ) ) {
				$new[ $field ] = '';
				continue;
			}
			$value = isset( $input[ $field ] ) ? (string) $input[ $field ] : '';
			if ( 'private_key' === $field ) {
				$value = trim( str_replace( "\r\n", "\n", $value ) );
			}
			if ( '' !== $value ) {
				$new[ $field ] = Secrets::encrypt( $value );
			}
		}

		$fingerprint = isset( $input['fingerprint'] ) ? trim( (string) $input['fingerprint'] ) : '';
		$new['fingerprint'] = '' === $fingerprint ? '' : preg_replace( '/[^A-Za-z0-9+\/:=]/', '', $fingerprint );

		$path = isset( $input['remote_path'] ) ? (string) $input['remote_path'] : '';
		$path = trim( str_replace( array( "\0", '\\' ), array( '', '/' ), $path ) );
		$new['remote_path'] = '' === $path ? 'wp-backups' : sanitize_text_field( $path );

		$schedule        = isset( $input['schedule'] ) ? (string) $input['schedule'] : 'off';
		$new['schedule'] = in_array( $schedule, self::SCHEDULES, true ) ? $schedule : 'off';
		$time            = isset( $input['schedule_time'] ) ? (string) $input['schedule_time'] : '03:00';
		$new['schedule_time'] = preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : '03:00';

		$new['retention']   = max( 0, min( 1000, (int) ( isset( $input['retention'] ) ? $input['retention'] : 7 ) ) );
		$new['compression'] = max( 1, min( 9, (int) ( isset( $input['compression'] ) ? $input['compression'] : 6 ) ) );
		$new['step_time']   = max( 0, min( 600, (int) ( isset( $input['step_time'] ) ? $input['step_time'] : 0 ) ) );

		$excludes = isset( $input['exclude_paths'] ) ? (string) $input['exclude_paths'] : '';
		$lines    = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $excludes ) ?: array() ), 'strlen' );
		$new['exclude_paths'] = implode( "\n", array_map( 'sanitize_text_field', $lines ) );

		$tables = isset( $input['exclude_tables'] ) ? (array) $input['exclude_tables'] : array();
		$new['exclude_tables'] = array_values( array_unique( array_filter( array_map( 'strval', $tables ), 'strlen' ) ) );
		$new['all_tables']     = ! empty( $input['all_tables'] );

		$notify_on        = isset( $input['notify_on'] ) ? (string) $input['notify_on'] : 'failure';
		$new['notify_on'] = in_array( $notify_on, array( 'never', 'failure', 'always' ), true ) ? $notify_on : 'failure';
		$email            = isset( $input['notify_email'] ) ? sanitize_email( (string) $input['notify_email'] ) : '';
		$new['notify_email'] = is_email( $email ) ? $email : '';

		return $new;
	}

	public static function save( array $stored ): void {
		update_option( self::OPTION, $stored, false );
	}

	private static function cleanHost( string $host ): string {
		$host = trim( $host );
		$host = preg_replace( '#^s?ftp://#i', '', $host );
		return preg_replace( '/[^A-Za-z0-9.\-:\[\]]/', '', $host );
	}
}
