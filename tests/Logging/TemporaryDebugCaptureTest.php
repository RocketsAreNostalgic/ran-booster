<?php

declare(strict_types=1);

namespace Tests\Logging;

// Direct local filesystem operations are the behavior under test.
// phpcs:disable WordPress.WP.AlternativeFunctions

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RAN\Logging\TemporaryDebugCapture;
use RuntimeException;

require_once __DIR__ . '/LoggingWordPressFunctions.php';

#[CoversClass( TemporaryDebugCapture::class )]
final class TemporaryDebugCaptureTest extends TestCase {

	private string $directory;
	private string $secretsPath;
	private string $capturePath;
	private int $now;

	protected function setUp(): void {
		$this->directory   = sys_get_temp_dir() . '/ran-booster-debug-' . bin2hex( random_bytes( 8 ) );
		$this->secretsPath = $this->directory . '/custom-secrets.php';
		$this->capturePath = $this->directory . '/ran-booster-debug.php';
		$this->now         = strtotime( '2026-07-23T12:00:00Z' );

		self::assertTrue( mkdir( $this->directory, 0700 ) );
	}

	protected function tearDown(): void {
		foreach (
			array(
				$this->capturePath,
				$this->capturePath . '.lock',
				$this->directory . '/link-target',
			) as $path
		) {
			if ( is_link( $path ) || is_file( $path ) ) {
				unlink( $path );
			}
		}

		if ( is_dir( $this->directory ) ) {
			chmod( $this->directory, 0700 );
			rmdir( $this->directory );
		}
	}

	public function testCaptureUsesFixedSiblingAndSupportsItsCompleteLifecycle(): void {
		$capture = $this->capture();

		self::assertSame( 'inactive', $capture->snapshot()['state'] );

		$started = $capture->start();

		self::assertSame( 'active', $started['state'] );
		self::assertSame( '2026-07-23T13:00:00Z', $started['active_until'] );
		self::assertSame( '2026-07-24T13:00:00Z', $started['expires_at'] );
		self::assertSame( 0600, fileperms( $this->capturePath ) & 0777 );
		self::assertSame( 0600, fileperms( $this->capturePath . '.lock' ) & 0777 );
		self::assertStringStartsWith( "<?php exit; ?>\n", file_get_contents( $this->capturePath ) );
		self::assertStringContainsString( '"owner":"ran-booster"', file_get_contents( $this->capturePath ) );

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
		self::assertFileDoesNotExist( $this->capturePath );
		self::assertFalse( $capture->delete() );
		self::assertSame( 'inactive', $capture->snapshot()['state'] );
	}

	public function testManagedStorageDeletionRemovesOwnedCaptureAndExactLockIdempotently(): void {
		$capture = $this->capture();
		$capture->start();
		$contents = file_get_contents( $this->capturePath );

		$capture->assertManagedStorageDeletable();
		self::assertSame( $contents, file_get_contents( $this->capturePath ) );

		$capture->deleteManagedStorage();

		self::assertFileDoesNotExist( $this->capturePath );
		self::assertFileDoesNotExist( $this->capturePath . '.lock' );

		$capture->deleteManagedStorage();
		self::assertFileDoesNotExist( $this->capturePath );
		self::assertFileDoesNotExist( $this->capturePath . '.lock' );
	}

	public function testManagedStorageDeletionRemovesAnOrphanedExactLock(): void {
		$capture = $this->capture();
		$capture->start();
		self::assertTrue( unlink( $this->capturePath ) );

		$capture->deleteManagedStorage();

		self::assertFileDoesNotExist( $this->capturePath );
		self::assertFileDoesNotExist( $this->capturePath . '.lock' );
	}

	public function testManagedStorageDeletionSecuresANewLockWhenTheCaptureLockIsMissing(): void {
		$capture = $this->capture();
		$capture->start();
		self::assertTrue( unlink( $this->capturePath . '.lock' ) );

		$originalUmask = umask( 0022 );
		try {
			$capture->deleteManagedStorage();
		} finally {
			umask( $originalUmask );
		}

		self::assertFileDoesNotExist( $this->capturePath );
		self::assertFileDoesNotExist( $this->capturePath . '.lock' );
	}

