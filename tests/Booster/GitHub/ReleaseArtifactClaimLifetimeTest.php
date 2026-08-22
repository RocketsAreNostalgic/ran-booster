<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\GitHubReleaseArtifact;
use RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact;

final class ReleaseArtifactClaimLifetimeTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testProviderArtifactIsCopiedIntoCoreCustodyBeforeProviderCleanup(): void {
		$root = dirname( __DIR__, 3 );
		require_once $root . '/vendor/ran/wp-release-updater/runtime.php';

		$path = tempnam( sys_get_temp_dir(), 'ran-booster-real-release-claim-' );
		if ( false === $path ) {
			self::fail( 'The real release-claim fixture could not be created.' );
		}

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only temporary artifact.
			file_put_contents( $path, 'verified-release-archive' );
			chmod( $path, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test-only custody fixture.
			$stat     = lstat( $path );
			$identity = false === $stat ? false : array(
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
			$digest   = hash_file( 'sha256', $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_hash_file -- Test-only temporary artifact identity.
			self::assertIsArray( $identity );
			self::assertIsString( $digest );
			$updater  = new TemporaryArtifact(
				$path,
				$digest,
				$identity
			);
			$artifact = new GitHubReleaseArtifact(
				$updater,
				'1.2.3',
				str_repeat( 'a', 40 ),
				'example',
				'example.php'
			);

			$prepared = $artifact->handoffToCore();
			unset( $artifact, $updater );
			gc_collect_cycles();

			self::assertFileDoesNotExist( $path );
			$prepared->assertUnchanged();
			$prepared->cleanup();
			self::assertFileDoesNotExist( $prepared->getPath() );
			self::assertDirectoryDoesNotExist( dirname( $prepared->getPath() ) );
		} finally {
			if ( is_file( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only fallback cleanup.
				unlink( $path );
			}
		}
	}
}
