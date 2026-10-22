<?php
/**
 * WP SFTP Stream Backup — one-click restore runtime.
 *
 * A copy of this file is generated as run.php inside a random directory for every
 * one-click restore or migration. It streams the selected backup directly from the
 * repository (no local archive), runs the restore engine in short resumable steps
 * and deletes its whole directory when the restore is complete.
 *
 * Access requires the random token created together with this directory: the token
 * is never stored here (only its hash) and also decrypts the repository credentials.
 *
 * Coded by Radici Soluzioni Informatiche — https://radici.biz
 *
 * @package WPSSB
 */

namespace WPSSB\Job {
	if ( ! class_exists( __NAMESPACE__ . '\\Lock', false ) ) {
		/** No-op replacement of the plugin lock: the runtime has its own file lock. */
		class Lock {
			public static function heartbeat() {
			}
		}
	}
}

namespace {

	define( 'RT_DIR', str_replace( '\\', '/', __DIR__ ) );
	define( 'RT_CONFIG', RT_DIR . '/config.php' );
	define( 'RT_VERSION', 1 );

	/** Reads the plain part of the runtime configuration. */
	function rt_config() {
		if ( ! is_file( RT_CONFIG ) ) {
			return null;
		}
		$raw = (string) @file_get_contents( RT_CONFIG );
		$pos = strpos( $raw, "\n" );
		$cfg = json_decode( false === $pos ? '' : substr( $raw, $pos + 1 ), true );
		if ( ! is_array( $cfg ) || RT_VERSION !== ( isset( $cfg['v'] ) ? $cfg['v'] : 0 ) ) {
			return null;
		}
		return $cfg;
	}

	function rt_key( $token, $salt ) {
		return hash_hmac( 'sha256', 'wpssb-runtime-config|' . $salt, $token, true );
	}

	function rt_check_token( array $cfg, $token ) {
		return is_string( $token ) && 1 === preg_match( '/\A[0-9a-f]{64}\z/D', $token ) && hash_equals( (string) $cfg['token_hash'], hash( 'sha256', $token ) );
	}

	/** Decrypts the secret part (repository credentials, database password, options). */
	function rt_secret( array $cfg, $token ) {
		$plain = openssl_decrypt(
			(string) base64_decode( $cfg['ct'] ),
			'aes-256-gcm',
			rt_key( $token, $cfg['salt'] ),
			OPENSSL_RAW_DATA,
			(string) base64_decode( $cfg['iv'] ),
			(string) base64_decode( $cfg['tag'] )
		);
		$secret = false === $plain ? null : json_decode( $plain, true );
		if ( ! is_array( $secret ) ) {
			throw new RuntimeException( 'Cannot decrypt the restore configuration.' );
		}
		return $secret;
	}

