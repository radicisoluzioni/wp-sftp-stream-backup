<?php
/**
 * SFTP implementation of RemoteStorage based on (prefixed) phpseclib 3.
 *
 * @package WPSSB
 */

namespace WPSSB\Remote;

use WPSSB\Vendor\phpseclib3\Crypt\PublicKeyLoader;
use WPSSB\Vendor\phpseclib3\Net\SFTP;

class SftpStorage implements RemoteStorage {

	/** @var array */
	private $cfg;

	/** @var SFTP|null */
	private $sftp;

	/**
	 * @param array $cfg host, port, username, auth (password|key), password, private_key, passphrase, fingerprint, timeout.
	 */
	public function __construct( array $cfg ) {
		$this->cfg = array_merge(
			array(
				'host'        => '',
				'port'        => 22,
				'username'    => '',
				'auth'        => 'password',
				'password'    => '',
				'private_key' => '',
				'passphrase'  => '',
				'fingerprint' => '',
				'timeout'     => 30,
			),
			$cfg
		);
	}

	/**
	 * OpenSSH style fingerprint ("SHA256:...") of a "type base64" host key.
	 */
	public static function fingerprint( string $host_key ): string {
		$parts = explode( ' ', trim( $host_key ) );
		$blob  = base64_decode( isset( $parts[1] ) ? $parts[1] : $parts[0], true );
		if ( false === $blob ) {
			$blob = $host_key;
		}
		return 'SHA256:' . rtrim( base64_encode( hash( 'sha256', $blob, true ) ), '=' );
	}

	public static function normalizeFingerprint( string $fp ): string {
		$fp = trim( $fp );
		if ( '' === $fp ) {
			return '';
		}
		if ( 0 === stripos( $fp, 'SHA256:' ) ) {
			$fp = substr( $fp, 7 );
		}
		return 'SHA256:' . rtrim( $fp, '=' );
	}

	/**
	 * Connects, verifies the host key and authenticates.
	 *
	 * @return string The server fingerprint.
	 * @throws HostKeyException When no fingerprint is configured or it does not match (no credentials are sent).
	 * @throws RemoteException  On connection/authentication failures.
	 */
	public function connect(): string {
		if ( $this->sftp ) {
			return self::normalizeFingerprint( $this->cfg['fingerprint'] );
		}
		if ( '' === trim( (string) $this->cfg['host'] ) ) {
			throw new RemoteException( 'SFTP host is not configured.' );
		}

		try {
			$sftp = new SFTP( $this->cfg['host'], (int) $this->cfg['port'], (int) $this->cfg['timeout'] );
			$key  = $sftp->getServerPublicHostKey();
		} catch ( \Throwable $e ) {
			throw new RemoteException( 'Cannot connect to ' . $this->cfg['host'] . ':' . $this->cfg['port'] . ' - ' . $e->getMessage(), 0, $e );
		}
		if ( false === $key || ! is_string( $key ) ) {
			throw new RemoteException( 'Cannot connect to ' . $this->cfg['host'] . ':' . $this->cfg['port'] . ' (no host key received).' );
		}

		$actual   = self::fingerprint( $key );
		$expected = self::normalizeFingerprint( (string) $this->cfg['fingerprint'] );
		if ( '' === $expected ) {
			throw new HostKeyException( $actual, 'The server host key fingerprint has not been confirmed yet.' );
		}
		if ( ! hash_equals( $expected, $actual ) ) {
			throw new HostKeyException( $actual, sprintf( 'Host key fingerprint mismatch! Expected %s, got %s. Possible man-in-the-middle attack.', $expected, $actual ) );
		}

		try {
			if ( 'key' === $this->cfg['auth'] ) {
				$pass   = '' !== (string) $this->cfg['passphrase'] ? (string) $this->cfg['passphrase'] : false;
				$secret = PublicKeyLoader::load( (string) $this->cfg['private_key'], $pass );
			} else {
				$secret = (string) $this->cfg['password'];
			}
			$ok = $sftp->login( (string) $this->cfg['username'], $secret );
		} catch ( \Throwable $e ) {
			throw new RemoteException( 'SFTP authentication error: ' . $e->getMessage(), 0, $e );
		}
		if ( ! $ok ) {
			throw new RemoteException( 'SFTP authentication failed for user "' . $this->cfg['username'] . '".' );
		}

		$sftp->disableStatCache();
		$this->sftp = $sftp;
		return $actual;
	}

