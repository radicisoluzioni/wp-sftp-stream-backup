<?php
/**
 * Real-server transport test for NextcloudStorage (no WordPress, no mocks).
 * Env: NC_URL, NC_USER, NC_APP_PASS, RUN_ID. Plugin root mounted at /plugin.
 *
 * @package WPSSB
 */

define( 'WPSSB_ALLOW_INSECURE_REMOTE', true );
foreach ( array( 'RemoteException', 'ObjectStorage', 'NextcloudStorage' ) as $cls ) {
	require '/plugin/src/Remote/' . $cls . '.php';
}

use WPSSB\Remote\NextcloudStorage;
use WPSSB\Remote\RemoteException;

$url   = rtrim( (string) getenv( 'NC_URL' ), '/' );
$user  = (string) getenv( 'NC_USER' );
$pass  = (string) getenv( 'NC_APP_PASS' );
$run   = preg_replace( '/[^A-Za-z0-9.-]/', '', (string) getenv( 'RUN_ID' ) );
$fails = 0;
$calls = 0;

function check( bool $ok, string $msg ): void {
	global $fails;
	echo ( $ok ? '[ok]   ' : '[FAIL] ' ) . $msg . "\n";
	if ( ! $ok ) {
		$fails++;
	}
}

function throws( callable $fn, string $needle, string $msg ): void {
	try {
		$fn();
		check( false, $msg . ' (no exception)' );
	} catch ( RemoteException $e ) {
		check( false !== strpos( $e->getMessage(), $needle ), $msg . ' -> ' . $e->getMessage() );
	}
}

/** WP HTTP shaped sender over PHP's http:// stream wrapper. */
function send( string $method, string $url, array $args ) {
	global $calls;
	$calls++;
	$hdr = "Connection: close\r\n";
	foreach ( isset( $args['headers'] ) ? $args['headers'] : array() as $k => $v ) {
		$hdr .= $k . ': ' . $v . "\r\n";
	}
	$http = array(
		'method'           => $method,
		'header'           => $hdr,
		'ignore_errors'    => true,
		'follow_location'  => 0,
		'protocol_version' => 1.1,
		'timeout'          => isset( $args['timeout'] ) ? $args['timeout'] : 60,
	);
	if ( isset( $args['body'] ) ) {
		$http['content'] = $args['body'];
	}
	$fp = @fopen( $url, 'rb', false, stream_context_create( array( 'http' => $http ) ) );
	if ( false === $fp ) {
		$err = error_get_last();
		return new class( $err ? $err['message'] : 'connection failed' ) {
			private $m;
			public function __construct( $m ) {
				$this->m = $m;
			}
			public function get_error_message() {
				return $this->m;
			}
		};
	}
	$meta    = stream_get_meta_data( $fp );
	$code    = 0;
	$headers = array();
	foreach ( $meta['wrapper_data'] as $line ) {
		if ( preg_match( '~^HTTP/\S+\s+(\d{3})~', $line, $m ) ) {
			$code    = (int) $m[1];
			$headers = array();
		} elseif ( false !== strpos( $line, ':' ) ) {
			list( $k, $v )                 = explode( ':', $line, 2 );
			$headers[ strtolower( $k ) ] = trim( $v );
		}
	}
	$limit = isset( $args['limit_response_size'] ) ? (int) $args['limit_response_size'] : -1;
	$body  = 'HEAD' === $method ? '' : (string) stream_get_contents( $fp, $limit );
	fclose( $fp );
	return array(
		'headers'  => $headers,
		'body'     => $body,
		'response' => array(
			'code'    => $code,
			'message' => '',
		),
	);
}

function auth_headers(): array {
	global $user, $pass;
	return array( 'Authorization' => 'Basic ' . base64_encode( $user . ':' . $pass ) );
}

function create_drop_share( string $path, string $password ): string {
	global $url;
	$form = array(
		'path'        => $path,
		'shareType'   => 3,
		'permissions' => 4,
	);
	if ( '' !== $password ) {
		$form['password'] = $password;
	}
	$r = send(
		'POST',
		$url . '/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json',
		array(
			'headers' => auth_headers() + array(
				'OCS-APIRequest' => 'true',
				'Accept'         => 'application/json',
				'Content-Type'   => 'application/x-www-form-urlencoded',
			),
			'body'    => http_build_query( $form ),
		)
	);
	$j     = json_decode( $r['body'], true );
	$token = isset( $j['ocs']['data']['token'] ) ? $j['ocs']['data']['token'] : '';
	$perm  = isset( $j['ocs']['data']['permissions'] ) ? (int) $j['ocs']['data']['permissions'] : -1;
	check( 200 === $r['response']['code'] && '' !== $token, 'OCS file-drop share created for ' . $path . ( '' !== $password ? ' (password)' : '' ) );
	check( 4 === $perm, 'share permissions are create-only (4), got ' . $perm );
	return $token;
}

