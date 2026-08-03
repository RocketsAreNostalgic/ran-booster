<?php

declare(strict_types=1);

namespace RANTests;

use PHPUnit\Framework\TestCase;

final class ReleaseWorkflowContractTest extends TestCase {
	public function testPackageReleaseIsBoundToTheManifestChangingCommit(): void {
		$workflow = file_get_contents( dirname( __DIR__ ) . '/.github/workflows/release-please.yml' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		self::assertIsString( $workflow );

		self::assertStringContainsString( 'skip-github-release: true', $workflow );
		self::assertStringContainsString( 'fetch-depth: 0', $workflow );
		self::assertStringContainsString( 'git diff --quiet HEAD^ HEAD -- .release-please-manifest.json && manifest_changed=false', $workflow );
		self::assertStringContainsString( 'gh api "repos/${GITHUB_REPOSITORY}/releases/tags/${tag}"', $workflow );
		self::assertStringContainsString( '"$manifest_changed" == false', $workflow );
		self::assertStringContainsString( 'git log -1 --format=%H -- .release-please-manifest.json', $workflow );
		self::assertStringContainsString( "'.target_commitish'", $workflow );
		self::assertStringContainsString( "*'(HTTP 404)'*", $workflow );
		self::assertStringContainsString( 'The published release is not immutable', $workflow );
		self::assertStringContainsString( 'git checkout --detach "${RAN_RELEASE_COMMIT}"', $workflow );
		self::assertStringContainsString( '--target "${RAN_RELEASE_COMMIT}"', $workflow );
		self::assertStringContainsString( 'RAN_IMMUTABLE_RELEASES_ENABLED', $workflow );
		self::assertStringContainsString( "--jq '.immutable'", $workflow );
		self::assertStringContainsString( 'for delay in 0 2 2 2 2', $workflow );
	}
}
