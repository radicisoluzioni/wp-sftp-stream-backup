<?php
namespace WPSSB;

use WPSSB\Remote\NextcloudStorage;
use WPSSB\Remote\ObjectStorage;
use WPSSB\Remote\S3Storage;
use WPSSB\Remote\SftpObjects;
use WPSSB\Remote\SftpStorage;
use WPSSB\Security\Secrets;

class Repositories {
	const OPTION = 'wpssb_repositories';
	const SECRETS = array( 'password', 'private_key', 'passphrase', 'secret_key', 'session_token', 'public_url', 'public_password' );

	public static function blank( string $type = 'sftp' ): array {
		return array(
			'name' => 'New repository', 'type' => $type, 'enabled' => false, 'retention' => 7,
			'base' => 'wp-backups', 'host' => '', 'port' => 22, 'username' => '', 'auth' => 'password',
			'password' => '', 'private_key' => '', 'passphrase' => '', 'fingerprint' => '',
			'provider' => 'custom', 'endpoint' => '', 'location' => '', 'region' => '', 'bucket' => '',
			'access_key' => '', 'secret_key' => '', 'session_token' => '', 'path_style' => true,
			'url' => '', 'path' => '', 'public_url' => '', 'public_password' => '',
		);
	}

	public static function raw(): array {
		$stored = get_option( self::OPTION, null );
		if ( is_array( $stored ) ) {
			return $stored;
		}
		$old = Settings::raw();
		$item = array_merge( self::blank(), array_intersect_key( $old, self::blank() ) );
		$item['name'] = 'SFTP';
		$item['base'] = $old['remote_path'];
		$item['enabled'] = '' !== Settings::get()['host'];
		return array( 'primary' => 'legacy-sftp', 'items' => array( 'legacy-sftp' => $item ) );
	}

	public static function get(): array {
		$all = self::raw();
		foreach ( $all['items'] as $id => &$item ) {
			$item = array_merge( self::blank(), $item );
			foreach ( self::SECRETS as $key ) {
				$plain = Secrets::decrypt( (string) $item[ $key ] );
				$overridden = 'legacy-sftp' === $id && Settings::isConstant( $key );
				if ( 'legacy-sftp' === $id && 'private_key' === $key && defined( 'WPSSB_SFTP_PRIVATE_KEY_FILE' ) && is_readable( WPSSB_SFTP_PRIVATE_KEY_FILE ) ) {
					$overridden = true;
				}
				if ( defined( 'WPSSB_REPOSITORIES' ) && is_array( WPSSB_REPOSITORIES ) && isset( WPSSB_REPOSITORIES[ $id ] ) && is_array( WPSSB_REPOSITORIES[ $id ] ) && array_key_exists( $key, WPSSB_REPOSITORIES[ $id ] ) ) {
					$overridden = true;
				}
				if ( ! $overridden && null === $plain && '' !== $item[ $key ] && $item['enabled'] ) {
					throw new \RuntimeException( 'Repository credentials cannot be decrypted: ' . $item['name'] );
				}
				$item[ $key ] = null === $plain ? '' : $plain;
			}
			if ( 'legacy-sftp' === $id ) {
				$legacy = Settings::get();
				foreach ( Settings::CONSTANTS as $key => $constant ) {
					if ( Settings::isConstant( $key ) ) {
						$item[ 'remote_path' === $key ? 'base' : $key ] = $legacy[ $key ];
					}
				}
				if ( defined( 'WPSSB_SFTP_PRIVATE_KEY_FILE' ) ) {
					$item['private_key'] = $legacy['private_key'];
				}
			}
		}
		unset( $item );
		if ( defined( 'WPSSB_REPOSITORIES' ) ) {
			$overrides = WPSSB_REPOSITORIES;
			if ( ! is_array( $overrides ) ) {
				throw new \RuntimeException( 'WPSSB_REPOSITORIES must be an array keyed by repository ID.' );
			}
			foreach ( $overrides as $id => $values ) {
				$all['items'][ $id ] = array_merge( self::blank(), $all['items'][ $id ] ?? array(), $values );
			}
		}
		foreach ( $all['items'] as &$item ) {
			$location = $item['location'];
			if ( '' === $item['endpoint'] && preg_match( '/^[a-z0-9-]+$/D', $location ) ) {
				$templates = array(
					'amazon' => 'https://s3.%s.amazonaws.com',
					'hetzner' => 'https://%s.your-objectstorage.com',
					'digitalocean' => 'https://%s.digitaloceanspaces.com',
				);
				if ( isset( $templates[ $item['provider'] ] ) ) {
					$item['endpoint'] = sprintf( $templates[ $item['provider'] ], $location );
				}
			}
			if ( '' === $item['region'] && '' !== $location ) {
				$item['region'] = 'digitalocean' === $item['provider'] ? 'us-east-1' : $location;
			}
		}
		unset( $item );
		return $all;
	}

