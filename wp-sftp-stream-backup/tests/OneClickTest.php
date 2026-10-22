<?php
/**
 * @package WPSSB
 */

namespace WPSSB\Tests;

use PHPUnit\Framework\TestCase;
use WPSSB\Restore\OneClick;
use WPSSB\Tests\Support\Fs;

class OneClickTest extends TestCase {

	private const PASSWORD = 'correct horse battery';

	/** @var string */
	private $tmp;

	protected function setUp(): void {
		$this->tmp = rtrim( str_replace( '\\', '/', sys_get_temp_dir() ), '/' ) . '/wpssb-oneclick-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->tmp );
	}

	protected function tearDown(): void {
		OneClick::removeDir( $this->tmp );
	}

	/** Runs PHP code in a separate process (the installer and runtime define global constants). */
	private function subprocess( string $code ): array {
		$script = $this->tmp . '/probe-' . bin2hex( random_bytes( 4 ) ) . '.php';
		file_put_contents( $script, "<?php\n" . $code );
		$out = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' 2>&1' );
		unlink( $script );
		$data = json_decode( (string) $out, true );
		$this->assertIsArray( $data, 'Subprocess output: ' . $out );
		return $data;
	}

	private function payload(): array {
		return array(
			'v'       => 1,
			'repo'    => array( 'type' => 's3', 'bucket' => 'b', 'secret_key' => "s3cr\u{e8}t/+=" ),
			'path'    => 'backups/site-20260101-000000-abcdef',
			'expires' => time() + 3600,
		);
	}

	public function testMigrationCodeRoundTrip(): void {
		$code = OneClick::encodeCode( $this->payload(), self::PASSWORD );
		$this->assertMatchesRegularExpression( '/\AWPSSB1\.[A-Za-z0-9_-]{60,}\z/', $code );
		$this->assertStringNotContainsString( 'secret_key', $code );
		$this->assertSame( $this->payload()['repo'], OneClick::decodeCode( chunk_split( $code, 40, "\n " ), self::PASSWORD )['repo'] );
		$this->assertNotSame( $code, OneClick::encodeCode( $this->payload(), self::PASSWORD ), 'salt and IV are random' );
	}

	public function testMigrationCodeRejectsWrongInput(): void {
		$code = OneClick::encodeCode( $this->payload(), self::PASSWORD );
		foreach (
			array(
				array( $code, 'wrong password!!' ),
				array( substr( $code, 0, -2 ) . ( 'AA' === substr( $code, -2 ) ? 'BB' : 'AA' ), self::PASSWORD ),
				array( 'WPSSB1.abc', self::PASSWORD ),
				array( 'XYZ' . $code, self::PASSWORD ),
			) as $case
		) {
			try {
				OneClick::decodeCode( $case[0], $case[1] );
				$this->fail( 'Accepted an invalid code or password.' );
			} catch ( \RuntimeException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		$this->expectException( \RuntimeException::class );
		OneClick::encodeCode( $this->payload(), 'short' );
	}

	public function testInstallerBundleDecodesTheCode(): void {
		$code      = OneClick::encodeCode( $this->payload(), self::PASSWORD );
		$installer = $this->tmp . '/wpssb-installer.php';
		file_put_contents( $installer, OneClick::installerSource( $code ) );
		$data = $this->subprocess(
			"define( 'WPSSB_INSTALLER_LIBRARY_ONLY', true );\n" .
			'require ' . var_export( $installer, true ) . ";\n" .
			'$b = wi_bundle();' . "\n" .
			'echo json_encode( array( "code" => $b["code"], "files" => array_keys( $b["files"] ), "payload" => wi_decode_code( $b["code"], ' . var_export( self::PASSWORD, true ) . ' ) ) );'
		);
		$this->assertSame( $code, $data['code'] );
		$this->assertSame( $this->payload()['repo'], $data['payload']['repo'] );
		foreach ( array( 'run.php', 'engine.php', '.htaccess', 'src/Remote/S3Storage.php', 'src/Remote/SegmentedStorage.php' ) as $file ) {
			$this->assertContains( $file, $data['files'] );
		}

		$generic = $this->tmp . '/generic.php';
		file_put_contents( $generic, OneClick::installerSource( '' ) );
		$this->assertSame( 0, $this->lint( $generic ) );
	}

	private function lint( string $file ): int {
		exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file ) . ' 2>&1', $out, $status );
		return $status;
	}

	public function testBundledRemoteClassesAreSelfContained(): void {
		$files = OneClick::runtimeFiles( false );
		foreach ( $files as $rel => $source ) {
			if ( 0 !== strpos( $rel, 'src/Remote/' ) ) {
				continue;
			}
			preg_match_all( '/(?:implements|extends|new)\s+([A-Z][A-Za-z0-9]*)\b|(?<![\\\\\w])([A-Z][A-Za-z0-9]*)::/', $source, $m );
			foreach ( array_filter( array_merge( $m[1], $m[2] ) ) as $class ) {
				if ( in_array( $class, array( 'SFTP', 'PublicKeyLoader' ), true ) ) {
					continue;
				}
				$this->assertArrayHasKey( 'src/Remote/' . $class . '.php', $files, $rel . ' needs ' . $class );
			}
			preg_match_all( '/\\\\WPSSB\\\\([A-Za-z]+)\\\\([A-Za-z0-9]+)/', $source, $ns );
			foreach ( $ns[0] as $i => $fqcn ) {
				$this->assertSame( 'Job\\Lock', $ns[1][ $i ] . '\\' . $ns[2][ $i ], $rel . ' uses ' . $fqcn . ', unavailable in the runtime' );
			}
		}
		$this->assertStringContainsString( 'Require all denied', $files['.htaccess'] );
		$this->assertStringStartsWith( "<?php if ( ! defined( 'WPSSB_RESTORE_LIBRARY_ONLY' ) )", $files['engine.php'] );
	}

	public function testRuntimeReadsItsConfiguration(): void {
		$secret  = array(
			'repo' => array( 'type' => 'sftp', 'password' => 'p@ss' ),
			'path' => 'upload/x',
			'opts' => array( 'db_pass' => 'dbpw', 'keep' => array( 'cache' ) ),
		);
		$runtime = OneClick::createRuntime( $this->tmp, array( 'mode' => 'rollback', 'done_url' => 'https://example.com/wp-admin/' ), $secret, OneClick::runtimeFiles( false ) );
		$this->assertMatchesRegularExpression( '/\Awpssb-restore-[0-9a-f]{16}\z/', $runtime['dir'] );
		$dir    = $this->tmp . '/' . $runtime['dir'];
		$config = (string) file_get_contents( $dir . '/config.php' );
		$this->assertStringStartsWith( "<?php exit; ?>\n", $config );
		$this->assertStringNotContainsString( 'p@ss', $config );
		$this->assertStringNotContainsString( $runtime['token'], $config );

		$data = $this->subprocess(
			"define( 'WPSSB_RUNTIME_LIBRARY_ONLY', true );\n" .
			'require ' . var_export( $dir . '/run.php', true ) . ";\n" .
			'$cfg = rt_config();' . "\n" .
			'echo json_encode( array( "mode" => $cfg["mode"], "root" => $cfg["root"], "ok" => rt_check_token( $cfg, ' . var_export( $runtime['token'], true ) . ' ), "bad" => rt_check_token( $cfg, str_repeat( "0", 64 ) ), "secret" => rt_secret( $cfg, ' . var_export( $runtime['token'], true ) . ' ) ) );'
		);
		$this->assertSame( 'rollback', $data['mode'] );
		$this->assertSame( $this->tmp, $data['root'] );
		$this->assertTrue( $data['ok'] );
		$this->assertFalse( $data['bad'] );
		$this->assertSame( 'p@ss', $data['secret']['repo']['password'] );
		$this->assertSame( array( 'cache', $runtime['dir'] ), $data['secret']['opts']['keep'], 'the runtime directory survives the restore' );
	}

	public function testSweepRuntimes(): void {
		$now = time();
		$make = function ( string $name, ?int $state_age ) use ( $now ) {
			mkdir( $this->tmp . '/' . $name );
			file_put_contents( $this->tmp . '/' . $name . '/config.php', 'x' );
			if ( null !== $state_age ) {
				file_put_contents( $this->tmp . '/' . $name . '/state.php', 'x' );
				touch( $this->tmp . '/' . $name . '/state.php', $now - $state_age );
			}
		};
		$make( 'wpssb-restore-0000000000000001', null );
		$make( 'wpssb-restore-0000000000000002', 2 * 86400 );
		$make( 'wpssb-restore-not-a-runtime', null );
		OneClick::sweepRuntimes( $this->tmp, $now );
		$this->assertDirectoryDoesNotExist( $this->tmp . '/wpssb-restore-0000000000000001' );
		$this->assertDirectoryDoesNotExist( $this->tmp . '/wpssb-restore-0000000000000002' );
		$this->assertDirectoryExists( $this->tmp . '/wpssb-restore-not-a-runtime' );

		$make( 'wpssb-restore-0000000000000003', 60 );
		try {
			OneClick::sweepRuntimes( $this->tmp, $now );
			$this->fail( 'A running restore was swept.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'in progress', $e->getMessage() );
		}
		$this->assertDirectoryExists( $this->tmp . '/wpssb-restore-0000000000000003' );
	}
}