if ( getenv( 'HANDOFF' ) ) {
	// Prepare fixture folders/shares for an external WordPress -> Nextcloud test and print JSON.
	foreach ( array( 'WPSSB%20Handoff/', 'WPSSB%20Handoff/auth/', 'WPSSB%20Handoff/drop-open/', 'WPSSB%20Handoff/drop-pw/' ) as $d ) {
		$r = send( 'MKCOL', $url . '/remote.php/dav/files/' . rawurlencode( $user ) . '/' . $d, array( 'headers' => auth_headers() ) );
		check( in_array( $r['response']['code'], array( 201, 405 ), true ), 'MKCOL ' . rawurldecode( $d ) );
	}
	$share_pw = 'Drop-' . bin2hex( random_bytes( 8 ) ) . '!';
	$out      = array(
		'auth_path'       => 'WPSSB Handoff/auth',
		'drop_open_token' => create_drop_share( '/WPSSB Handoff/drop-open', '' ),
		'drop_pw_token'   => create_drop_share( '/WPSSB Handoff/drop-pw', $share_pw ),
		'drop_pw_password' => $share_pw,
	);
	fwrite( STDERR, $fails ? "handoff setup failed\n" : '' );
	echo 'HANDOFF_JSON=' . json_encode( $out ) . "\n";
	exit( $fails ? 1 : 0 );
}

$cfg  = array(
	'url'      => $url,
	'username' => $user,
	'password' => $pass,
	'path'     => 'WPSSB IT/' . $run,
);
$auth = new NextcloudStorage( $cfg, 'send' );

echo "=== Authenticated WebDAV ({$run})\n";
check( array( 'read' => true, 'list' => true, 'delete' => true, 'overwrite' => true ) === $auth->capabilities(), 'auth capabilities all true' );
$k1 = 'base/bk1/manifest.json';
$k2 = 'base/bk1/part #1 ä+%.bin';
$auth->put( $k1, '{"v":1}' );
check( '{"v":1}' === $auth->get( $k1 ), 'PUT creates nested folders; GET returns content' );
$bin = random_bytes( 70000 );
$auth->put( $k2, $bin );
check( $bin === $auth->get( $k2 ), 'binary round trip with special-character key' );
$st = $auth->stat( $k2 );
check( is_array( $st ) && 70000 === $st['size'] && abs( $st['mtime'] - time() ) < 300, 'HEAD stat size/mtime ' . json_encode( $st ) );
$auth->put( $k1, '{"v":2}' );
check( '{"v":2}' === $auth->get( $k1 ), 'overwrite PUT' );
check( null === $auth->get( 'base/bk1/missing' ), 'GET missing -> null' );
check( null === $auth->stat( 'base/bk1/missing' ), 'HEAD missing -> null' );
check( null === $auth->get( 'base/nofolder/x' ), 'GET in missing folder -> null' );
$auth->put( 'base/bk1/sub/nested.txt', 'n' );

$l = $auth->objects( 'base/bk1/' );
check( array( $k1, $k2 ) === array_keys( $l['items'] ) && null === $l['next'], 'PROPFIND depth 1 returns full keys of direct files only: ' . json_encode( array_keys( $l['items'] ), JSON_UNESCAPED_UNICODE ) );
check( 70000 === $l['items'][ $k2 ]['size'], 'listing size' );
check( array( $k2 ) === array_keys( $auth->objects( 'base/bk1/part' )['items'] ), 'partial-name prefix filter' );
check( array() === $auth->objects( 'base/none/' )['items'], 'missing folder lists empty' );
throws( function () use ( $auth ) { $auth->objects( 'base/bk1/', 'c' ); }, 'not paginated', 'cursor rejected' );

for ( $i = 0; $i < 150; $i++ ) {
	$auth->put( sprintf( 'base/many/f%03d', $i ), (string) $i );
}
$l = $auth->objects( 'base/many/' );
check( 150 === count( $l['items'] ) && isset( $l['items']['base/many/f149'] ), '150-file folder fully listed (' . count( $l['items'] ) . ')' );

$big = str_repeat( 'B', NextcloudStorage::MAX_GET_BYTES + 1 );
$auth->put( 'base/big.bin', $big );
check( strlen( $big ) === $auth->stat( 'base/big.bin' )['size'], 'large PUT stat size' );
throws( function () use ( $auth ) { $auth->get( 'base/big.bin' ); }, 'download limit', 'GET over 8 MiB refused' );

$auth->remove( $k1 );
check( null === $auth->get( $k1 ), 'DELETE then GET -> null' );
$auth->remove( $k1 );
check( true, 'DELETE of missing object tolerated' );

$bad = new NextcloudStorage( array( 'password' => 'wrong-app-password' ) + $cfg, 'send' );
throws( function () use ( $bad ) { $bad->put( 'x', 'y' ); }, 'HTTP 401', 'wrong app password' );
throws( function () use ( $bad ) { $bad->get( 'x' ); }, 'HTTP 401', 'wrong app password on GET is not null' );

