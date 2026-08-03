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
		$unchangedExit = strpos( $workflow, 'git diff --quiet HEAD^ HEAD -- .release-please-manifest.json; then exit 0' );
		$releaseLookup = strpos( $workflow, 'gh release view "$tag"' );
		self::assertIsInt( $unchangedExit );
		self::assertIsInt( $releaseLookup );
		self::assertLessThan( $releaseLookup, $unchangedExit, 'A non-release push must exit before inspecting the previous release.' );
		self::assertStringContainsString( 'git log -1 --format=%H -- .release-please-manifest.json', $workflow );
		self::assertStringContainsString( 'isDraft,isImmutable,targetCommitish', $workflow );
		self::assertStringContainsString( "*'(HTTP 404)'*", $workflow );
		self::assertStringContainsString( 'The published release is not immutable', $workflow );
		self::assertStringContainsString( 'git checkout --detach "${RAN_RELEASE_COMMIT}"', $workflow );
		self::assertStringContainsString( '--target "${RAN_RELEASE_COMMIT}"', $workflow );
		self::assertStringContainsString( 'RAN_IMMUTABLE_RELEASES_ENABLED', $workflow );
		self::assertStringContainsString( "--jq '.immutable'", $workflow );
		self::assertStringContainsString( 'for delay in 0 2 2 2 2', $workflow );
	}
}
