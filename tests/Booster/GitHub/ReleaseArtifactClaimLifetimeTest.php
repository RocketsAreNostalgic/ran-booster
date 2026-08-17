<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\GitHubReleaseArtifact;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ClaimedArtifact;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\VerifiedArtifact;
use RAN\WPGitHubReleaseUpdater\V1\Http\TemporaryFileFactory;
use RuntimeException;

final class ReleaseArtifactClaimLifetimeTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testRealUpdaterClaimSurvivesUntilPreparedArtifactCleanup(): void {
		$root = dirname( __DIR__, 3 );
		require_once $root . '/tests/Support/WPError.php';
		require_once $root . '/vendor/ran/wp-github-release-updater/src/Http/TemporaryFileFactory.php';
		require_once $root . '/vendor/ran/wp-github-release-updater/src/Artifact/VerifiedArtifact.php';
		require_once $root . '/vendor/ran/wp-github-release-updater/src/Artifact/ClaimedArtifact.php';

		$path = tempnam( sys_get_temp_dir(), 'ran-booster-real-release-claim-' );
		if ( false === $path ) {
			self::fail( 'The real release-claim fixture could not be created.' );
		}

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only temporary artifact.
			file_put_contents( $path, 'verified-release-archive' );
			chmod( $path, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test-only custody fixture.
			$identity = VerifiedArtifact::fileIdentity( $path );
			$digest   = hash_file( 'sha256', $path );
			self::assertIsArray( $identity );
			self::assertIsString( $digest );
			$claim    = new ClaimedArtifact(
				$path,
				$digest,
				new class() implements TemporaryFileFactory {
					public function create( string $filename ): string {
						unset( $filename );
						throw new RuntimeException( 'Creation is not part of this test.' );
					}

					public function delete( string $path ): void {
						if ( is_file( $path ) ) {
							// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only temporary artifact cleanup.
							unlink( $path );
						}
					}
				},
				$identity
			);
			$updater  = new class( $claim ) {
				public function __construct( private ClaimedArtifact $claim ) {
				}

				public function discard(): bool {
					return $this->claim->discard();
				}

				public function handoffToCore(): ClaimedArtifact {
					return $this->claim;
				}
			};
			$artifact = new GitHubReleaseArtifact(
				$updater,
				'1.2.3',
				str_repeat( 'a', 40 ),
				'example',
				'example.php'
			);

			$prepared = $artifact->handoffToCore();
			unset( $artifact, $claim, $updater );
			gc_collect_cycles();

			self::assertFileExists( $path );
			$prepared->assertUnchanged();
			try {
				$prepared->claimForNativeUpdate( 'plugin', 'example/example.php' );
				self::fail( 'A release claim must not be re-minted for native update.' );
			} catch ( RuntimeException $exception ) {
				self::assertSame( 'The prepared deployment artifact is unavailable.', $exception->getMessage() );
			}
			$prepared->cleanup();
			self::assertFileDoesNotExist( $path );
		} finally {
			if ( is_file( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only fallback cleanup.
				unlink( $path );
			}
		}
	}
}
