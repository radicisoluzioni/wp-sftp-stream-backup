<?php
/**
 * One-click restore (rollback of this site) and migration codes/installers.
 *
 * Both modes generate a self-contained runtime (restore engine + repository clients)
 * that streams the backup directly from the repository: nothing is downloaded first.
 *
 * @package WPSSB
 */

namespace WPSSB\Restore;

use WPSSB\Files\Exclusions;
use WPSSB\Job\Replication;
use WPSSB\Repositories;
use WPSSB\Settings;

class OneClick {
	const DIR_PREFIX     = 'wpssb-restore-';
	const CODE_PREFIX    = 'WPSSB1.';
	const KDF_ITERATIONS = 200000;
	const MIN_PASSWORD   = 12;
	const STALE_AFTER    = 86400;

	const REMOTE_FILES = array( 'HostKeyException', 'NextcloudStorage', 'ObjectStorage', 'RemoteException', 'RemoteStorage', 'S3Storage', 'SegmentedStorage', 'SftpObjects', 'SftpStorage' );
	const REPO_FIELDS  = array( 'type', 'base', 'host', 'port', 'username', 'auth', 'password', 'private_key', 'passphrase', 'fingerprint', 'provider', 'endpoint', 'location', 'region', 'bucket', 'access_key', 'secret_key', 'session_token', 'path_style', 'url', 'path', 'public_url' );

	/* ------------------------------------------------------------------ */
	/* Shared helpers                                                     */
	/* ------------------------------------------------------------------ */

	public static function pluginDir(): string {
		return rtrim( str_replace( '\\', '/', defined( 'WPSSB_DIR' ) ? WPSSB_DIR : dirname( __DIR__, 2 ) ), '/' );
	}

	public static function requireCrypto(): void {
		if ( ! function_exists( 'openssl_encrypt' ) || ! in_array( 'aes-256-gcm', array_map( 'strtolower', openssl_get_cipher_methods() ), true ) ) {
			throw new \RuntimeException( 'The OpenSSL PHP extension with AES-256-GCM is required for one-click restore.' );
		}
	}