echo "=== Public file drop\n";
foreach ( array( 'drop-open', 'drop-pw' ) as $d ) {
	$r = send( 'MKCOL', $url . '/remote.php/dav/files/' . rawurlencode( $user ) . '/WPSSB%20IT/' . $run . '/' . $d . '/', array( 'headers' => auth_headers() ) );
	check( 201 === $r['response']['code'], 'MKCOL ' . $d );
}
$tok_open = create_drop_share( '/WPSSB IT/' . $run . '/drop-open', '' );
$share_pw = 'Drop-' . bin2hex( random_bytes( 8 ) ) . '!';
$tok_pw   = create_drop_share( '/WPSSB IT/' . $run . '/drop-pw', $share_pw );

$pub = new NextcloudStorage( array( 'public_url' => $url . '/s/' . $tok_open ), 'send' );
check( array( 'read' => false, 'list' => false, 'delete' => false, 'overwrite' => false ) === $pub->capabilities(), 'public capabilities all false' );
$name = 'bk1--g1--database.sql.gz';
$pub->put( $name, 'first' );
$pub->put( $name, 'second' );
$pub->put( 'bk1--g1--manifest.json', '{}' );
$l     = $auth->objects( 'drop-open/' );
$names = array_keys( $l['items'] );
echo '       drop-open contains: ' . json_encode( $names ) . "\n";
check( 3 === count( $names ), 'three files stored flat in drop folder' );
check( 'first' === $auth->get( 'drop-open/' . $name ), 'first upload kept original name and content (no overwrite)' );
$renamed = array_values( array_diff( $names, array( 'drop-open/' . $name, 'drop-open/bk1--g1--manifest.json' ) ) );
check( 1 === count( $renamed ) && false !== strpos( $renamed[0], '(2)' ) && 'second' === $auth->get( $renamed[0] ), 'duplicate name renamed by server: ' . json_encode( $renamed ) );

$before = $calls;
throws( function () use ( $pub ) { $pub->get( 'x' ); }, 'unavailable', 'public get unavailable' );
throws( function () use ( $pub ) { $pub->stat( 'x' ); }, 'unavailable', 'public stat unavailable' );
throws( function () use ( $pub ) { $pub->objects( '' ); }, 'unavailable', 'public objects unavailable' );
throws( function () use ( $pub ) { $pub->remove( 'x' ); }, 'unavailable', 'public remove unavailable' );
throws( function () use ( $pub ) { $pub->put( 'a/b', 'x' ); }, 'flat', 'public nested name rejected' );
check( $before === $calls, 'unavailable operations sent no HTTP request' );

$pub_base = $url . '/public.php/dav/files/' . $tok_open . '/';
$h        = array( 'X-Requested-With' => 'XMLHttpRequest' );
foreach ( array( 'GET' => $pub_base . rawurlencode( $name ), 'PROPFIND' => $pub_base, 'DELETE' => $pub_base . rawurlencode( $name ) ) as $m => $u ) {
	$r = send( $m, $u, array( 'headers' => $h + ( 'PROPFIND' === $m ? array( 'Depth' => '1' ) : array() ) ) );
	check( $r['response']['code'] >= 400 && false === strpos( $r['body'], 'first' ), 'server denies raw public ' . $m . ' (HTTP ' . $r['response']['code'] . ')' );
}
check( 'first' === $auth->get( 'drop-open/' . $name ), 'file intact after public DELETE attempt' );

$pubpw = new NextcloudStorage( array( 'public_url' => $url . '/index.php/s/' . $tok_pw, 'public_password' => $share_pw ), 'send' );
$pubpw->put( 'bk2--g1--manifest.json', 'pw' );
check( 'pw' === $auth->get( 'drop-pw/bk2--g1--manifest.json' ), 'password-protected drop upload (index.php/s/ URL form)' );
$nopw = new NextcloudStorage( array( 'public_url' => $url . '/s/' . $tok_pw ), 'send' );
throws( function () use ( $nopw ) { $nopw->put( 'bk2--g1--x', 'x' ); }, 'HTTP 401', 'password share without password' );
$wrong = new NextcloudStorage( array( 'public_url' => $url . '/s/' . $tok_pw, 'public_password' => 'nope-nope' ), 'send' );
throws( function () use ( $wrong ) { $wrong->put( 'bk2--g1--x', 'x' ); }, 'HTTP 401', 'password share with wrong password' );
$gone = new NextcloudStorage( array( 'public_url' => $url . '/s/NoSuchToken123' ), 'send' );
throws( function () use ( $gone ) { $gone->put( 'x', 'x' ); }, 'share not found', 'unknown share token' );
check( array( 'drop-pw/bk2--g1--manifest.json' ) === array_keys( $auth->objects( 'drop-pw/' )['items'] ), 'rejected uploads stored nothing' );

echo "\n" . ( $fails ? "FAILED: {$fails}" : 'ALL PASSED' ) . "\n";
exit( $fails ? 1 : 0 );