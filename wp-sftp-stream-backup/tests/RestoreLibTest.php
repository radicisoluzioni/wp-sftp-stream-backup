<?php
/**
 * Tests for the library parts of restore.php.
 *
 * @package WPSSB
 */

namespace WPSSB\Tests;

use PHPUnit\Framework\TestCase;

class SerializedFixture {
	public $pub = 'https://old.test/pub';
	protected $prot = array( 'u' => 'https://old.test/prot' );
	private $priv = 'https://old.test/priv';

	public function values(): array {
		return array( $this->pub, $this->prot['u'], $this->priv );
	}
}

class RestoreLibTest extends TestCase {

	const PAIRS = array(
		'https://old.test'      => 'https://new.example.org/sub',
		'https:\/\/old.test'    => 'https:\/\/new.example.org\/sub',
	);

	public function testSerializedReplace(): void {
		$data = array(
			'url'    => 'https://old.test/x',
			'nested' => serialize( array( 'a' => 'https://old.test/a', 'n' => 3 ) ),
			'obj'    => (object) array( 'p' => 'https://old.test/y' ),
			'fix'    => new SerializedFixture(),
			'n'      => 1,
			'f'      => 1.5,
			'b'      => true,
			'null'   => null,
			'json'   => json_encode( array( 'u' => 'https://old.test/j' ) ),
			'utf8'   => 'àèì https://old.test/ü',
			5        => 'int key',
		);
		$ser = serialize( $data );
		$new = \RsSerialized::replaceValue( $ser, self::PAIRS );
		$this->assertNotSame( $ser, $new );
		$back = unserialize( $new );
		$this->assertIsArray( $back );
		$this->assertSame( 'https://new.example.org/sub/x', $back['url'] );
		$this->assertSame( 'https://new.example.org/sub/a', unserialize( $back['nested'] )['a'] );
		$this->assertSame( 'https://new.example.org/sub/y', $back['obj']->p );
		$this->assertSame( array( 'https://new.example.org/sub/pub', 'https://new.example.org/sub/prot', 'https://new.example.org/sub/priv' ), $back['fix']->values() );
		$this->assertSame( 'https://new.example.org/sub/j', json_decode( $back['json'], true )['u'] );
		$this->assertSame( 'àèì https://new.example.org/sub/ü', $back['utf8'] );
		$this->assertSame( 1.5, $back['f'] );
		$this->assertNull( $back['null'] );
		$this->assertSame( 'int key', $back[5] );
	}

	public function testUnknownClassIsPreservedWithoutUnserializing(): void {
		$ser = 'O:12:"Missing\\Klas":1:{s:3:"url";s:18:"https://old.test/z";}';
		$new = \RsSerialized::replaceValue( $ser, self::PAIRS );
		$this->assertSame( 'O:12:"Missing\\Klas":1:{s:3:"url";s:29:"https://new.example.org/sub/z";}', $new );
	}

	public function testPlainAndBrokenValues(): void {
		$this->assertSame( 'see https://new.example.org/sub/p', \RsSerialized::replaceValue( 'see https://old.test/p', self::PAIRS ) );
		$this->assertSame( 'untouched', \RsSerialized::replaceValue( 'untouched', self::PAIRS ) );
		// Wrong length: not valid serialized data, plain replacement is used.
		$this->assertSame( 's:99:"https://new.example.org/sub";', \RsSerialized::replaceValue( 's:99:"https://old.test";', self::PAIRS ) );
	}

	public function testGzReaderResumesFromCheckpoints(): void {
		$file  = tempnam( sys_get_temp_dir(), 'gzr' );
		$plain = '';
		$gz    = '';
		for ( $i = 0; $i < 6; $i++ ) {
			$part   = random_bytes( 20000 + $i * 7000 ) . str_repeat( "row $i\n", 3000 );
			$plain .= $part;
			$gz    .= gzencode( $part, 1 );
		}
		$gz .= gzencode( '', 1 );
		file_put_contents( $file, $gz );

		$reader = new \RsGzReader( $file, 0, 0, 0 );
		$got    = '';
		$marks  = array();
		while ( '' !== ( $chunk = $reader->read( 3333 + strlen( $got ) % 5000 ) ) ) {
			$got    .= $chunk;
			$pos     = $reader->position();
			$marks[] = array_merge( $reader->checkpoint( $pos ), array( $pos ) );
		}
		$this->assertSame( strlen( $plain ), strlen( $got ) );
		$this->assertSame( sha1( $plain ), sha1( $got ) );
		$this->assertSame( filesize( $file ), $reader->compressedPosition() );

		foreach ( array( 1, 7, 20, count( $marks ) - 3 ) as $k ) {
			list( $c, $u, $pos ) = $marks[ $k ];
			$r2 = new \RsGzReader( $file, $c, $u, $pos );
			$this->assertSame( substr( $plain, $pos, 50000 ), $r2->read( 50000 ), "resume at $pos" );
		}
		unlink( $file );
	}

	public function testTruncatedGzipIsDetected(): void {
		$file = tempnam( sys_get_temp_dir(), 'gzt' );
		file_put_contents( $file, substr( gzencode( random_bytes( 100000 ) ), 0, 50000 ) );
		$reader = new \RsGzReader( $file, 0, 0, 0 );
		$this->expectException( \RuntimeException::class );
		while ( '' !== $reader->read( 65536 ) ) {
			// Read until the exception.
		}
	}
}