	public static function b64url( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	public static function unb64url( string $data ): ?string {
		$out = base64_decode( strtr( $data, '-_', '+/' ), true );
		return false === $out ? null : $out;
	}

	/** Resolves and validates the selected backup. @return array{id:string,repo:array,path:string} */
	public static function locate( string $repo_id, string $path ): array {
		$path = rtrim( trim( str_replace( '\\', '/', $path ) ), '/' );
		if ( '' === $path || false !== strpos( $path, '..' ) || preg_match( '/[\x00-\x1f]/', $path ) ) {
			throw new \RuntimeException( 'Invalid backup path.' );
		}
		$all = Repositories::get();
		if ( Replication::configured() ) {
			if ( ! isset( $all['items'][ $repo_id ] ) ) {
				throw new \RuntimeException( 'Unknown repository.' );
			}
			$candidate = $all['items'][ $repo_id ];
			if ( 'nextcloud' === $candidate['type'] && '' !== (string) $candidate['public_url'] ) {
				throw new \RuntimeException( 'A public Nextcloud File Drop is write-only: restore from a readable repository.' );
			}
			$found = null;
			foreach ( Repositories::listing( $repo_id ) as $row ) {
				if ( $row['path'] === $path ) {
					$found = $row;
				}
			}
			if ( ! $found ) {
				throw new \RuntimeException( 'This backup is not in the repository history.' );
			}
			if ( ! $found['complete'] ) {
				throw new \RuntimeException( 'This backup is not complete in the repository.' );
			}
		} else {
			$repo_id = 'legacy-sftp';
			$name    = basename( $path );
			if ( $path !== Settings::remoteBase() . '/' . $name || ! preg_match( '/\A[A-Za-z0-9._-]+\z/', $name ) ) {
				throw new \RuntimeException( 'Invalid backup path.' );
			}
		}
		$repo = $all['items'][ $repo_id ];
		if ( 'nextcloud' === $repo['type'] && '' !== (string) $repo['public_url'] ) {
			throw new \RuntimeException( 'A public Nextcloud File Drop is write-only: restore from a readable repository.' );
		}
		if ( ! in_array( $repo['type'], array( 'sftp', 's3', 'nextcloud' ), true ) ) {
			throw new \RuntimeException( 'Unsupported repository type.' );
		}
		return array( 'id' => $repo_id, 'repo' => $repo, 'path' => $path );
	}

	/** Downloads and validates the manifest of the selected backup. @return array{raw:string,data:array} */
	public static function fetchManifest( string $repo_id, string $path ): array {
		$storage = Repositories::storage( $repo_id );
		try {
			$raw = $storage->get( $path . '/manifest.json' );
		} finally {
			$storage->disconnect();
		}
		$data = null === $raw ? null : json_decode( $raw, true );
		if ( ! is_array( $data ) || ! in_array( $data['format'] ?? 1, array( 1, 2 ), true ) ) {
			throw new \RuntimeException( 'The backup manifest is missing or invalid.' );
		}
		if ( 1 === ( $data['format'] ?? 1 ) && 'sftp' !== Repositories::get()['items'][ $repo_id ]['type'] ) {
			throw new \RuntimeException( 'Legacy single-file backups can be streamed only from SFTP.' );
		}
		return array( 'raw' => $raw, 'data' => $data );
	}

	public static function repoConfig( array $repo ): array {
		$out               = array_intersect_key( $repo, array_flip( self::REPO_FIELDS ) );
		$out['public_url'] = '';
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Runtime                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Files of the restore runtime, relative path => contents.
	 *
	 * @param bool $vendor Include the bundled phpseclib (needed for SFTP only).
	 */
	public static function runtimeFiles( bool $vendor ): array {
		$dir   = self::pluginDir();
		$files = array(
			'run.php'    => self::read( $dir . '/restore/runtime.php' ),
			'engine.php' => "<?php if ( ! defined( 'WPSSB_RESTORE_LIBRARY_ONLY' ) ) { http_response_code( 404 ); exit; } ?>" . self::read( $dir . '/restore/restore.php' ),
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n<Files \"run.php\">\nRequire all granted\n</Files>\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n<Files \"run.php\">\nAllow from all\n</Files>\n</IfModule>\n",
		);
		if ( is_readable( $dir . '/assets/radici-logo.png' ) ) {
			$files['logo.png'] = self::read( $dir . '/assets/radici-logo.png' );
		}
		foreach ( self::REMOTE_FILES as $class ) {
			$files[ 'src/Remote/' . $class . '.php' ] = self::read( $dir . '/src/Remote/' . $class . '.php' );
		}
		if ( $vendor && is_dir( $dir . '/vendor-prefixed' ) ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir . '/vendor-prefixed', \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				if ( $file->isFile() ) {
					$rel           = substr( str_replace( '\\', '/', $file->getPathname() ), strlen( $dir ) + 1 );
					$files[ $rel ] = self::read( $file->getPathname() );
				}
			}
		}
		return $files;
	}

	private static function read( string $file ): string {
		$data = is_readable( $file ) ? file_get_contents( $file ) : false;
		if ( false === $data ) {
			throw new \RuntimeException( 'Missing plugin file: ' . basename( $file ) );
		}
		return $data;
	}

	/**
	 * Builds the configuration of a runtime. The token is needed both to use the runtime
	 * and to decrypt the secret part; only its hash is stored.
	 */
	public static function configFile( array $plain, array $secret, string $token ): string {
		self::requireCrypto();
		$salt = bin2hex( random_bytes( 16 ) );
		$iv   = random_bytes( 12 );
		$tag  = '';
		$json = json_encode( $secret, JSON_UNESCAPED_SLASHES );
		$ct   = false === $json ? false : openssl_encrypt( $json, 'aes-256-gcm', hash_hmac( 'sha256', 'wpssb-runtime-config|' . $salt, $token, true ), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $ct ) {
			throw new \RuntimeException( 'Cannot encrypt the restore configuration.' );
		}
		$plain = array_merge(
			$plain,
			array(
				'v'          => 1,
				'token_hash' => hash( 'sha256', $token ),
				'salt'       => $salt,
				'iv'         => base64_encode( $iv ),
				'tag'        => base64_encode( $tag ),
				'ct'         => base64_encode( $ct ),
			)
		);
		return "<?php exit; ?>\n" . json_encode( $plain, JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Creates a runtime directory below $root.
	 *
	 * @param array $cfg    mode, done_url, cleanup.
	 * @param array $secret repo, path, manifest_sha256, opts.
	 * @return array{dir:string,token:string}
	 */
	public static function createRuntime( string $root, array $cfg, array $secret, array $files ): array {
		$root                   = rtrim( str_replace( '\\', '/', $root ), '/' );
		$name                   = self::DIR_PREFIX . bin2hex( random_bytes( 8 ) );
		$dir                    = $root . '/' . $name;
		$token                  = bin2hex( random_bytes( 32 ) );
		$secret['opts']['keep'] = array_values( array_unique( array_merge( (array) ( $secret['opts']['keep'] ?? array() ), array( $name ) ) ) );
		$plain                  = array(
			'mode'     => $cfg['mode'],
			'root'     => $root,
			'manifest' => 'manifest.json',
			'cleanup'  => array_values( (array) ( $cfg['cleanup'] ?? array() ) ),
			'done_url' => (string) ( $cfg['done_url'] ?? '' ),
			'insecure' => defined( 'WPSSB_ALLOW_INSECURE_REMOTE' ) && true === WPSSB_ALLOW_INSECURE_REMOTE,
		);
		$files['config.php']    = self::configFile( $plain, $secret, $token );
		if ( ! @mkdir( $dir, 0755 ) ) {
			throw new \RuntimeException( 'Cannot create the restore directory in ' . $root . ' (not writable?).' );
		}
		try {
			foreach ( $files as $rel => $data ) {
				$target = $dir . '/' . $rel;
				if ( ! is_dir( dirname( $target ) ) && ! @mkdir( dirname( $target ), 0755, true ) ) {
					throw new \RuntimeException( 'Cannot create ' . dirname( $rel ) );
				}
				if ( false === @file_put_contents( $target, $data ) ) {
					throw new \RuntimeException( 'Cannot write ' . $rel );
				}
			}
			@chmod( $dir . '/config.php', 0600 );
		} catch ( \Throwable $e ) {
			self::removeDir( $dir );
			throw $e;
		}
		return array( 'dir' => $name, 'token' => $token );
	}

	public static function removeDir( string $dir ): void {
		if ( is_link( $dir ) || ! is_dir( $dir ) ) {
			@unlink( $dir );
			return;
		}
		foreach ( (array) @scandir( $dir ) as $name ) {
			if ( '.' !== $name && '..' !== $name && '' !== (string) $name ) {
				self::removeDir( $dir . '/' . $name );
			}
		}
		@rmdir( $dir );
	}

	/**
	 * Removes stale or never-started runtimes; refuses when a restore is in progress.
	 */
	public static function sweepRuntimes( string $root, int $now ): void {
		foreach ( (array) glob( rtrim( $root, '/' ) . '/' . self::DIR_PREFIX . '*', GLOB_ONLYDIR ) as $dir ) {
			if ( ! preg_match( '/\A' . preg_quote( self::DIR_PREFIX, '/' ) . '[0-9a-f]{16}\z/', basename( (string) $dir ) ) ) {
				continue;
			}
			$started = is_file( $dir . '/state.php' );
			$mtime   = (int) @filemtime( $started ? $dir . '/state.php' : $dir . '/config.php' );
			if ( $started && $now - $mtime < self::STALE_AFTER ) {
				throw new \RuntimeException( 'Another restore is in progress (' . basename( (string) $dir ) . '): complete or abort it first.' );
			}
			self::removeDir( (string) $dir );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Rollback of this site                                              */
	/* ------------------------------------------------------------------ */

	public static function expectedConfirmation(): string {
		return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Raw rows of the plugin options that a rollback must keep (current settings,
	 * repositories and the history of backups newer than the restored one).
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private static function pluginOptions(): array {
		global $wpdb;
		$out = array();
		foreach ( array( Settings::OPTION, Repositories::OPTION, \WPSSB\Job\State::HISTORY ) as $name ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_N );
			if ( $row ) {
				$out[ $name ] = array( (string) $row[0], (string) $row[1] );
			}
		}
		return $out;
	}

	/**
	 * Prepares an in-place restore of this site.
	 *
	 * @return array{url:string,dir:string,token:string} Runtime URL with the token in the fragment.
	 */
	public static function prepareRollback( string $repo_id, string $path, string $confirm ): array {
		global $wpdb;
		if ( Repositories::locked() ) {
			throw new \RuntimeException( 'A backup is running or has pending replicas: wait for it to finish first.' );
		}
		if ( strtolower( trim( $confirm ) ) !== self::expectedConfirmation() ) {
			throw new \RuntimeException( 'Type the site domain exactly to confirm the restore.' );
		}
		self::requireCrypto();
		$loc      = self::locate( $repo_id, $path );
		$manifest = self::fetchManifest( $loc['id'], $loc['path'] );
		if ( (string) ( $manifest['data']['table_prefix'] ?? '' ) !== $wpdb->prefix ) {
			throw new \RuntimeException( 'The backup uses a different table prefix: restore it with a migration code instead.' );
		}
		$root = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
		self::sweepRuntimes( $root, time() );

		$settings = Settings::get();
		$exclude  = array_values( array_unique( array_merge( (array) ( $manifest['data']['exclude_paths'] ?? array() ), Exclusions::fromText( (string) $settings['exclude_paths'] )->patterns() ) ) );
		$tables   = array_values( array_unique( array_merge( (array) ( $manifest['data']['exclude_tables'] ?? array() ), (array) $settings['exclude_tables'] ) ) );
		$secret   = array(
			'repo'            => self::repoConfig( $loc['repo'] ),
			'path'            => $loc['path'],
			'manifest_sha256' => hash( 'sha256', $manifest['raw'] ),
			'opts'            => array(
				'url'            => home_url(),
				'db_host'        => DB_HOST,
				'db_name'        => DB_NAME,
				'db_user'        => DB_USER,
				'db_pass'        => DB_PASSWORD,
				'table_prefix'   => $wpdb->prefix,
				'keep'           => array( 'wp-config.php', '.maintenance' ),
				'keep_config'    => true,
				'exclude'        => $exclude,
				'exclude_tables' => $tables,
				'preserve_options' => self::pluginOptions(),
			),
		);
		$runtime  = self::createRuntime(
			$root,
			array(
				'mode'     => 'rollback',
				'done_url' => \WPSSB\Admin\AdminPage::url(),
			),
			$secret,
			self::runtimeFiles( 'sftp' === $loc['repo']['type'] )
		);
		$runtime['url'] = site_url( '/' . $runtime['dir'] . '/run.php' ) . '#' . $runtime['token'];
		return $runtime;
	}

	/* ------------------------------------------------------------------ */
	/* Migration                                                          */
	/* ------------------------------------------------------------------ */

	public static function encodeCode( array $payload, string $password ): string {
		self::requireCrypto();
		if ( strlen( $password ) < self::MIN_PASSWORD ) {
			throw new \RuntimeException( sprintf( 'The migration password must be at least %d characters long.', self::MIN_PASSWORD ) );
		}
		$salt = random_bytes( 16 );
		$iv   = random_bytes( 12 );
		$tag  = '';
		$key  = hash_pbkdf2( 'sha256', $password, $salt, self::KDF_ITERATIONS, 32, true );
		$ct   = openssl_encrypt( (string) json_encode( $payload, JSON_UNESCAPED_SLASHES ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, self::CODE_PREFIX );
		if ( false === $ct ) {
			throw new \RuntimeException( 'Cannot encrypt the migration code.' );
		}
		return self::CODE_PREFIX . self::b64url( $salt . $iv . $tag . $ct );
	}

	/** Same format as wi_decode_code() in the installer. */
	public static function decodeCode( string $code, string $password ): array {
		$code = (string) preg_replace( '/\s+/', '', $code );
		if ( 0 !== strpos( $code, self::CODE_PREFIX ) ) {
			throw new \RuntimeException( 'This is not a WP SFTP Stream Backup migration code.' );
		}
		$raw = self::unb64url( substr( $code, strlen( self::CODE_PREFIX ) ) );
		if ( null === $raw || strlen( $raw ) < 45 ) {
			throw new \RuntimeException( 'The migration code is damaged.' );
		}
		$key   = hash_pbkdf2( 'sha256', $password, substr( $raw, 0, 16 ), self::KDF_ITERATIONS, 32, true );
		$plain = openssl_decrypt( substr( $raw, 44 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 16, 12 ), substr( $raw, 28, 16 ), self::CODE_PREFIX );
		$data  = false === $plain ? null : json_decode( $plain, true );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Wrong password or damaged migration code.' );
		}
		return $data;
	}

	/** @return array{code:string,expires:int,backup:string} */
	public static function migrationCode( string $repo_id, string $path, string $password, int $days ): array {
		$loc      = self::locate( $repo_id, $path );
		$manifest = self::fetchManifest( $loc['id'], $loc['path'] );
		$expires  = time() + max( 1, min( 30, $days ) ) * 86400;
		$backup   = (string) ( $manifest['data']['id'] ?? basename( $loc['path'] ) );
		$code     = self::encodeCode(
			array(
				'v'               => 1,
				'repo'            => self::repoConfig( $loc['repo'] ),
				'path'            => $loc['path'],
				'manifest_sha256' => hash( 'sha256', $manifest['raw'] ),
				'expires'         => $expires,
				'site'            => home_url(),
				'backup'          => $backup,
				'insecure'        => defined( 'WPSSB_ALLOW_INSECURE_REMOTE' ) && true === WPSSB_ALLOW_INSECURE_REMOTE,
			),
			$password
		);
		return array(
			'code'    => $code,
			'expires' => $expires,
			'backup'  => $backup,
		);
	}

	/**
	 * Source of the standalone installer, optionally with an embedded migration code.
	 */
	public static function installerSource( string $code ): string {
		$template = self::read( self::pluginDir() . '/restore/installer.php' );
		$marker   = "__halt_compiler();";
		$pos      = strrpos( $template, $marker );
		if ( false === $pos ) {
			throw new \RuntimeException( 'Invalid installer template.' );
		}
		$files = array();
		foreach ( self::runtimeFiles( true ) as $rel => $data ) {
			$files[ $rel ] = base64_encode( $data );
		}
		$bundle = gzcompress( (string) json_encode( array( 'code' => $code, 'files' => $files ), JSON_UNESCAPED_SLASHES ), 9 );
		return substr( $template, 0, $pos + strlen( $marker ) ) . $bundle;
	}
}
