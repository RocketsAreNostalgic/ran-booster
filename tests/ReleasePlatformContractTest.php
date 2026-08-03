<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class ReleasePlatformContractTest extends TestCase {
	private const UPDATER_COMMIT = 'c5880a949355567b7e58efb7720962a0282fee20';

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

	public function testDeploymentPreflightNamesTheMissingPlatformRequirement(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/RAN/Deployment/DeploymentArchivePreflight.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release contract.
		self::assertIsString( $source );
		self::assertStringContainsString( 'The PHP ext-zip platform requirement is unavailable', $source );
	}
}
