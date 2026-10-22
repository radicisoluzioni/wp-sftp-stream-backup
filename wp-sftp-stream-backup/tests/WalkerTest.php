<?php
/**
 * @package WPSSB
 */

namespace WPSSB\Tests;

use PHPUnit\Framework\TestCase;
use WPSSB\Files\Exclusions;
use WPSSB\Files\Walker;
use WPSSB\Tests\Support\Fs;

class WalkerTest extends TestCase {

	/** @var string */
	private $root;

	protected function setUp(): void {
		$this->root = Fs::tmp( 'walk' );
		Fs::sampleTree( $this->root );
	}

	protected function tearDown(): void {
		Fs::rmrf( $this->root );
	}

	private function collect( Walker $walker, ?string $cursor = null ): array {
		$out = array();
		foreach ( $walker->walk( $cursor ) as $entry ) {
			$out[] = $entry['rel'];
		}
		return $out;
	}

	public function testOrderIsComponentWiseAndResumable(): void {
		$walker = new Walker( $this->root, new Exclusions( array() ) );
		$all    = $this->collect( $walker );

		$this->assertGreaterThan( 50, count( $all ) );
		$this->assertSame( count( $all ), count( array_unique( $all ) ) );
		for ( $i = 1; $i < count( $all ); $i++ ) {
			$this->assertSame( -1, Walker::compare( explode( '/', $all[ $i - 1 ] ), explode( '/', $all[ $i ] ) ), $all[ $i - 1 ] . ' < ' . $all[ $i ] );
		}
		// "a" and its children come before "a-b" and "a-c.php" even though "-" < "/".
		$this->assertLessThan( array_search( 'wp-content/plugins/a-b', $all, true ), array_search( 'wp-content/plugins/a/b.php', $all, true ) );

		foreach ( array( 0, 3, 10, 25, count( $all ) - 2, count( $all ) - 1 ) as $k ) {
			$this->assertSame( array_slice( $all, $k + 1 ), $this->collect( $walker, $all[ $k ] ), 'resume after ' . $all[ $k ] );
		}
	}

	public function testExclusions(): void {
		$walker = new Walker( $this->root, new Exclusions( array( 'wp-content/cache', '*.log', '/wp-includes/file-1?.php', '# comment' ) ) );
		$all    = $this->collect( $walker );
		$this->assertNotContains( 'wp-content/cache', $all );
		$this->assertNotContains( 'wp-content/cache/page.html', $all );
		$this->assertNotContains( 'wp-content/debug.log', $all );
		$this->assertNotContains( 'wp-includes/file-12.php', $all );
		$this->assertContains( 'wp-includes/file-02.php', $all );
		$this->assertContains( 'wp-content/upgrade-empty', $all );
	}

	public function testExtraRootFile(): void {
		unlink( $this->root . '/wp-config.php' );
		$outside = Fs::tmp( 'cfg' );
		file_put_contents( $outside . '/wp-config.php', 'outside' );
		$walker = new Walker( $this->root, new Exclusions( array() ), array( 'wp-config.php' => $outside . '/wp-config.php' ) );
		$found  = null;
		foreach ( $walker->walk() as $entry ) {
			if ( 'wp-config.php' === $entry['rel'] ) {
				$found = $entry['abs'];
			}
		}
		$this->assertSame( $outside . '/wp-config.php', $found );
		Fs::rmrf( $outside );
	}
}