	public function testManagedStorageDeletionRetainsForeignOrUnsafeMaterial(): void {
		$capture = $this->capture();
		file_put_contents( $this->capturePath, "<?php exit; ?>\n{\"owner\":\"someone-else\"}\n" );
		chmod( $this->capturePath, 0600 );

		$this->assertMutationRefused(
			static function () use ( $capture ): void {
				$capture->deleteManagedStorage();
			}
		);
		self::assertFileExists( $this->capturePath );
		self::assertFileExists( $this->capturePath . '.lock' );

		unlink( $this->capturePath );
		file_put_contents( $this->capturePath, 'unsafe' );
		chmod( $this->capturePath, 0644 );
		$this->assertMutationRefused(
			static function () use ( $capture ): void {
				$capture->deleteManagedStorage();
			}
		);
		self::assertFileExists( $this->capturePath );
	}

	public function testStartResetsAnOwnedCaptureAndNaturalEndIsRetained(): void {
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

	public function testLegacyLifecycleMetadataRemainsReadableAndIsRemovedOnWrite(): void {
		$metadata = array(
			'owner'        => 'ran-booster',
			'format'       => 1,
			'started_at'   => '2026-07-23T12:00:00Z',
			'active_until' => '2026-07-23T13:00:00Z',
			'stopped_at'   => null,
			'expires_at'   => '2026-07-24T13:00:00Z',
		);
		file_put_contents( $this->capturePath, "<?php exit; ?>\n" . json_encode( $metadata ) . "\n" );
		chmod( $this->capturePath, 0600 );

		$capture = $this->capture();
		self::assertSame( 'active', $capture->snapshot()['state'] );
		self::assertSame( 'retained', $capture->stop()['state'] );

		$contents = file_get_contents( $this->capturePath );
		self::assertStringNotContainsString( 'started_at', $contents );
		self::assertStringNotContainsString( 'stopped_at', $contents );
	}

	public function testLegacyStoppedCaptureRemainsRetainedUntilItIsRestarted(): void {
		$metadata = array(
			'owner'        => 'ran-booster',
			'format'       => 1,
			'started_at'   => '2026-07-23T11:00:00Z',
			'active_until' => '2026-07-23T13:00:00Z',
			'stopped_at'   => '2026-07-23T11:30:00Z',
			'expires_at'   => '2026-07-24T11:30:00Z',
		);
		file_put_contents( $this->capturePath, "<?php exit; ?>\n" . json_encode( $metadata ) . "\n" );
		chmod( $this->capturePath, 0600 );

		$capture = $this->capture();
		self::assertSame( 'retained', $capture->snapshot()['state'] );
		self::assertFalse( $capture->append( '[ran-booster] refused' ) );
		self::assertSame( 'active', $capture->start()['state'] );

		$contents = file_get_contents( $this->capturePath );
		self::assertStringNotContainsString( 'started_at', $contents );
		self::assertStringNotContainsString( 'stopped_at', $contents );
	}

	public function testExpiredCaptureIsLazilyDeletedAndBecomesInactive(): void {
		$capture = $this->capture();
		$capture->start();
		$capture->append( '[ran-booster] expiring' );

		$this->now += 3600 + 86400;
		$snapshot   = $capture->snapshot();

		self::assertSame( 'inactive', $snapshot['state'] );
		self::assertSame( array(), $snapshot['entries'] );
		self::assertFileDoesNotExist( $this->capturePath );
		self::assertSame( 'inactive', $capture->snapshot()['state'] );
	}

	public function testEntryCountEntrySizeAndFileSizeRemainBounded(): void {
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

		clearstatcache( true, $this->capturePath );
		self::assertLessThanOrEqual( 262144, filesize( $this->capturePath ) );

		$lines = explode( "\n", file_get_contents( $this->capturePath ) );
		array_shift( $lines );
		array_shift( $lines );
		foreach ( array_filter( $lines, static fn( string $line ): bool => '' !== $line ) as $line ) {
			self::assertLessThanOrEqual( 4096, strlen( $line ) );
		}
	}

	public function testForeignMalformedAndUnsafeFilesAreNeverOverwrittenOrDeleted(): void {
		$capture = $this->capture();

		file_put_contents( $this->capturePath, "<?php exit; ?>\n{\"owner\":\"someone-else\"}\n" );
		chmod( $this->capturePath, 0600 );
		self::assertSame( 'malformed', $capture->snapshot()['state'] );
		$this->assertMutationRefused( static fn(): array => $capture->start() );
		$this->assertMutationRefused( static fn(): bool => $capture->delete() );
		self::assertFileExists( $this->capturePath );

		unlink( $this->capturePath );
		file_put_contents( $this->capturePath, "not-a-capture\n" );
		chmod( $this->capturePath, 0600 );
		self::assertSame( 'malformed', $capture->snapshot()['state'] );

		unlink( $this->capturePath );
		file_put_contents( $this->directory . '/link-target', 'foreign' );
		symlink( $this->directory . '/link-target', $this->capturePath );
		self::assertSame( 'malformed', $capture->snapshot()['state'] );
		$this->assertMutationRefused( static fn(): array => $capture->start() );
		self::assertTrue( is_link( $this->capturePath ) );
	}

	public function testHardLinkedCaptureAndLockDoNotBlockCaptureLifecycle(): void {
		$capture = $this->capture();
		$capture->start();

		self::assertTrue( link( $this->capturePath, $this->directory . '/link-target' ) );
		self::assertSame( 'active', $capture->snapshot()['state'] );
		self::assertTrue( $capture->append( '[ran-booster] accepted' ) );

		unlink( $this->directory . '/link-target' );
		unlink( $this->capturePath . '.lock' );
		file_put_contents( $this->directory . '/link-target', '' );
		self::assertTrue( link( $this->directory . '/link-target', $this->capturePath . '.lock' ) );
		chmod( $this->capturePath . '.lock', 0600 );
		self::assertSame( 'active', $capture->snapshot()['state'] );
	}

	public function testSymlinkedLockIsRefused(): void {
		$capture = $this->capture();
		$capture->start();
		unlink( $this->capturePath . '.lock' );
		file_put_contents( $this->directory . '/link-target', 'foreign' );
		self::assertTrue( symlink( $this->directory . '/link-target', $this->capturePath . '.lock' ) );

		self::assertSame( 'unavailable', $capture->snapshot()['state'] );
		self::assertSame( 'foreign', file_get_contents( $this->directory . '/link-target' ) );
	}

	public function testUnavailableLocationAndAppendFailuresAreFailOpen(): void {
		$capture = new TemporaryDebugCapture( $this->directory . '/missing/secrets.json' );

		self::assertSame( 'unavailable', $capture->snapshot()['state'] );
		self::assertFalse( $capture->append( '[ran-booster] ignored' ) );
		$this->assertMutationRefused( static fn(): array => $capture->start() );

		$available = $this->capture();
		$available->start();
		chmod( $this->capturePath, 0644 );
		self::assertSame( 'malformed', $available->snapshot()['state'] );
		self::assertFalse( $available->append( '[ran-booster] fail open' ) );

		chmod( $this->capturePath, 0600 );
		chmod( $this->directory, 0755 );
		self::assertSame( 'unavailable', $available->snapshot()['state'] );
		$this->assertMutationRefused( static fn(): array => $available->start() );
	}

	private function capture(): TemporaryDebugCapture {
		return new TemporaryDebugCapture(
			$this->secretsPath,
			fn(): int => $this->now
		);
	}

	private function assertMutationRefused( callable $operation ): void {
		try {
			$operation();
			self::fail( 'Expected an unsafe capture mutation to be refused.' );
		} catch ( RuntimeException ) {
			self::assertTrue( true );
		}
	}
}
