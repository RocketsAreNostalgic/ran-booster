<?php

declare(strict_types=1);

namespace RANTests;

use PHPUnit\Framework\TestCase;

final class ReleasePromotionBoundaryTest extends TestCase {
	public function testReleasePleaseConfigurationUsesProfileBDraftLifecycle(): void {
		$config = json_decode(
			$this->readText( 'release-please-config.json' ),
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		self::assertIsArray( $config );
		self::assertTrue( $config['draft'] ?? false );
		self::assertTrue( $config['force-tag-creation'] ?? false );
		self::assertNotSame( true, $config['skip-github-release'] ?? false );
	}

	public function testProfileBCallerOwnsTheOnlyGenericReleaseMutationBoundary(): void {
		$workflow = $this->readText( '.github/workflows/release-please.yml' );

		self::assertStringContainsString(
			'uses: RocketsAreNostalgic/.github/.github/workflows/release-profile-b.yml@e2fb19244a301a62f8fae2a80536898adf21fe22',
			$workflow
		);
		self::assertStringContainsString( 'contents: write', $workflow );
		self::assertStringContainsString( 'pull-requests: write', $workflow );
		self::assertStringNotContainsString( 'shell:', $workflow );
		self::assertSame( 0, preg_match( '/^\\s+run:/m', $workflow ) );
		self::assertStringNotContainsString( '--clobber', $workflow );
	}

	public function testQualityPromotionManifestBindsExactCoreAssets(): void {
		$quality = $this->readText( '.github/workflows/quality.yml' );
		$markers = array(
			'ran-profile-b-promotion',
			'quality_commit:$quality_commit',
			'source_commit:$source_commit',
			'ran-booster-${version}.zip',
			'${archive}.sha256',
			'archive_sha256',
			'checksum_sha256',
		);

		foreach ( $markers as $marker ) {
			self::assertStringContainsString( $marker, $quality );
		}
	}

	public function testObsoleteGenericReleaseStateHelpersAreRemoved(): void {
		$paths = array(
			'scripts/has-trusted-release-candidate-run.sh',
			'scripts/reconcile-release-candidate-marker.sh',
			'scripts/select-merged-release-pr.sh',
			'scripts/verify-immutable-release-assets.sh',
			'scripts/verify-release-tag-target.sh',
			'tests/reconcile-release-candidate-marker.sh',
			'tests/release-state-contracts.sh',
			'tests/quality-fallback-contract.sh',
		);

		foreach ( $paths as $path ) {
			self::assertFileDoesNotExist( $this->root() . '/' . $path, $path );
		}
	}

	public function testReleaseDocumentationMapsRetainedAndDeletedGuarantees(): void {
		$release = $this->readText( 'RELEASE.md' );

		self::assertStringContainsString( 'Retained and deleted evidence', $release );
		self::assertStringContainsString( 'Runtime archive', $release );
		self::assertStringContainsString( 'Release candidate install readback', $release );
		self::assertStringContainsString( 'ran-profile-b-promotion.json', $release );
		self::assertStringContainsString( 'Local candidate comments/markers', $release );
		self::assertStringContainsString( '--clobber', $release );
	}

	private function readText( string $path ): string {
		$text = file_get_contents( $this->root() . '/' . $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local repository contract.
		self::assertIsString( $text );

		return $text;
	}

	private function root(): string {
		return dirname( __DIR__ );
	}
}
