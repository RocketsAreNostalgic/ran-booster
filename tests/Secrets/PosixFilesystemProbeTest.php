<?php

declare(strict_types=1);

namespace RAN\Tests\Secrets;

// Test fixtures deliberately exercise native filesystem semantics.

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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
		$candidate = $this->root . '/.ran-booster/0123456789abcdef0123456789abcdef/ran-booster-store.json';
		$base_dir  = $this->root . '/.ran-booster';

		self::assertTrue( ( new PosixFilesystemProbe() )->probe( $candidate ) );
		self::assertFileDoesNotExist( $candidate );
		self::assertDirectoryExists( dirname( $candidate ) );
		self::assertSame( 0700, fileperms( dirname( $candidate ) ) & 0777 );
		self::assertSame( array(), glob( dirname( $candidate ) . '/.probe-*' ) );
		self::assertFileExists( $base_dir . '/.htaccess' );
		self::assertFileExists( $base_dir . '/index.php' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Verify exact defense-in-depth deny stub bytes created by probe.
		self::assertStringContainsString( 'Require all denied', (string) file_get_contents( $base_dir . '/.htaccess' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Verify exact defense-in-depth index.php stub bytes created by probe.
		self::assertStringContainsString( '<?php', (string) file_get_contents( $base_dir . '/index.php' ) );
		self::assertSame( 0600, fileperms( $base_dir . '/.htaccess' ) & 0777 );
		self::assertSame( 0600, fileperms( $base_dir . '/index.php' ) & 0777 );
	}

	public function test_accepts_legacy_16_hex_candidate_and_preserves_existing_deny_stubs(): void {
		$base_dir  = $this->root . '/.ran-booster';
		$candidate = $base_dir . '/0123456789abcdef/ran-booster-store.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create pre-existing private directory with operator-owned stubs.
		self::assertTrue( mkdir( $base_dir, 0700 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seed existing .htaccess stub that probe must preserve without error.
		self::assertNotFalse( file_put_contents( $base_dir . '/.htaccess', "# custom\nRequire all denied\n" ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seed existing index.php stub that probe must preserve without error.
		self::assertNotFalse( file_put_contents( $base_dir . '/index.php', "<?php // custom\n" ) );

		self::assertTrue( ( new PosixFilesystemProbe() )->probe( $candidate ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Confirm existing .htaccess bytes were preserved untouched.
		self::assertSame( "# custom\nRequire all denied\n", file_get_contents( $base_dir . '/.htaccess' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Confirm existing index.php bytes were preserved untouched.
		self::assertSame( "<?php // custom\n", file_get_contents( $base_dir . '/index.php' ) );
	}

	public function test_rejects_symlinked_deny_stub_inside_private_root(): void {
		$base_dir  = $this->root . '/.ran-booster';
		$target    = $this->root . '/outside-file';
		$candidate = $base_dir . '/0123456789abcdef0123456789abcdef/ran-booster-store.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create private directory for symlink stub rejection test.
		self::assertTrue( mkdir( $base_dir, 0700 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Create external symlink target that must not be overwritten.
		self::assertNotFalse( file_put_contents( $target, 'untouched' ) );
		self::assertTrue( symlink( $target, $base_dir . '/.htaccess' ) );

		self::assertFalse( ( new PosixFilesystemProbe() )->probe( $candidate ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Confirm symlink target was not modified.
		self::assertSame( 'untouched', file_get_contents( $target ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_failed_deny_stub_keeps_the_entry_if_its_handle_identity_cannot_be_proved(): void {
		require __DIR__ . '/ZeroInodeStatFunctions.php';
		$GLOBALS['ran_booster_fixture_zero_handle_inode_only'] = true;
		$base = $this->root . '/.ran-booster';
		self::assertFalse( ( new PosixFilesystemProbe() )->probe( $base . '/0123456789abcdef/ran-booster-store.json' ) );
		self::assertFileExists( $base . '/.htaccess' );
		self::assertFileDoesNotExist( $base . '/index.php' );
		self::assertDirectoryDoesNotExist( $base . '/0123456789abcdef' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_probe_finalizer_preserves_an_entry_when_its_creating_handle_cannot_be_verified(): void {
		require __DIR__ . '/ZeroInodeStatFunctions.php';
		$GLOBALS['ran_booster_fixture_zero_handle_inode_only']          = true;
		$GLOBALS['ran_booster_fixture_handle_checks_before_zero_inode'] = 3;
		$site = $this->root . '/.ran-booster/0123456789abcdef';

		self::assertFalse( ( new PosixFilesystemProbe() )->probe( $site . '/ran-booster-store.json' ) );
		$entries = glob( $site . '/.probe-*' );
		self::assertIsArray( $entries );
		self::assertCount( 1, $entries );
		self::assertFileExists( $entries[0] );
		self::assertFileDoesNotExist( $site . '/ran-booster-store.json' );
	}

	public function test_rejects_paths_outside_the_fixed_candidate_shape(): void {
		self::assertFalse( ( new PosixFilesystemProbe() )->probe( $this->root . '/ran-booster-store.json' ) );
	}

	public function test_rejects_the_old_basename_before_creating_private_directories(): void {
		$private   = $this->root . '/.ran-booster';
		$candidate = $private . '/0123456789abcdef/secrets.json';

		self::assertFalse( ( new PosixFilesystemProbe() )->probe( $candidate ) );
		self::assertDirectoryDoesNotExist( $private );
	}

	public function test_rejects_an_unsafe_existing_private_directory_without_changing_its_contents(): void {
		$private   = $this->root . '/.ran-booster';
		$sentinel  = $private . '/operator-owned-canary';
		$candidate = $private . '/0123456789abcdef/ran-booster-store.json';
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