	/** Minimal HTTP client returning WordPress-shaped responses for the S3 and Nextcloud clients. */
	function rt_http( $url, array $args ) {
		$method  = isset( $args['method'] ) ? strtoupper( (string) $args['method'] ) : 'GET';
		$body    = isset( $args['body'] ) ? (string) $args['body'] : '';
		$timeout = isset( $args['timeout'] ) ? max( 1, (int) $args['timeout'] ) : 60;
		$limit   = isset( $args['limit_response_size'] ) ? (int) $args['limit_response_size'] : 0;
		$verify  = ! isset( $args['sslverify'] ) || false !== $args['sslverify'];
		$lines   = array();
		foreach ( isset( $args['headers'] ) ? (array) $args['headers'] : array() as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}
		if ( function_exists( 'curl_init' ) ) {
			$attempt = 0;
			retry:
			$attempt++;
			$headers = array();
			$data    = '';
			$ch      = curl_init( $url );
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_CUSTOMREQUEST  => $method,
					CURLOPT_NOBODY         => 'HEAD' === $method,
					CURLOPT_HTTPHEADER     => array_merge( $lines, array( 'Expect:' ) ),
					CURLOPT_FOLLOWLOCATION => false,
					CURLOPT_CONNECTTIMEOUT => min( 30, $timeout ),
					CURLOPT_TIMEOUT        => $timeout,
					CURLOPT_SSL_VERIFYPEER => $verify,
					CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
					CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
					CURLOPT_HEADERFUNCTION => function ( $ch, $line ) use ( &$headers ) {
						if ( preg_match( '#^HTTP/\S+\s+\d+#', $line ) ) {
							$headers = array();
						} elseif ( false !== strpos( $line, ':' ) ) {
							list( $k, $v ) = explode( ':', $line, 2 );
							$headers[ strtolower( trim( $k ) ) ] = trim( $v );
						}
						return strlen( $line );
					},
					CURLOPT_WRITEFUNCTION  => function ( $ch, $chunk ) use ( &$data, $limit ) {
						if ( $limit > 0 && strlen( $data ) + strlen( $chunk ) > $limit ) {
							$chunk = substr( $chunk, 0, max( 0, $limit - strlen( $data ) ) );
							$data .= $chunk;
							return 0;
						}
						$data .= $chunk;
						return strlen( $chunk );
					},
				)
			);
			if ( '' !== $body || in_array( $method, array( 'PUT', 'POST' ), true ) ) {
				curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
			}
			$ok    = curl_exec( $ch );
			$code  = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
			$errno = curl_errno( $ch );
			curl_close( $ch );
			if ( false === $ok && ! ( CURLE_WRITE_ERROR === $errno && $limit > 0 && strlen( $data ) >= $limit ) ) {
				// Connection-level failures on idempotent requests are retried briefly.
				if ( $attempt < 3 && in_array( $method, array( 'GET', 'HEAD', 'PROPFIND' ), true ) && in_array( $errno, array( 6, 7, 28, 35, 52, 55, 56 ), true ) ) {
					usleep( 500000 * $attempt );
					goto retry;
				}
				throw new RuntimeException( 'HTTP request failed (cURL error ' . $errno . ').' );
			}
			return array( 'response' => array( 'code' => $code, 'message' => '' ), 'headers' => $headers, 'body' => $data );
		}
		$context = stream_context_create(
			array(
				'http' => array(
					'method'          => $method,
					'header'          => implode( "\r\n", $lines ),
					'content'         => $body,
					'timeout'         => $timeout,
					'ignore_errors'   => true,
					'follow_location' => 0,
					'protocol_version' => 1.1,
				),
				'ssl'  => array( 'verify_peer' => $verify, 'verify_peer_name' => $verify ),
			)
		);
		$fp = @fopen( $url, 'rb', false, $context );
		if ( ! $fp ) {
			throw new RuntimeException( 'HTTP request failed.' );
		}
		$meta = stream_get_meta_data( $fp );
		$data = (string) stream_get_contents( $fp, $limit > 0 ? $limit : -1 );
		fclose( $fp );
		$code    = 0;
		$headers = array();
		foreach ( isset( $meta['wrapper_data'] ) ? (array) $meta['wrapper_data'] : array() as $line ) {
			if ( preg_match( '#^HTTP/\S+\s+(\d+)#', $line, $m ) ) {
				$code    = (int) $m[1];
				$headers = array();
			} elseif ( false !== strpos( $line, ':' ) ) {
				list( $k, $v ) = explode( ':', $line, 2 );
				$headers[ strtolower( trim( $k ) ) ] = trim( $v );
			}
		}
		return array( 'response' => array( 'code' => $code, 'message' => '' ), 'headers' => $headers, 'body' => $data );
	}

	function rt_http_nextcloud( $method, $url, array $args ) {
		$args['method'] = $method;
		return rt_http( $url, $args );
	}

	/** Seekable reader over a remote SFTP file, used for legacy single-file archives. */
	class RtSftpSource {
		const READ_AHEAD = 1048576;

		private $sftp;
		private $path;
		private $length;
		private $pos = 0;
		private $buf = '';
		private $buf_at = -1;

		public function __construct( $sftp, $path ) {
			$this->sftp   = $sftp;
			$this->path   = $path;
			$size         = $sftp->size( $path );
			if ( null === $size ) {
				throw new RuntimeException( 'Missing backup archive: ' . basename( $path ) );
			}
			$this->length = (int) $size;
		}

		public function size() {
			return $this->length;
		}

		public function seek( $offset ) {
			if ( $offset < 0 || $offset > $this->length ) {
				throw new RuntimeException( 'Cannot seek in archive.' );
			}
			$this->pos = (int) $offset;
		}

		public function read( $length ) {
			if ( $this->pos >= $this->length ) {
				return '';
			}
			if ( $this->buf_at < 0 || $this->pos < $this->buf_at || $this->pos >= $this->buf_at + strlen( $this->buf ) ) {
				$this->buf    = '';
				$this->buf    = $this->sftp->getRange( $this->path, $this->pos, max( (int) $length, self::READ_AHEAD ) );
				$this->buf_at = $this->pos;
				if ( '' === $this->buf ) {
					throw new RuntimeException( 'Unexpected end of remote archive.' );
				}
			}
			$out        = (string) substr( $this->buf, $this->pos - $this->buf_at, $length );
			$this->pos += strlen( $out );
			return $out;
		}
	}

	/** Backup objects read directly from a repository folder, never written to disk. */
	class RtRemoteStore {
		const CACHE_LIMIT = 1048576;

		private $storage;
		private $sftp;
		private $base;
		private $cache = array();

		public function __construct( $storage, $base, $sftp = null ) {
			$this->storage = $storage;
			$this->base    = rtrim( (string) $base, '/' );
			$this->sftp    = $sftp;
		}

		public function local() {
			return false;
		}

		private function key( $name ) {
			return $this->base . '/' . $name;
		}

		public function exists( $name ) {
			return isset( $this->cache[ $name ] ) || null !== $this->storage->stat( $this->key( $name ) );
		}

		public function object( $name, $limit ) {
			if ( isset( $this->cache[ $name ] ) ) {
				$data = $this->cache[ $name ];
			} else {
				$data = $this->storage->get( $this->key( $name ) );
				if ( null === $data ) {
					throw new RuntimeException( 'Missing backup object in the repository: ' . $name );
				}
			}
			if ( strlen( $data ) > $limit ) {
				throw new RuntimeException( 'Oversized backup object: ' . $name );
			}
			if ( strlen( $data ) <= self::CACHE_LIMIT ) {
				if ( count( $this->cache ) >= 8 ) {
					array_shift( $this->cache );
				}
				$this->cache[ $name ] = $data;
			}
			return $data;
		}

		public function checkPart( $name, $size ) {
			// Remote parts are size- and SHA-256-checked when they are read.
		}

		public function source( $name ) {
			if ( ! $this->sftp ) {
				throw new RuntimeException( 'Legacy single-file backups can be streamed only from SFTP.' );
			}
			return new RtSftpSource( $this->sftp, $this->key( $name ) );
		}

		public function disconnect() {
			$this->storage->disconnect();
		}
	}

	function rt_bootstrap( array $cfg ) {
		define( 'RS_DIR', rtrim( str_replace( '\\', '/', (string) $cfg['root'] ), '/' ) );
		define( 'RS_STATE', RT_DIR . '/state.php' );
		define( 'RS_LOCK', RT_DIR . '/restore.lock' );
		define( 'RS_EXTRACTED', RT_DIR . '/extracted.log' );
		define( 'RS_MANIFEST', (string) $cfg['manifest'] );
		define( 'WPSSB_RESTORE_LIBRARY_ONLY', true );
		if ( ! empty( $cfg['insecure'] ) && ! defined( 'WPSSB_ALLOW_INSECURE_REMOTE' ) ) {
			define( 'WPSSB_ALLOW_INSECURE_REMOTE', true );
		}
		require RT_DIR . '/engine.php';
		spl_autoload_register(
			function ( $class ) {
				if ( 0 === strpos( $class, 'WPSSB\\Remote\\' ) ) {
					$file = RT_DIR . '/src/Remote/' . substr( $class, 13 ) . '.php';
					if ( preg_match( '/\A[A-Za-z][A-Za-z0-9]*\z/', substr( $class, 13 ) ) && is_file( $file ) ) {
						require $file;
					}
				}
			}
		);
		if ( is_file( RT_DIR . '/vendor-prefixed/autoload.php' ) ) {
			require_once RT_DIR . '/vendor-prefixed/autoload.php';
		}
	}

	/** Connects the remote store described by the decrypted configuration. */
	function rt_connect( array $secret ) {
		$r = (array) $secret['repo'];
		$sftp = null;
		if ( 's3' === $r['type'] ) {
			$storage = new \WPSSB\Remote\S3Storage( $r, 'rt_http' );
		} elseif ( 'nextcloud' === $r['type'] ) {
			if ( '' !== (string) ( isset( $r['public_url'] ) ? $r['public_url'] : '' ) ) {
				throw new RuntimeException( 'A public File Drop share is write-only: restore from a readable repository.' );
			}
			$storage = new \WPSSB\Remote\NextcloudStorage( $r, 'rt_http_nextcloud' );
		} elseif ( 'sftp' === $r['type'] ) {
			$sftp = new \WPSSB\Remote\SftpStorage(
				array(
					'host'        => (string) $r['host'],
					'port'        => (int) $r['port'],
					'username'    => (string) $r['username'],
					'auth'        => (string) $r['auth'],
					'password'    => (string) $r['password'],
					'private_key' => (string) $r['private_key'],
					'passphrase'  => (string) $r['passphrase'],
					'fingerprint' => (string) $r['fingerprint'],
					'timeout'     => 30,
				)
			);
			$storage = new \WPSSB\Remote\SftpObjects( $sftp );
		} else {
			throw new RuntimeException( 'Unsupported repository type.' );
		}
		$GLOBALS['rs_store'] = new RtRemoteStore( $storage, (string) $secret['path'], $sftp );
		return $GLOBALS['rs_store'];
	}

	function rt_maintenance( array $cfg, $on ) {
		if ( 'rollback' !== $cfg['mode'] ) {
			return;
		}
		$file = RS_DIR . '/.maintenance';
		if ( $on ) {
			@file_put_contents( $file, '<?php $upgrading = ' . time() . ';' );
		} elseif ( is_file( $file ) ) {
			@unlink( $file );
		}
	}

	function rt_rmdir( $dir ) {
		if ( is_link( $dir ) || ! is_dir( $dir ) ) {
			@unlink( $dir );
			return;
		}
		foreach ( (array) @scandir( $dir ) as $name ) {
			if ( '.' !== $name && '..' !== $name && '' !== (string) $name ) {
				rt_rmdir( $dir . '/' . $name );
			}
		}
		@rmdir( $dir );
	}

	/** Removes the runtime directory and the other one-shot files (installer). */
	function rt_remove( array $cfg ) {
		rt_maintenance( $cfg, false );
		@unlink( RT_CONFIG );
		foreach ( isset( $cfg['cleanup'] ) ? (array) $cfg['cleanup'] : array() as $rel ) {
			$rel = rs_safe_path( $rel );
			if ( null !== $rel && false === strpos( $rel, '/' ) ) {
				@unlink( RS_DIR . '/' . $rel );
			}
		}
		rt_rmdir( RT_DIR );
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset();
		}
	}

	function rt_new_state( array $cfg, array $secret ) {
		$manifest = rs_manifest();
		if ( ! $manifest ) {
			throw new RuntimeException( 'The backup manifest was not found in the repository.' );
		}
		if ( ! empty( $secret['manifest_sha256'] ) && ! hash_equals( (string) $secret['manifest_sha256'], hash( 'sha256', (string) rs_manifest_raw() ) ) ) {
			throw new RuntimeException( 'The backup manifest in the repository differs from the selected one.' );
		}
		$opts = (array) $secret['opts'];
		if ( 'rollback' === $cfg['mode'] ) {
			$opts['mode'] = 'rollback';
			if ( ! isset( $manifest['table_prefix'] ) || (string) $manifest['table_prefix'] !== (string) $opts['table_prefix'] ) {
				throw new RuntimeException( 'The backup uses a different table prefix: use a migration instead.' );
			}
			$opts['exclude'] = array_merge( (array) $opts['exclude'], isset( $manifest['exclude_paths'] ) ? (array) $manifest['exclude_paths'] : array() );
			$opts['exclude_tables'] = array_merge( (array) $opts['exclude_tables'], isset( $manifest['exclude_tables'] ) ? (array) $manifest['exclude_tables'] : array() );
		} elseif ( '' === (string) $opts['url'] && isset( $manifest['home_url'] ) ) {
			$opts['url'] = (string) $manifest['home_url'];
		}
		foreach ( rs_requirements() as $check ) {
			if ( ! $check[1] && ! ( 'rollback' === $cfg['mode'] && 'Free disk space' === $check[0] ) ) {
				throw new RuntimeException( 'Requirement not met: ' . $check[0] . ( '' !== $check[2] ? ' (' . $check[2] . ')' : '' ) );
			}
		}
		rs_db_connect( $opts['db_host'], $opts['db_user'], $opts['db_pass'], $opts['db_name'] )->close();
		@unlink( RS_EXTRACTED );
		$state = rs_new_state( $opts );
		rs_log( $state, 'Streaming backup ' . ( isset( $manifest['id'] ) ? $manifest['id'] : basename( (string) $secret['path'] ) ) . ' from the repository.' );
		return $state;
	}

	/**
	 * Runs one bounded step.
	 *
	 * @return array{state:array|null,busy:bool,done:bool}
	 */
	function rt_step( array $cfg, array $secret, $seconds ) {
		$lock = rs_lock();
		if ( ! $lock ) {
			return array( 'state' => rs_load_state(), 'busy' => true, 'done' => false );
		}
		$state = null;
		$store = null;
		try {
			$store = rt_connect( $secret );
			$state = rs_load_state();
			if ( ! $state ) {
				$state = rt_new_state( $cfg, $secret );
				rs_save_state( $state );
			}
			if ( 'done' !== $state['phase'] ) {
				rt_maintenance( $cfg, true );
				rs_step( $state, microtime( true ) + $seconds );
				rs_save_state( $state );
			}
			if ( 'done' === $state['phase'] ) {
				rs_unlock( $lock );
				$lock = null;
				rs_cleanup( $state, false );
				$store->disconnect();
				$store = null;
				rt_remove( $cfg );
				return array( 'state' => $state, 'busy' => false, 'done' => true );
			}
		} finally {
			if ( $store ) {
				$store->disconnect();
			}
			if ( $lock ) {
				rs_unlock( $lock );
			}
		}
		return array( 'state' => $state, 'busy' => false, 'done' => false );
	}

	function rt_summary( $state ) {
		if ( ! $state ) {
			return array( 'phase' => 'ready', 'progress' => 0, 'log' => array() );
		}
		return array(
			'phase'    => $state['phase'],
			'progress' => (int) $state['progress'],
			'log'      => array_slice( (array) $state['log'], -40 ),
		);
	}

	function rt_budget() {
		$max = (int) ini_get( 'max_execution_time' );
		return $max > 0 ? max( 5, min( 20, $max - 10 ) ) : 20;
	}

	function rt_json( array $data, $code = 200 ) {
		http_response_code( $code );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo json_encode( $data, JSON_UNESCAPED_SLASHES );
		exit;
	}

	function rt_h( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	}

	function rt_page( array $cfg ) {
		$rollback = 'rollback' === $cfg['mode'];
		$logo     = is_file( RT_DIR . '/logo.png' ) ? 'data:image/png;base64,' . base64_encode( (string) file_get_contents( RT_DIR . '/logo.png' ) ) : '';
		$title    = $rollback ? 'Restore this site from the repository' : 'Migrate the site from the repository';
		$done_url = (string) ( isset( $cfg['done_url'] ) ? $cfg['done_url'] : '' );
		header( 'Content-Type: text/html; charset=utf-8' );
		?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo rt_h( $title ); ?> — WP SFTP Stream Backup</title>
<style>
*{box-sizing:border-box}body{font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f1f3ee;color:#28332d;margin:0;padding:32px 16px}
main{max-width:820px;margin:0 auto;background:#fff;border:1px solid #dce3da;border-top:4px solid #c4d72f;border-radius:14px;box-shadow:0 8px 30px #1c2a1808;overflow:hidden}
header{display:flex;justify-content:space-between;align-items:center;gap:24px;padding:24px 32px;border-bottom:1px solid #dce3da}
.eyebrow{margin:0 0 4px;font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#59665e}header strong{font-size:17px}
header img{display:block;width:130px;height:auto}.content{padding:24px 32px}h1{font-size:24px;line-height:1.3;margin:0 0 16px}
a{color:#375526}button{background:#354c29;color:#fff;border:1px solid #354c29;border-radius:6px;padding:10px 20px;margin:16px 8px 0 0;cursor:pointer;font:inherit;font-weight:600}button:hover{background:#263d1c}
button[disabled]{opacity:.6;cursor:default}.secondary{background:#fff;color:#354c29}.secondary:hover{background:#eef3e7}
:is(a,button):focus-visible{outline:2px solid #354c29;outline-offset:3px}
.err,.note,.ok{padding:14px 16px;border-radius:6px;overflow-wrap:anywhere;margin:16px 0}.err{background:#fcecec;border-left:4px solid #a02323}.note{background:#f1f5e8;border-left:4px solid #859a20}.ok{background:#e8f3e1;border-left:4px solid #2e571d}
progress{display:block;width:100%;height:14px;accent-color:#354c29;margin:12px 0}#phase{font-weight:600}
pre{background:#f4f6f1;border:1px solid #dce3da;border-radius:8px;padding:14px;max-height:300px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;font-size:12px}
footer{padding:16px 32px;border-top:1px solid #dce3da;font-size:13px;color:#59665e}[hidden]{display:none!important}
</style></head><body><main>
<header><div><p class="eyebrow">WP SFTP Stream Backup</p><strong>One-click restore</strong></div><?php if ( $logo ) : ?><a href="https://radici.biz" target="_blank" rel="noopener"><img src="<?php echo rt_h( $logo ); ?>" alt="Radici Soluzioni Informatiche"></a><?php endif; ?></header>
<div class="content">
<h1><?php echo rt_h( $title ); ?></h1>
<p class="note"><?php echo $rollback ? 'The backup is streamed directly from the repository: files and tables that are not in the backup are removed (exclusions are kept) and the site stays in maintenance mode until the end. Keep this page open.' : 'The backup is streamed directly from the repository into this folder and the database you entered. Keep this page open.'; ?></p>
<div id="noauth" class="err" hidden>This page needs the access link generated by the plugin or installer. Open it again from there.</div>
<div id="panel" hidden>
<p><span id="phase">Ready</span> <span id="pct"></span></p>
<progress id="bar" max="100" value="0"></progress>
<div id="error" class="err" hidden></div>
<div id="done" class="ok" hidden>Restore completed. The temporary restore files have been deleted. <a id="donelink" href="<?php echo rt_h( $done_url ); ?>">Continue to the site</a></div>
<button id="start" type="button">Start restore</button>
<button id="retry" type="button" hidden>Retry</button>
<button id="abort" type="button" class="secondary">Abort and remove restore files</button>
<pre id="log" aria-live="polite"></pre>
</div>
</div>
<footer>Coded by <a href="https://radici.biz" target="_blank" rel="noopener">Radici Soluzioni Informatiche</a></footer>
</main>
<script>
(function(){
var key='wpssb-rt-'+location.pathname, h=location.hash.replace(/^#/,'');
if(/^[0-9a-f]{64}$/.test(h)){try{sessionStorage.setItem(key,h);}catch(e){}history.replaceState(null,'',location.pathname);}
var token=h; try{token=sessionStorage.getItem(key)||h;}catch(e){}
var $=function(id){return document.getElementById(id);};
if(!/^[0-9a-f]{64}$/.test(token||'')){$('noauth').hidden=false;return;}
$('panel').hidden=false;
var names={ready:'Ready',extract:'Extracting files',import:'Importing database',replace:'Updating URLs and paths',sweep:'Removing files and tables not in the backup',config:'Finishing',done:'Completed'};
var running=false, failures=0;
function post(action){var f=new FormData();f.append('action',action);f.append('token',token);
return fetch(location.pathname,{method:'POST',body:f,credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json().catch(function(){throw new Error('Unexpected server response (HTTP '+r.status+').');});});}
function show(d){$('phase').textContent=names[d.phase]||d.phase;$('pct').textContent=d.phase==='ready'?'':'('+d.progress+'%)';$('bar').value=d.phase==='done'?100:d.progress;
if(d.log){$('log').textContent=d.log.join('\n');$('log').scrollTop=1e9;}$('start').hidden=d.phase!=='ready'||running;}
function fail(m){running=false;$('error').textContent=m+' The restore can be resumed: press Retry.';$('error').hidden=false;$('retry').hidden=false;$('abort').disabled=false;}
function step(){post('step').then(function(d){
 if(d.state)show(d.state);
 if(d.done){running=false;try{sessionStorage.removeItem(key);}catch(e){}$('done').hidden=false;$('abort').hidden=true;$('retry').hidden=true;$('start').hidden=true;return;}
 if(!d.ok){throw new Error(d.error||'Restore step failed.');}
 failures=0;setTimeout(step,d.busy?3000:200);
}).catch(function(e){failures++;if(failures<4){setTimeout(step,2000*failures);}else{fail(e.message);}});}
function begin(){if(running)return;running=true;failures=0;$('error').hidden=true;$('retry').hidden=true;$('start').hidden=true;$('abort').disabled=true;step();}
$('start').onclick=function(){if(confirm('<?php echo $rollback ? 'The current site will be overwritten by the backup. Continue?' : 'Files in this folder and tables in the database will be overwritten. Continue?'; ?>'))begin();};
$('retry').onclick=begin;
$('abort').onclick=function(){if(!confirm('Remove the restore files? A restore that was interrupted halfway leaves the site incomplete.'))return;
 post('abort').then(function(d){if(!d.ok)throw new Error(d.error||'Abort failed.');try{sessionStorage.removeItem(key);}catch(e){}$('panel').innerHTML='<p class="note">Restore files removed.</p>';}).catch(function(e){fail(e.message);});};
post('status').then(function(d){if(!d.ok){$('panel').hidden=true;$('noauth').textContent=d.error||'Access denied.';$('noauth').hidden=false;return;}show(d.state);
 if(d.state.phase!=='ready'){$('start').hidden=true;$('retry').hidden=false;$('error').textContent='A restore was interrupted: press Retry to resume it.';$('error').hidden=false;}}).catch(function(e){fail(e.message);});
})();
</script>
</body></html>
		<?php
		exit;
	}

	/* ================================================================== */
	/* Entry point                                                        */
	/* ================================================================== */

	error_reporting( E_ALL & ~E_DEPRECATED );
	if ( defined( 'WPSSB_RUNTIME_LIBRARY_ONLY' ) ) {
		return;
	}
	$rt_cli = PHP_SAPI === 'cli';
	if ( ! $rt_cli ) {
		@ini_set( 'display_errors', '0' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Cache-Control: no-store' );
		header( 'X-Frame-Options: DENY' );
		header( 'Referrer-Policy: no-referrer' );
	}
	$rt_cfg = rt_config();
	if ( ! $rt_cfg ) {
		if ( $rt_cli ) {
			fwrite( STDERR, "Restore configuration missing.\n" );
			exit( 1 );
		}
		http_response_code( 404 );
		exit;
	}
	if ( ! function_exists( 'openssl_decrypt' ) ) {
		http_response_code( 500 );
		exit( 'The OpenSSL PHP extension is required.' );
	}
	rt_bootstrap( $rt_cfg );

	if ( $rt_cli ) {
		$token = (string) getenv( 'WPSSB_RUNTIME_TOKEN' );
		if ( ! rt_check_token( $rt_cfg, $token ) ) {
			fwrite( STDERR, "Invalid or missing WPSSB_RUNTIME_TOKEN.\n" );
			exit( 2 );
		}
		$printed = 0;
		try {
			$secret = rt_secret( $rt_cfg, $token );
			do {
				$result = rt_step( $rt_cfg, $secret, 60 );
				if ( $result['busy'] ) {
					fwrite( STDERR, "Another restore step is running.\n" );
					exit( 1 );
				}
				$state = $result['state'];
				echo sprintf( "%-8s %3d%%\n", $state['phase'], $state['progress'] );
				for ( $i = $printed; $i < count( $state['log'] ); $i++ ) {
					echo '  ' . $state['log'][ $i ] . "\n";
				}
				$printed = count( $state['log'] );
			} while ( ! $result['done'] );
		} catch ( Throwable $e ) {
			fwrite( STDERR, 'ERROR: ' . $e->getMessage() . "\nRun the same command again to resume.\n" );
			exit( 1 );
		}
		echo "Restore completed.\n";
		exit( 0 );
	}

	if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET' ) ) {
		rt_page( $rt_cfg );
	}
	$token = isset( $_POST['token'] ) ? (string) $_POST['token'] : '';
	if ( ! rt_check_token( $rt_cfg, $token ) ) {
		usleep( 500000 );
		rt_json( array( 'ok' => false, 'error' => 'Invalid or expired restore link.' ), 403 );
	}
	$action = isset( $_POST['action'] ) ? (string) $_POST['action'] : '';
	try {
		if ( 'status' === $action ) {
			rt_json( array( 'ok' => true, 'state' => rt_summary( rs_load_state() ) ) );
		}
		$secret = rt_secret( $rt_cfg, $token );
		if ( 'abort' === $action ) {
			$lock = rs_lock();
			if ( ! $lock ) {
				rt_json( array( 'ok' => false, 'error' => 'A restore step is running: wait and try again.' ), 409 );
			}
			rs_unlock( $lock );
			rt_remove( $rt_cfg );
			rt_json( array( 'ok' => true ) );
		}
		if ( 'step' === $action ) {
			ignore_user_abort( true );
			@set_time_limit( 120 );
			$result = rt_step( $rt_cfg, $secret, rt_budget() );
			rt_json(
				array(
					'ok'    => true,
					'busy'  => $result['busy'],
					'done'  => $result['done'],
					'state' => rt_summary( $result['state'] ),
				)
			);
		}
		rt_json( array( 'ok' => false, 'error' => 'Unknown action.' ), 400 );
	} catch ( Throwable $e ) {
		rt_json( array( 'ok' => false, 'error' => $e->getMessage(), 'state' => rt_summary( is_file( RS_STATE ) ? rs_load_state() : null ) ), 500 );
	}
}
