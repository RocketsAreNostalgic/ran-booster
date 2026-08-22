<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\GitHubReleaseArtifact;
use RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact;
use RAN\WPReleaseUpdater\V1\Provider\GitHub\ProspectiveReleaseArtifact;
use RAN\WPReleaseUpdater\V1\Provider\GitHub\ProspectiveReleaseInspection;

final class ReleaseArtifactClaimLifetimeTest extends TestCase {
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testProviderArtifactIsCopiedIntoCoreCustodyBeforeProviderCleanup(): void {
		require_once dirname( __DIR__, 3 ) . '/../ran-wp-release-updater/runtime.php';
		$path = tempnam( sys_get_temp_dir(), 'ran-booster-real-release-artifact-' );
		self::assertIsString( $path );

		try {
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
			$artifact   = new GitHubReleaseArtifact(
				new ProspectiveReleaseArtifact( $inspection, $temporary ),
				'1.2.3',
				str_repeat( 'a', 40 ),
				'example',
				'example.php'
			);

			$prepared = $artifact->handoffToCore();
			self::assertFileDoesNotExist( $path );
			$prepared->assertUnchanged();
			$ownedPath = $prepared->getPath();
			$prepared->cleanup();
			self::assertFileDoesNotExist( $ownedPath );
			self::assertDirectoryDoesNotExist( dirname( $ownedPath ) );
		} finally {
			if ( is_file( $path ) ) {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only fallback cleanup.
			}
		}
	}
}
