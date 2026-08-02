<?php

declare(strict_types=1);

namespace Tests\Secrets;

// Test fixtures deliberately exercise native filesystem semantics.
// phpcs:disable WordPress.WP.AlternativeFunctions

use PHPUnit\Framework\TestCase;
use RAN\Secrets\PosixFilesystemProbe;

final class PosixFilesystemProbeTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/ran-booster-probe-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->root, 0700 );
	}

	protected function tearDown(): void {
		$this->remove( $this->root );
	}

	public function testProvesRequiredOperationsWithoutCreatingTheSecretsFile(): void {
		$candidate = $this->root . '/.ran-booster/0123456789abcdef/secrets.json';

		self::assertTrue( ( new PosixFilesystemProbe() )->probe( $candidate ) );
		self::assertFileDoesNotExist( $candidate );
		self::assertDirectoryExists( dirname( $candidate ) );
		self::assertSame( 0700, fileperms( dirname( $candidate ) ) & 0777 );
		self::assertSame( array(), glob( dirname( $candidate ) . '/.probe-*' ) );
	}

	public function testRejectsPathsOutsideTheFixedCandidateShape(): void {
		self::assertFalse( ( new PosixFilesystemProbe() )->probe( $this->root . '/secrets.json' ) );
	}

	public function testRejectsAnUnsafeExistingPrivateDirectoryWithoutChangingItsContents(): void {
		$private   = $this->root . '/.ran-booster';
		$sentinel  = $private . '/operator-owned-canary';
		$candidate = $private . '/0123456789abcdef/secrets.json';
		self::assertTrue( mkdir( $private, 0700 ) );
		self::assertNotFalse( file_put_contents( $sentinel, 'operator-owned-content' ) );
		self::assertTrue( chmod( $private, 0770 ) );

		self::assertFalse( ( new PosixFilesystemProbe() )->probe( $candidate ) );
		self::assertSame( 'operator-owned-content', file_get_contents( $sentinel ) );
		self::assertDirectoryDoesNotExist( dirname( $candidate ) );
		self::assertFileDoesNotExist( $candidate );
	}

	private function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		$entries = scandir( $path );
		foreach ( false === $entries ? array() : $entries as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$this->remove( $path . '/' . $entry );
			}
		}
		rmdir( $path );
	}
}
