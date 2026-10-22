<?php
namespace WPSSB\Tests;

use PHPUnit\Framework\TestCase;

class BrandingTest extends TestCase {
	public function testStandaloneLogoIsIdenticalToPackagedOriginal(): void {
		$root = dirname( __DIR__ );
		$restore = file_get_contents( $root . '/restore/restore.php' );
		$this->assertSame( 1, preg_match( "/echo '([A-Za-z0-9+\\/]+=*)';/", $restore, $match ) );
		$embedded = base64_decode( $match[1], true );
		$this->assertSame( file_get_contents( $root . '/assets/radici-logo.png' ), $embedded );
		$image = getimagesizefromstring( $embedded );
		$this->assertSame( 130, $image[0] );
		$this->assertSame( 48, $image[1] );
		$this->assertSame( 'image/png', $image['mime'] );
	}
}
