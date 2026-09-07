<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Structural public-artifact fixture belongs beside its custody cases.

require_once __DIR__ . '/ReleaseArtifactFilesystemFunctions.php';

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\GitHubReleaseArtifact;
use RuntimeException;

final class ReleaseArtifactClaimLifetimeTest extends TestCase {
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
	public function testProviderArtifactIsCopiedIntoCoreCustodyBeforeProviderCleanup(): void {
		$path     = '';
		$artifact = null;

		try {
			$this->resetFilesystemHooks();
			[ $artifact, $path ] = $this->artifact();

			$prepared = $artifact->handoffToCore();
			self::assertFileDoesNotExist( $path );
			$prepared->assertUnchanged();
			$ownedPath = $prepared->getPath();
			$prepared->cleanup();
			self::assertFileDoesNotExist( $ownedPath );
			self::assertDirectoryDoesNotExist( dirname( $ownedPath ) );
		} finally {
			$this->resetFilesystemHooks();
			if ( is_file( $path ) ) {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only fallback cleanup.
			}
		}
	}

		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
	public function testMkdirCollisionNeverRemovesPreexistingDirectory(): void {
		$this->resetFilesystemHooks();
		$random    = str_repeat( "\x31", 16 );
		$directory = sys_get_temp_dir() . '/ran-booster-release-' . bin2hex( $random );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test-only exact collision fixture.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$before = lstat( $directory );
		self::assertIsArray( $before );
		$path = '';

		try {
			$GLOBALS['ran_booster_custody_random_bytes'] = $random;
			[ $artifact, $path ]                         = $this->artifact();
			$this->expectHandoffFailure( $artifact );

			self::assertFileDoesNotExist( $path );
			self::assertDirectoryExists( $directory );
			self::assertSame( $before, lstat( $directory ) );
		} finally {
			$this->resetFilesystemHooks();
			$this->removeExactPath( $path );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test-only exact collision fixture cleanup.
			rmdir( $directory );
		}
	}

		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
	public function testMkdirFailureDoesNotAttemptPathCleanup(): void {
		$this->resetFilesystemHooks();
		$random    = str_repeat( "\x32", 16 );
		$directory = sys_get_temp_dir() . '/ran-booster-release-' . bin2hex( $random );
		$path      = '';

		try {
			$GLOBALS['ran_booster_custody_random_bytes']  = $random;
			$GLOBALS['ran_booster_custody_mkdir_failure'] = true;
			[ $artifact, $path ]                          = $this->artifact();
			$this->expectHandoffFailure( $artifact );

			self::assertFileDoesNotExist( $path );
			self::assertDirectoryDoesNotExist( $directory );
		} finally {
			$this->resetFilesystemHooks();
			$this->removeExactPath( $path );
		}
	}

		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
	public function testDirectorySwapBeforeDestinationCreationLeavesReplacementUntouched(): void {
		$this->resetFilesystemHooks();
		$random       = str_repeat( "\x33", 16 );
		$directory    = sys_get_temp_dir() . '/ran-booster-release-' . bin2hex( $random );
		$quarantine   = $directory . '-original';
		$sentinel     = $directory . '/unrelated.txt';
		$providerPath = '';

		try {
			$GLOBALS['ran_booster_custody_random_bytes']      = $random;
			$GLOBALS['ran_booster_custody_after_source_open'] = static function () use ( $directory, $quarantine, $sentinel ): void {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Test-only deterministic directory replacement.
				rename( $directory, $quarantine );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test-only deterministic directory replacement.
				mkdir( $directory, 0700 );
				file_put_contents( $sentinel, 'unrelated' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only replacement sentinel.
			};
				[ $artifact, $providerPath ]                  = $this->artifact();
				$this->expectHandoffFailure( $artifact );

				self::assertFileDoesNotExist( $providerPath );
				self::assertSame( 'unrelated', file_get_contents( $sentinel ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only replacement sentinel.
				self::assertFileDoesNotExist( $directory . '/archive.zip' );
				self::assertDirectoryExists( $quarantine );
		} finally {
			$this->resetFilesystemHooks();
			$this->removeExactPath( $providerPath );
			$this->removeExactPath( $sentinel );
			$this->removeExactPath( $directory . '/archive.zip' );
			$this->removeExactDirectory( $directory );
			$this->removeExactDirectory( $quarantine );
		}
	}

		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
	public function testDirectoryIdentityDriftPreventsFailureCleanup(): void {
		$this->resetFilesystemHooks();
		$random       = str_repeat( "\x34", 16 );
		$directory    = sys_get_temp_dir() . '/ran-booster-release-' . bin2hex( $random );
		$archive      = $directory . '/archive.zip';
		$providerPath = '';

		try {
			$GLOBALS['ran_booster_custody_random_bytes']           = $random;
			$GLOBALS['ran_booster_custody_after_destination_open'] = static function () use ( $directory ): void {
				chmod( $directory, 0755 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test-only identity drift.
			};
				[ $artifact, $providerPath ]                       = $this->artifact();
				$this->expectHandoffFailure( $artifact );

				self::assertFileDoesNotExist( $providerPath );
				self::assertFileExists( $archive );
				self::assertSame( 'verified-release-archive', file_get_contents( $archive ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only retained fail-closed copy.
				self::assertSame( 0755, fileperms( $directory ) & 0777 );
		} finally {
			$this->resetFilesystemHooks();
			$this->removeExactPath( $providerPath );
			$this->removeExactPath( $archive );
			$this->removeExactDirectory( $directory );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testFirstFailedHandoffDiscardCannotBeHiddenByRetry(): void {
		$this->resetFilesystemHooks();
		$path = $this->archivePath();

		try {
			$source   = new StructuralReleaseArtifact( $path, array( false, true ) );
			$artifact = $this->artifactFromSource( $source );
			$this->expectHandoffFailure( $artifact );

			self::assertSame( 1, $source->discardCalls );
			self::assertFalse( $artifact->discard() );
			self::assertSame( 1, $source->discardCalls );
		} finally {
			$this->resetFilesystemHooks();
			$this->removeExactPath( $path );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testThrownHandoffDiscardCannotBeHiddenByLaterSuccess(): void {
		$this->resetFilesystemHooks();
		$path = $this->archivePath();

		try {
			$source   = new ThrowingDiscardStructuralReleaseArtifact( $path );
			$artifact = $this->artifactFromSource( $source );
			$this->expectHandoffFailure( $artifact );

			self::assertSame( 1, $source->discardCalls );
			self::assertFalse( $artifact->discard() );
			self::assertSame( 1, $source->discardCalls );
		} finally {
			$this->resetFilesystemHooks();
			$this->removeExactPath( $path );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testPostReaderRuntimeLossRemovesProvisionalCopy(): void {
		$this->resetFilesystemHooks();
		$path = $this->archivePath();

		try {
			$source   = new FaultingStructuralReleaseArtifact( $path, false );
			$artifact = $this->artifactFromSource( $source );
			$this->expectHandoffFailure( $artifact );

			self::assertNotNull( $source->prepared );
			self::assertFileDoesNotExist( $source->prepared->getPath() );
			self::assertDirectoryDoesNotExist( dirname( $source->prepared->getPath() ) );
			self::assertFileDoesNotExist( $path );
		} finally {
			$this->resetFilesystemHooks();
			$this->removeExactPath( $path );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testPreparedCopyCleanupUncertaintyRemainsReportable(): void {
		$this->resetFilesystemHooks();
		$path = $this->archivePath();

		try {
			$source   = new FaultingStructuralReleaseArtifact( $path, true );
			$artifact = $this->artifactFromSource( $source );
			$this->expectHandoffFailure( $artifact );

			self::assertNotNull( $source->prepared );
			self::assertFileExists( $source->prepared->getPath() );
			self::assertFalse( $artifact->discard() );
			chmod( $source->prepared->getPath(), 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test-only retained-copy cleanup.
			$source->prepared->cleanup();
		} finally {
			$this->resetFilesystemHooks();
			$this->removeExactPath( $path );
		}
	}

		/** @return array{GitHubReleaseArtifact, string} */
	private function artifact(): array {
		$path   = $this->archivePath();
		$digest = hash_file( 'sha256', $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_hash_file -- Test-only artifact identity.
		self::assertIsString( $digest );

		return array(
			new GitHubReleaseArtifact(
				new StructuralReleaseArtifact( $path ),
				'1.2.3',
				str_repeat( 'a', 40 ),
				'example',
				'example.php',
				strlen( 'verified-release-archive' ),
				52428800,
				$digest
			),
			$path,
		);
	}

	private function archivePath(): string {
		$path = tempnam( sys_get_temp_dir(), 'ran-booster-real-release-artifact-' );
		self::assertIsString( $path );
		file_put_contents( $path, 'verified-release-archive' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only artifact.
		chmod( $path, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test-only custody fixture.

		return $path;
	}

	private function artifactFromSource( object $source ): GitHubReleaseArtifact {
		return new GitHubReleaseArtifact( $source, '1.2.3', str_repeat( 'a', 40 ), 'example', 'example.php', strlen( 'verified-release-archive' ), 52428800, hash( 'sha256', 'verified-release-archive' ) );
	}

	private function expectHandoffFailure( GitHubReleaseArtifact $artifact ): void {
		try {
			$artifact->handoffToCore();
			self::fail( 'Unsafe custody handoff must fail closed.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'The GitHub release artifact could not be prepared.', $exception->getMessage() );
		}
	}

	private function resetFilesystemHooks(): void {
		unset(
			$GLOBALS['ran_booster_custody_random_bytes'],
			$GLOBALS['ran_booster_custody_mkdir_failure'],
			$GLOBALS['ran_booster_custody_after_source_open'],
			$GLOBALS['ran_booster_custody_after_destination_open']
		);
	}

	private function removeExactPath( string $path ): void {
		if ( '' !== $path && ( is_file( $path ) || is_link( $path ) ) ) {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only exact cleanup.
		}
	}

	private function removeExactDirectory( string $directory ): void {
		if ( is_dir( $directory ) && ! is_link( $directory ) ) {
			rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test-only exact cleanup.
		}
	}
}

final class StructuralReleaseArtifact {
	public int $discardCalls = 0;

	/** @param list<bool> $discardResults */
	public function __construct( private string $path, private array $discardResults = array() ) {}

	public function inspect( callable $reader ): mixed {
		return $reader( $this->path );
	}

	public function discard(): bool {
		++$this->discardCalls;
		if ( array() !== $this->discardResults ) {
			return array_shift( $this->discardResults );
		}
		if ( is_file( $this->path ) ) {
			unlink( $this->path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture discards its exact temporary artifact.
		}

		return ! file_exists( $this->path );
	}
}

final class FaultingStructuralReleaseArtifact {
	public ?\RAN\Deployment\PreparedArtifact $prepared = null;

	public function __construct( private string $path, private bool $breakPreparedCopy ) {}

	public function inspect( callable $reader ): mixed {
		$this->prepared = $reader( $this->path );
		if ( $this->breakPreparedCopy ) {
			chmod( $this->prepared->getPath(), 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test-only prepared-copy identity drift.
		}

		throw new RuntimeException( 'Provider artifact runtime became unavailable.' );
	}

	public function discard(): bool {
		if ( is_file( $this->path ) ) {
			unlink( $this->path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture discards its exact temporary artifact.
		}

		return ! file_exists( $this->path );
	}
}

final class ThrowingDiscardStructuralReleaseArtifact {
	public int $discardCalls = 0;

	public function __construct( private string $path ) {}

	public function inspect( callable $reader ): mixed {
		return $reader( $this->path );
	}

	public function discard(): bool {
		++$this->discardCalls;
		if ( 1 === $this->discardCalls ) {
			throw new RuntimeException( 'The provider discard operation failed.' );
		}
		if ( is_file( $this->path ) ) {
			unlink( $this->path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture discards its exact temporary artifact.
		}

		return ! file_exists( $this->path );
	}
}