	private function client(): SFTP {
		if ( ! $this->sftp ) {
			$this->connect();
		}
		return $this->sftp;
	}

	private function fail( string $what ): RemoteException {
		$detail = $this->sftp ? (string) $this->sftp->getLastSFTPError() : '';
		return new RemoteException( $what . ( '' !== $detail ? ' (' . $detail . ')' : '' ) );
	}

	public function mkdir( string $path ): void {
		$sftp = $this->client();
		if ( $sftp->is_dir( $path ) ) {
			return;
		}
		if ( ! $sftp->mkdir( $path, -1, true ) && ! $sftp->is_dir( $path ) ) {
			throw $this->fail( 'Cannot create remote directory ' . $path );
		}
	}

	public function size( string $path ): ?int {
		$stat = $this->client()->stat( $path );
		if ( false === $stat || ! isset( $stat['size'] ) ) {
			return null;
		}
		return (int) $stat['size'];
	}

	public function truncate( string $path, int $size ): void {
		if ( ! $this->client()->truncate( $path, $size ) ) {
			throw $this->fail( 'Cannot truncate remote file ' . $path );
		}
	}

	public function writeAt( string $path, string $data, int $offset ): void {
		if ( ! $this->client()->put( $path, $data, SFTP::SOURCE_STRING, $offset ) ) {
			throw $this->fail( 'Cannot write remote file ' . $path );
		}
	}

	public function putContents( string $path, string $data ): void {
		if ( ! $this->client()->put( $path, $data, SFTP::SOURCE_STRING ) ) {
			throw $this->fail( 'Cannot write remote file ' . $path );
		}
	}

	public function getContents( string $path ): ?string {
		$sftp = $this->client();
		if ( ! $sftp->is_file( $path ) ) {
			return null;
		}
		$data = $sftp->get( $path );
		return false === $data ? null : (string) $data;
	}

	/** Reads up to $length bytes from $offset (used to stream legacy single-file archives). */
	public function getRange( string $path, int $offset, int $length ): string {
		$data = $this->client()->get( $path, false, $offset, $length );
		if ( false === $data ) {
			throw $this->fail( 'Cannot read remote file ' . $path );
		}
		return (string) $data;
	}

	public function listDir( string $path ): array {
		$raw = $this->client()->rawlist( $path );
		if ( false === $raw ) {
			throw $this->fail( 'Cannot list remote directory ' . $path );
		}
		$out = array();
		foreach ( $raw as $name => $attr ) {
			$name = (string) $name;
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$type = 'other';
			if ( isset( $attr['type'] ) ) {
				if ( 2 === (int) $attr['type'] ) {
					$type = 'dir';
				} elseif ( 1 === (int) $attr['type'] ) {
					$type = 'file';
				}
			}
			$out[ $name ] = array(
				'type'  => $type,
				'size'  => isset( $attr['size'] ) ? (int) $attr['size'] : 0,
				'mtime' => isset( $attr['mtime'] ) ? (int) $attr['mtime'] : 0,
			);
		}
		return $out;
	}

	public function delete( string $path ): void {
		if ( ! $this->client()->delete( $path, true ) ) {
			throw $this->fail( 'Cannot delete remote path ' . $path );
		}
	}

	public function disconnect(): void {
		if ( $this->sftp ) {
			try {
				$this->sftp->disconnect();
			} catch ( \Throwable $e ) {
				// Ignore errors while closing.
				unset( $e );
			}
			$this->sftp = null;
		}
	}
}
