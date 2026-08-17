<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class ReleasePlatformContractTest extends TestCase {
	private const UPDATER_COMMIT = '1e64357a2954e4512bfdb11d3ad9cf4515d3c1a0';

	public function testComposerDeclaresTheZipRuntimeRequirement(): void {
		$composer = json_decode(
			(string) file_get_contents( dirname( __DIR__ ) . '/composer.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release contract.
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		self::assertSame( '*', $composer['require']['ext-zip'] ?? null );
	}

	public function testReleaseScriptsPinTheLiteralUpdaterCommit(): void {
		foreach ( array( 'build-release.sh', 'verify-release.sh' ) as $scriptName ) {
			$script = file_get_contents( dirname( __DIR__ ) . '/scripts/' . $scriptName ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release contract.
			self::assertIsString( $script );
			self::assertStringContainsString( "updater_commit='" . self::UPDATER_COMMIT . "'", $script );
			self::assertStringContainsString( 'hash_equals( $argv[3], $source )', $script );
		}
	}

	public function testBuilderVerifiesTheArchiveBeforePublishingItToTheBuildDirectory(): void {
		$script = file_get_contents( dirname( __DIR__ ) . '/scripts/build-release.sh' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release contract.
		self::assertIsString( $script );

		$verify = strpos( $script, 'bash "$script_dir/verify-release.sh" "$tmp_archive" "$expected_version" "$commit"' );
		$move   = strpos( $script, 'mv -f "$tmp_archive" "$build_dir/$archive_name"' );
		self::assertIsInt( $verify );
		self::assertIsInt( $move );
		self::assertTrue( $verify < $move );
	}

	public function testDeploymentPreflightNamesTheMissingPlatformRequirement(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/RAN/Deployment/DeploymentArchivePreflight.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release contract.
		self::assertIsString( $source );
		self::assertStringContainsString( 'The PHP ext-zip platform requirement is unavailable', $source );
	}
}