	public static function active(): array {
		$all = self::get();
		return array_filter( $all['items'], function ( $item ) { return ! empty( $item['enabled'] ); } );
	}

	public static function validate( array $r ): void {
		if ( 'sftp' === $r['type'] ) {
			if ( '' === $r['host'] || '' === $r['username'] || '' === $r['fingerprint'] ) {
				throw new \RuntimeException( 'SFTP requires host, username and a verified fingerprint.' );
			}
		} elseif ( 's3' === $r['type'] ) {
			foreach ( array( 'endpoint', 'region', 'bucket', 'access_key', 'secret_key' ) as $field ) {
				if ( '' === $r[ $field ] ) {
					throw new \RuntimeException( 'Missing S3 field: ' . $field );
				}
			}
		} elseif ( 'nextcloud' === $r['type'] ) {
			if ( '' === $r['public_url'] && ( '' === $r['url'] || '' === $r['username'] || '' === $r['password'] ) ) {
				throw new \RuntimeException( 'Nextcloud requires an instance URL, username and app password, or a public share link.' );
			}
		} else {
			throw new \RuntimeException( 'Unknown repository type.' );
		}
		if ( '' === trim( $r['base'], '/' ) || false !== strpos( $r['base'], '..' ) ) {
			throw new \RuntimeException( 'A nonempty repository prefix without ".." is required.' );
		}
	}

	public static function storage( string $id ): ObjectStorage {
		$all = self::get();
		if ( ! isset( $all['items'][ $id ] ) ) {
			throw new \RuntimeException( 'Repository configuration is missing: ' . $id );
		}
		$r = $all['items'][ $id ];
		$state = \WPSSB\Job\State::load();
		$expected = $state['repositories'][ $id ]['revision'] ?? '';
		if ( $state && in_array( $state['status'], array( 'running', 'partial' ), true ) && '' !== $expected && ! hash_equals( $expected, self::revision( $r ) ) ) {
			throw new \RuntimeException( 'Repository configuration changed during the backup: ' . $id );
		}
		$custom = apply_filters( 'wpssb_repository_storage', null, $id, $r );
		if ( null !== $custom ) {
			if ( ! $custom instanceof ObjectStorage ) {
				throw new \RuntimeException( 'Repository storage filter must return ObjectStorage or null.' );
			}
			return $custom;
		}
		if ( 's3' === $r['type'] ) {
			return new S3Storage( $r );
		}
		if ( 'nextcloud' === $r['type'] ) {
			return new NextcloudStorage( $r );
		}
		return new SftpObjects( new SftpStorage( Settings::connection( $r ) ) );
	}

	public static function revision( array $config ): string {
		unset( $config['name'], $config['enabled'], $config['retention'] );
		return hash( 'sha256', serialize( $config ) );
	}

	public static function locked(): bool {
		$state = \WPSSB\Job\State::load();
		return \WPSSB\Job\Lock::isLocked() || ( $state && ( 'running' === $state['status'] || ( 'partial' === $state['status'] && ! empty( $state['repositories'] ) ) ) );
	}

	public static function test( string $id, bool $allow_public_probe = false ): string {
		$all = self::get();
		if ( ! isset( $all['items'][ $id ] ) ) {
			throw new \RuntimeException( 'Unknown repository.' );
		}

		$r = $all['items'][ $id ];
		$storage = self::storage( $id );
		try {
			$caps = $storage->capabilities();
			if ( ! $caps['read'] && ! $allow_public_probe ) {
				throw new \RuntimeException( 'Public File Drop test requires consent to leave a permanent small probe file.' );
			}
			$name = 'wpssb-probe-' . bin2hex( random_bytes( 8 ) );
			$key = $caps['read'] ? rtrim( $r['base'], '/' ) . '/' . $name : $name;
			$storage->put( $key, 'WPSSB connection test' );
			if ( $caps['read'] && 'WPSSB connection test' !== $storage->get( $key ) ) {
				throw new \RuntimeException( 'Connection test read-back mismatch.' );
			}
			if ( $caps['delete'] ) {
				$storage->remove( $key );
			}
			return $caps['read'] ? 'Connection and read/write/delete test succeeded.' : 'Upload accepted, not readable or verifiable; probe must be deleted by the owner.';
		} finally {
			$storage->disconnect();
		}
	}

