<?php
/**
 * Symmetric encryption for credentials stored in the database.
 *
 * Uses libsodium (bundled with PHP >= 7.2, polyfilled by WordPress through
 * sodium_compat). The key is derived from WPSSB_ENCRYPTION_KEY when defined,
 * otherwise from the secret keys in wp-config.php, so a database dump alone
 * does not reveal the SFTP credentials.
 *
 * @package WPSSB
 */

namespace WPSSB\Security;

class Secrets {

	const PREFIX = 'wpssb1:';

	private static function key(): string {
		if ( defined( 'WPSSB_ENCRYPTION_KEY' ) && '' !== (string) WPSSB_ENCRYPTION_KEY ) {
			$material = (string) WPSSB_ENCRYPTION_KEY;
		} else {
			$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . '|' .
				( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' ) . '|' .
				( defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : '' );
		}
		return hash( 'sha256', 'wpssb-secrets|' . $material, true );
	}

	public static function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plain, $nonce, self::key() );
		return self::PREFIX . base64_encode( $nonce . $cipher );
	}

	/**
	 * @return string|null Null when the value cannot be decrypted (e.g. the salts changed).
	 */
	public static function decrypt( string $stored ): ?string {
		if ( '' === $stored ) {
			return '';
		}
		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		try {
			$plain = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
		} catch ( \Throwable $e ) {
			return null;
		}
		return false === $plain ? null : $plain;
	}
}
