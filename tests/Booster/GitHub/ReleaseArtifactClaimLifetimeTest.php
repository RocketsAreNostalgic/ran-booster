<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

require_once __DIR__ . '/ReleaseArtifactFilesystemFunctions.php';

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\GitHubReleaseArtifact;
use RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact;
use RAN\WPReleaseUpdater\V1\Provider\GitHub\ProspectiveReleaseArtifact;
use RAN\WPReleaseUpdater\V1\Provider\GitHub\ProspectiveReleaseInspection;
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

		/** @return array{GitHubReleaseArtifact, string} */
	private function artifact(): array {
		require_once dirname( __DIR__, 3 ) . '/../ran-wp-release-updater/runtime.php';
		$path = tempnam( sys_get_temp_dir(), 'ran-booster-real-release-artifact-' );
		self::assertIsString( $path );
		file_put_contents( $path, 'verified-release-archive' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only artifact.
		chmod( $path, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test-only custody fixture.
		$stat = lstat( $path );
		self::assertIsArray( $stat );
		$identity = array(
			'dev'   => $stat['dev'],
			'ino'   => $stat['ino'],
			'mode'  => $stat['mode'],
			'nlink' => $stat['nlink'],
			'uid'   => $stat['uid'],
			'gid'   => $stat['gid'],
			'size'  => $stat['size'],
			'mtime' => $stat['mtime'],
			'ctime' => $stat['ctime'],
		);
		$digest   = hash_file( 'sha256', $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_hash_file -- Test-only artifact identity.
		self::assertIsString( $digest );
		$temporary  = new TemporaryArtifact( $path, $digest, $identity );
		$inspection = ProspectiveReleaseInspection::create(
			array(
				'artifact_filename'         => 'example.zip',
				'artifact_identity'         => '8',
				'artifact_sha256'           => $digest,
				'artifact_size'             => strlen( 'verified-release-archive' ),
				'assurance_facts'           => array(
					'exact_artifact_identity'       => true,
					'exact_commit_identity'         => true,
					'exact_reacquisition_supported' => true,
					'exact_release_identity'        => true,
					'provenance_verified'           => true,
					'publication_immutable'         => true,
					'repository_identity_stable'    => true,
					'trusted_digest_source'         => true,
				),
				'canonical_update_uri'      => 'https://github.com/owner/example',
				'channel'                   => 'stable',
				'commit_identity'           => str_repeat( 'a', 40 ),
				'main_file'                 => 'example.php',
				'package_root'              => 'example',
				'php_runtime_version'       => '8.2.0',
				'release_identity'          => '42',
				'repository_identity'       => '123456789',
				'repository_locator'        => 'owner/example',
				'tag'                       => 'v1.2.3',
				'target_type'               => 'plugin',
				'version'                   => '1.2.3',
				'wordpress_runtime_version' => '6.8.0',
			)
		);

		return array(
			new GitHubReleaseArtifact(
				new ProspectiveReleaseArtifact( $inspection, $temporary ),
				'1.2.3',
				str_repeat( 'a', 40 ),
				'example',
				'example.php'
			),
			$path,
		);
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