	/** Local history, with manifest existence checked where read access is available. */
	public static function listing( string $id ): array {
		$all = self::get();
		if ( ! isset( $all['items'][ $id ] ) ) {
			throw new \RuntimeException( 'Unknown repository.' );
		}
		$storage = self::storage( $id );
		try {
			$rows = array();
			foreach ( \WPSSB\Job\State::history() as $backup ) {
				if ( ! preg_match( \WPSSB\Job\Retention::sitePattern( \WPSSB\Job\Runner::siteSlug() ), $backup['id'] ) ) {
					continue;
				}
				$r = $backup['repositories'][ $id ] ?? null;
				if ( ! $r ) {
					continue;
				}
				$complete = in_array( $r['status'], array( 'verified', 'accepted' ), true );
				$status = $r['status'];
				if ( ! $r['public'] && $complete ) {
					$complete = null !== $storage->stat( $r['base'] . '/' . $backup['id'] . '/manifest.json' );
					if ( ! $complete ) {
						$status = 'missing';
					}
				}
				$rows[] = array(
					'name' => $backup['id'], 'date' => gmdate( 'Y-m-d H:i:s', $backup['started_at'] ),
					'complete' => $complete, 'status' => $status,
					'path' => $r['public'] ? $backup['id'] . '--' . $r['generation'] . '--' : $r['base'] . '/' . $backup['id'],
					'db' => size_format( $backup['db_bytes'] ), 'files' => size_format( $backup['files_bytes'] ),
					'format' => (int) ( $backup['format'] ?? 1 ),
				);
			}
			return $rows;
		} finally {
			$storage->disconnect();
		}
	}

	public static function save( array $input ): void {
		if ( self::locked() ) {
			throw new \RuntimeException( 'Finish, retry or abandon the current backup before editing repositories.' );
		}
		$old = self::raw();
		$new = array( 'primary' => sanitize_key( $input['primary'] ?? '' ), 'items' => array() );
		foreach ( (array) ( $input['items'] ?? array() ) as $id => $values ) {
			if ( ! isset( $old['items'][ $id ] ) || ! empty( $values['remove'] ) ) {
				continue;
			}
			$item = array_merge( self::blank(), $old['items'][ $id ] );
			foreach ( self::blank() as $key => $default ) {
				if ( in_array( $key, self::SECRETS, true ) ) {
					if ( ! empty( $values[ 'clear_' . $key ] ) ) {
						$item[ $key ] = '';
					} elseif ( isset( $values[ $key ] ) && '' !== $values[ $key ] ) {
						$item[ $key ] = Secrets::encrypt( (string) $values[ $key ] );
					}
				} elseif ( is_bool( $default ) ) {
					$item[ $key ] = ! empty( $values[ $key ] );
				} elseif ( is_int( $default ) ) {
					$item[ $key ] = max( 0, (int) ( $values[ $key ] ?? $default ) );
				} else {
					$item[ $key ] = sanitize_text_field( (string) ( $values[ $key ] ?? $item[ $key ] ) );
				}
			}
			$item['base'] = rtrim( str_replace( '\\', '/', $item['base'] ), '/' );
			$item['retention'] = min( 1000, $item['retention'] );
			if ( ! in_array( $item['type'], array( 'sftp', 's3', 'nextcloud' ), true ) ) {
				throw new \RuntimeException( 'Unsupported repository type.' );
			}
			$new['items'][ $id ] = $item;
		}
		if ( ! empty( $input['add_type'] ) ) {
			$type = (string) $input['add_type'];
			if ( ! in_array( $type, array( 'sftp', 's3', 'nextcloud' ), true ) ) {
				throw new \RuntimeException( 'Unsupported repository type.' );
			}
			$new['items'][ 'repo-' . bin2hex( random_bytes( 6 ) ) ] = self::blank( $type );
		}
		if ( isset( $new['items'][ $new['primary'] ] ) && 'nextcloud' === $new['items'][ $new['primary'] ]['type'] && '' !== $new['items'][ $new['primary'] ]['public_url'] ) {
			throw new \RuntimeException( 'A public File Drop share cannot be the primary repository.' );
		}
		$enabled = array_filter( $new['items'], function ( $r ) { return $r['enabled']; } );
		if ( $enabled && ! isset( $enabled[ $new['primary'] ] ) ) {
			throw new \RuntimeException( 'Choose an enabled primary repository.' );
		}
		update_option( self::OPTION, $new, false );
	}
}
