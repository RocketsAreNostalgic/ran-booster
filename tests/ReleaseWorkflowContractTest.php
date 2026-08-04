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
		self::assertStringContainsString( 'gh api --paginate --slurp "repos/${GITHUB_REPOSITORY}/releases?per_page=100"', $workflow );
		self::assertStringContainsString( 'select(.tag_name == $tag)', $workflow );
		self::assertStringContainsString( 'git log -1 --format=%H -- .release-please-manifest.json', $workflow );
		self::assertStringContainsString( "'.target_commitish'", $workflow );
		self::assertStringContainsString( 'The published release is not immutable', $workflow );
		self::assertStringContainsString( 'git checkout --detach "${RAN_RELEASE_COMMIT}"', $workflow );
		self::assertStringContainsString( '--target "${RAN_RELEASE_COMMIT}"', $workflow );
		self::assertStringContainsString( 'RAN_IMMUTABLE_RELEASES_ENABLED', $workflow );
		self::assertStringContainsString( "--jq '.immutable'", $workflow );
		self::assertStringContainsString( 'for delay in 0 2 2 2 2', $workflow );
	}

	public function testImmutablePublicationReconcilesTheExactMergedReleasePullRequest(): void {
		$workflow = file_get_contents( dirname( __DIR__ ) . '/.github/workflows/release-please.yml' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		self::assertIsString( $workflow );

		self::assertStringContainsString( 'pull-requests: write', $workflow );
		self::assertStringContainsString( 'RAN_RELEASE_PR_RECONCILE=true', $workflow );
		self::assertStringContainsString( 'releases/tags/${RAN_RELEASE_TAG}', $workflow );
		self::assertStringContainsString( '.tag_name == $tag and .draft == false and .immutable == true and .target_commitish == $commit', $workflow );
		self::assertStringContainsString( 'git/ref/tags/${RAN_RELEASE_TAG}', $workflow );
		self::assertStringContainsString( '.object.type == "commit" and .object.sha == $commit', $workflow );
		self::assertStringContainsString( 'commits/${RAN_RELEASE_COMMIT}/pulls', $workflow );
		self::assertStringContainsString( '.merged_at != null and .base.ref == $base and .head.sha == $commit', $workflow );
		self::assertStringContainsString( 'Expected exactly one merged release PR for the manifest commit.', $workflow );
		self::assertStringContainsString( 'autorelease: pending', $workflow );
		self::assertStringContainsString( 'autorelease: tagged', $workflow );
		self::assertStringContainsString( 'pending="$(jq -r \'any(.labels[]?; .name == "autorelease: pending")\'', $workflow );
		self::assertStringContainsString( 'tagged="$(jq -r \'any(.labels[]?; .name == "autorelease: tagged")\'', $workflow );
		self::assertStringNotContainsString( 'jq -er \'any(.labels[]?', $workflow );
		self::assertStringContainsString( 'issues/${release_pr_number}/labels/autorelease%3A%20pending', $workflow );
	}
}
