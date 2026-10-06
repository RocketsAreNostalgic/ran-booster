<?php

declare(strict_types=1);

namespace RAN\Tests\Secrets;

// Test fixtures deliberately exercise native filesystem semantics.

use PHPUnit\Framework\TestCase;
use RAN\Secrets\PosixFilesystemProbe;

final class PosixFilesystemProbeTest extends TestCase {

	private string $root;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/ran-booster-probe-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		mkdir( $this->root, 0700 );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		$this->remove( $this->root );
	}

	public function test_proves_required_operations_without_creating_the_secrets_file(): void {
		$candidate = $this->root . '/.ran-booster/0123456789abcdef/secrets.json';

		self::assertTrue( ( new PosixFilesystemProbe() )->probe( $candidate ) );
		self::assertFileDoesNotExist( $candidate );
		self::assertDirectoryExists( dirname( $candidate ) );
		self::assertSame( 0700, fileperms( dirname( $candidate ) ) & 0777 );
		self::assertSame( array(), glob( dirname( $candidate ) . '/.probe-*' ) );
	}

	public function test_rejects_paths_outside_the_fixed_candidate_shape(): void {
		self::assertFalse( ( new PosixFilesystemProbe() )->probe( $this->root . '/secrets.json' ) );
	}

	public function test_rejects_an_unsafe_existing_private_directory_without_changing_its_contents(): void {
		$private   = $this->root . '/.ran-booster';
		$sentinel  = $private . '/operator-owned-canary';
		$candidate = $private . '/0123456789abcdef/secrets.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $private, 0700 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
		self::assertNotFalse( file_put_contents( $sentinel, 'operator-owned-content' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $private, 0770 ) );

		self::assertFalse( ( new PosixFilesystemProbe() )->probe( $candidate ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local fixture/source bytes for the boundary assertion without WordPress filesystem indirection.
		self::assertSame( 'operator-owned-content', file_get_contents( $sentinel ) );
		self::assertDirectoryDoesNotExist( dirname( $candidate ) );
		self::assertFileDoesNotExist( $candidate );
	}

	private function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
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
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only disposable native filesystem entries owned by this fixture.
		rmdir( $path );
	}
}
