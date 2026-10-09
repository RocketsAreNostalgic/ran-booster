<?php

declare(strict_types=1);

namespace RAN\Tests\Logging;

// Direct local filesystem operations are the behavior under test.

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RAN\Logging\TemporaryDebugCapture;
use RuntimeException;

require_once __DIR__ . '/LoggingWordPressFunctions.php';

#[CoversClass( TemporaryDebugCapture::class )]
final class TemporaryDebugCaptureTest extends TestCase {

	private string $directory;
	private string $secrets_path;
	private string $capture_path;
	private int $now;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$this->directory    = sys_get_temp_dir() . '/ran-booster-debug-' . bin2hex( random_bytes( 8 ) );
		$this->secrets_path = $this->directory . '/custom-secrets.php';
		$this->capture_path = $this->directory . '/ran-booster-debug.php';
		$this->now          = strtotime( '2026-07-23T12:00:00Z' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		self::assertTrue( mkdir( $this->directory, 0700 ) );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		foreach (
			array(
				$this->capture_path,
				$this->capture_path . '.lock',
				$this->directory . '/link-target',
			) as $path
		) {
			if ( is_link( $path ) || is_file( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
				unlink( $path );
			}
		}

		if ( is_dir( $this->directory ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
			chmod( $this->directory, 0700 );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
			rmdir( $this->directory );
		}
	}

	public function test_capture_uses_fixed_sibling_and_supports_its_complete_lifecycle(): void {
		$capture = $this->capture();

		self::assertSame( 'inactive', $capture->snapshot()['state'] );

		$started = $capture->start();

		self::assertSame( 'active', $started['state'] );
		self::assertSame( '2026-07-23T13:00:00Z', $started['active_until'] );
		self::assertSame( '2026-07-24T13:00:00Z', $started['expires_at'] );
		self::assertSame( 0600, fileperms( $this->capture_path ) & 0777 );
		self::assertSame( 0600, fileperms( $this->capture_path . '.lock' ) & 0777 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		$bytes = file_get_contents( $this->capture_path );
		self::assertIsString( $bytes );
		self::assertStringStartsWith( "<?php exit; ?>\n", $bytes );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		$bytes = file_get_contents( $this->capture_path );
		self::assertIsString( $bytes );
		self::assertStringContainsString( '"owner":"ran-booster"', $bytes );

		self::assertTrue( $capture->append( "[ran-booster] first\nline" ) );
		$snapshot = $capture->snapshot();
		self::assertSame( 'active', $snapshot['state'] );
		self::assertSame(
			array(
				array(
					'at'   => '2026-07-23T12:00:00Z',
					'line' => '[ran-booster] first line',
				),
			),
			$snapshot['entries']
		);

		$this->now += 600;
		$stopped    = $capture->stop();
		self::assertSame( 'retained', $stopped['state'] );
		self::assertSame( '2026-07-23T12:10:00Z', $stopped['active_until'] );
		self::assertSame( '2026-07-24T12:10:00Z', $stopped['expires_at'] );
		self::assertFalse( $capture->append( '[ran-booster] ignored' ) );

		self::assertTrue( $capture->delete() );
		self::assertFileDoesNotExist( $this->capture_path );
		self::assertFalse( $capture->delete() );
		self::assertSame( 'inactive', $capture->snapshot()['state'] );
	}

	public function test_managed_storage_deletion_removes_owned_capture_and_exact_lock_idempotently(): void {
		$capture = $this->capture();
		$capture->start();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		$contents = file_get_contents( $this->capture_path );

		$capture->assert_managed_storage_deletable();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		self::assertSame( $contents, file_get_contents( $this->capture_path ) );

		$capture->delete_managed_storage();

		self::assertFileDoesNotExist( $this->capture_path );
		self::assertFileDoesNotExist( $this->capture_path . '.lock' );

		$capture->delete_managed_storage();
		self::assertFileDoesNotExist( $this->capture_path );
		self::assertFileDoesNotExist( $this->capture_path . '.lock' );
	}

	public function test_managed_storage_deletion_removes_an_orphaned_exact_lock(): void {
		$capture = $this->capture();
		$capture->start();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		self::assertTrue( unlink( $this->capture_path ) );

		$capture->delete_managed_storage();

		self::assertFileDoesNotExist( $this->capture_path );
		self::assertFileDoesNotExist( $this->capture_path . '.lock' );
	}

	public function test_managed_storage_deletion_secures_a_new_lock_when_the_capture_lock_is_missing(): void {
		$capture = $this->capture();
		$capture->start();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		self::assertTrue( unlink( $this->capture_path . '.lock' ) );

		$original_umask = umask( 0022 );
		try {
			$capture->delete_managed_storage();
		} finally {
			umask( $original_umask );
		}

		self::assertFileDoesNotExist( $this->capture_path );
		self::assertFileDoesNotExist( $this->capture_path . '.lock' );
	}

	public function test_managed_storage_deletion_retains_foreign_or_unsafe_material(): void {
		$capture = $this->capture();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		file_put_contents( $this->capture_path, "<?php exit; ?>\n{\"owner\":\"someone-else\"}\n" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		chmod( $this->capture_path, 0600 );

		$this->assert_mutation_refused(
			static function () use ( $capture ): void {
				$capture->delete_managed_storage();
			}
		);
		self::assertFileExists( $this->capture_path );
		self::assertFileExists( $this->capture_path . '.lock' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		unlink( $this->capture_path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		file_put_contents( $this->capture_path, 'unsafe' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		chmod( $this->capture_path, 0644 );
		$this->assert_mutation_refused(
			static function () use ( $capture ): void {
				$capture->delete_managed_storage();
			}
		);
		self::assertFileExists( $this->capture_path );
	}

	public function test_start_resets_an_owned_capture_and_natural_end_is_retained(): void {
		$capture = $this->capture();
		$capture->start();
		$capture->append( '[ran-booster] old' );

		$this->now += 3600;
		$retained   = $capture->snapshot();
		self::assertSame( 'retained', $retained['state'] );
		self::assertCount( 1, $retained['entries'] );
		self::assertFalse( $capture->append( '[ran-booster] too late' ) );

		$restarted = $capture->start();
		self::assertSame( 'active', $restarted['state'] );
		self::assertSame( array(), $restarted['entries'] );
		self::assertSame( '2026-07-23T14:00:00Z', $restarted['active_until'] );
	}

	public function test_legacy_active_capture_is_rejected_without_mutation(): void {
		$this->assert_legacy_metadata_rejected( null );
	}

	public function test_legacy_stopped_capture_is_rejected_without_mutation(): void {
		$this->assert_legacy_metadata_rejected( '2026-07-23T11:30:00Z' );
	}

	private function assert_legacy_metadata_rejected( ?string $stopped_at ): void {
		$metadata = array(
			'owner'        => 'ran-booster',
			'format'       => 1,
			'started_at'   => '2026-07-23T11:00:00Z',
			'active_until' => '2026-07-23T13:00:00Z',
			'stopped_at'   => $stopped_at,
			'expires_at'   => '2026-07-24T13:00:00Z',
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON encodes the isolated fixture without requiring WordPress bootstrap.
		$contents = "<?php exit; ?>\n" . json_encode( $metadata ) . "\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		file_put_contents( $this->capture_path, $contents );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		chmod( $this->capture_path, 0600 );

		$capture = $this->capture();
		self::assertSame( 'malformed', $capture->snapshot()['state'] );
		self::assertFalse( $capture->append( '[ran-booster] refused' ) );
		$this->assert_mutation_refused( static fn(): array => $capture->start() );
		$this->assert_mutation_refused( static fn(): array => $capture->stop() );
		$this->assert_mutation_refused( static fn(): bool => $capture->delete() );
		$this->assert_mutation_refused(
			static function () use ( $capture ): void {
				$capture->delete_managed_storage();
			}
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		self::assertSame( $contents, file_get_contents( $this->capture_path ) );
	}

	public function test_expired_capture_is_lazily_deleted_and_becomes_inactive(): void {
		$capture = $this->capture();
		$capture->start();
		$capture->append( '[ran-booster] expiring' );

		$this->now += 3600 + 86400;
		$snapshot   = $capture->snapshot();

		self::assertSame( 'inactive', $snapshot['state'] );
		self::assertSame( array(), $snapshot['entries'] );
		self::assertFileDoesNotExist( $this->capture_path );
		self::assertSame( 'inactive', $capture->snapshot()['state'] );
	}

	public function test_entry_count_entry_size_and_file_size_remain_bounded(): void {
		$capture = $this->capture();
		$capture->start();

		for ( $index = 0; $index < 405; ++$index ) {
			self::assertTrue( $capture->append( '[ran-booster] event-' . $index ) );
		}

		$snapshot = $capture->snapshot();
		self::assertCount( 400, $snapshot['entries'] );
		self::assertSame( '[ran-booster] event-5', $snapshot['entries'][0]['line'] );
		self::assertSame( '[ran-booster] event-404', $snapshot['entries'][399]['line'] );

		for ( $index = 0; $index < 100; ++$index ) {
			self::assertTrue( $capture->append( '[ran-booster] ' . str_repeat( 'x', 10000 ) . '-' . $index ) );
		}

		clearstatcache( true, $this->capture_path );
		self::assertLessThanOrEqual( 262144, filesize( $this->capture_path ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		$bytes = file_get_contents( $this->capture_path );
		self::assertIsString( $bytes );
		$lines = explode( "\n", $bytes );
		array_shift( $lines );
		array_shift( $lines );
		foreach ( array_filter( $lines, static fn( string $line ): bool => '' !== $line ) as $line ) {
			self::assertLessThanOrEqual( 4096, strlen( $line ) );
		}
	}

	public function test_foreign_malformed_and_unsafe_files_are_never_overwritten_or_deleted(): void {
		$capture = $this->capture();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		file_put_contents( $this->capture_path, "<?php exit; ?>\n{\"owner\":\"someone-else\"}\n" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		chmod( $this->capture_path, 0600 );
		self::assertSame( 'malformed', $capture->snapshot()['state'] );
		$this->assert_mutation_refused( static fn(): array => $capture->start() );
		$this->assert_mutation_refused( static fn(): bool => $capture->delete() );
		self::assertFileExists( $this->capture_path );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		unlink( $this->capture_path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		file_put_contents( $this->capture_path, "not-a-capture\n" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		chmod( $this->capture_path, 0600 );
		self::assertSame( 'malformed', $capture->snapshot()['state'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		unlink( $this->capture_path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		file_put_contents( $this->directory . '/link-target', 'foreign' );
		symlink( $this->directory . '/link-target', $this->capture_path );
		// @phpstan-ignore staticMethod.alreadyNarrowedType (Repeat the assertion after external database filesystem or scheduler state changes; earlier narrowing must not replace the runtime check.)
		self::assertSame( 'malformed', $capture->snapshot()['state'] );
		$this->assert_mutation_refused( static fn(): array => $capture->start() );
		self::assertTrue( is_link( $this->capture_path ) );
	}

	public function test_hard_linked_capture_and_lock_do_not_block_capture_lifecycle(): void {
		$capture = $this->capture();
		$capture->start();

		self::assertTrue( link( $this->capture_path, $this->directory . '/link-target' ) );
		self::assertSame( 'active', $capture->snapshot()['state'] );
		self::assertTrue( $capture->append( '[ran-booster] accepted' ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		unlink( $this->directory . '/link-target' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		unlink( $this->capture_path . '.lock' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		file_put_contents( $this->directory . '/link-target', '' );
		self::assertTrue( link( $this->directory . '/link-target', $this->capture_path . '.lock' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		chmod( $this->capture_path . '.lock', 0600 );
		self::assertSame( 'active', $capture->snapshot()['state'] );
	}

	public function test_symlinked_lock_is_refused(): void {
		$capture = $this->capture();
		$capture->start();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		unlink( $this->capture_path . '.lock' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		file_put_contents( $this->directory . '/link-target', 'foreign' );
		self::assertTrue( symlink( $this->directory . '/link-target', $this->capture_path . '.lock' ) );

		self::assertSame( 'unavailable', $capture->snapshot()['state'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		self::assertSame( 'foreign', file_get_contents( $this->directory . '/link-target' ) );
	}

	public function test_unavailable_location_and_append_failures_are_fail_open(): void {
		$capture = new TemporaryDebugCapture( $this->directory . '/missing/secrets.json' );

		self::assertSame( 'unavailable', $capture->snapshot()['state'] );
		self::assertFalse( $capture->append( '[ran-booster] ignored' ) );
		$this->assert_mutation_refused( static fn(): array => $capture->start() );

		$available = $this->capture();
		$available->start();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		chmod( $this->capture_path, 0644 );
		self::assertSame( 'malformed', $available->snapshot()['state'] );
		self::assertFalse( $available->append( '[ran-booster] fail open' ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		chmod( $this->capture_path, 0600 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		chmod( $this->directory, 0755 );
		self::assertSame( 'unavailable', $available->snapshot()['state'] );
		$this->assert_mutation_refused( static fn(): array => $available->start() );
	}

	private function capture(): TemporaryDebugCapture {
		return new TemporaryDebugCapture(
			$this->secrets_path,
			fn(): int => $this->now
		);
	}

	private function assert_mutation_refused( callable $operation ): void {
		try {
			$operation();
			self::fail( 'Expected an unsafe capture mutation to be refused.' );
		} catch ( RuntimeException ) {
			$this->addToAssertionCount( 1 );
		}
	}
}
