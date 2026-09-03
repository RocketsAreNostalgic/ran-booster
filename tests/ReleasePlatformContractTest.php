<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class ReleasePlatformContractTest extends TestCase {
	private const UPDATER_COMMIT  = 'd1e67116492116b3001d34f4fe40129c13f9cf7e';
	private const UPDATER_PACKAGE = 'ran/wp-release-updater';
	private const UPDATER_PATH    = 'vendor/ran/wp-release-updater';
	private const UPDATER_VERSION = '0.1.0-beta.2';

	public function testComposerDeclaresTheZipRuntimeRequirement(): void {
		$composer = json_decode(
			(string) file_get_contents( dirname( __DIR__ ) . '/composer.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release contract.
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		self::assertSame( '*', $composer['require']['ext-zip'] ?? null );
	}

	public function testReleaseScriptsPinTheNeutralUpdaterLockIdentity(): void {
		foreach ( array( 'build-release.sh', 'verify-release.sh' ) as $scriptName ) {
			$script = file_get_contents( dirname( __DIR__ ) . '/scripts/' . $scriptName ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release contract.
			self::assertIsString( $script );
			self::assertStringContainsString( "package_root='" . self::UPDATER_PATH . "'", $script );
			self::assertStringContainsString( "updater_version='" . self::UPDATER_VERSION . "'", $script );
			self::assertStringContainsString( "updater_commit='" . self::UPDATER_COMMIT . "'", $script );
			self::assertStringContainsString( '"' . self::UPDATER_PACKAGE . '" !==', $script );
			self::assertStringContainsString( '"../ran-wp-release-updater" !== ( $dist["url"] ?? null )', $script );
			self::assertStringContainsString( 'hash_equals( $argv[3], $dist["reference"] )', $script );
			self::assertStringContainsString( 'array_key_exists( "source", $package )', $script );
			self::assertStringContainsString( 'git -C "$updater_repository" archive "$updater_commit" | tar -xf - -C "$updater_checkout"', $script );
			self::assertStringNotContainsString( 'ran/wp-github-release-updater', $script );
		}
	}

	public function testReleaseScriptsRejectSymbolicLinksInEveryUpdaterRuntimeFile(): void {
		$symlinkScan = 'find "$installed_package/LICENSE" "$installed_package/bootstrap.php" "$installed_package/runtime-copy.json" "$installed_package/runtime.php" "$installed_package/src" -type l -print -quit | grep -q .';

		foreach ( array( 'build-release.sh', 'verify-release.sh' ) as $scriptName ) {
			$script = file_get_contents( dirname( __DIR__ ) . '/scripts/' . $scriptName ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release contract.
			self::assertIsString( $script );
			self::assertStringContainsString( $symlinkScan, $script );
		}
	}

	public function testComposerLockPinsTheNeutralRuntimeAndItsExactMetadata(): void {
		$lock    = json_decode(
			(string) file_get_contents( dirname( __DIR__ ) . '/composer.lock' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release contract.
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$package = $lock['packages'][0] ?? null;

		self::assertIsArray( $package );
		self::assertSame( self::UPDATER_PACKAGE, $package['name'] ?? null );
		self::assertSame( self::UPDATER_VERSION, $package['version'] ?? null );
		self::assertSame( 'path', $package['dist']['type'] ?? null );
		self::assertSame( '../ran-wp-release-updater', $package['dist']['url'] ?? null );
		self::assertSame( self::UPDATER_COMMIT, $package['dist']['reference'] ?? null );
		self::assertArrayNotHasKey( 'source', $package );
	}

	public function testDisposableLifecycleFixtureUsesTheVendoredUpdatersStableUserAgent(): void {
		$updater = file_get_contents( dirname( __DIR__ ) . '/' . self::UPDATER_PATH . '/src/Provider/GitHub/GitHubReleaseService.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Vendored release contract.
		$fixture = file_get_contents( dirname( __DIR__ ) . '/tests/Integration/phase-4.4-core-disposable-harness.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Disposable lifecycle fixture contract.

		self::assertIsString( $updater );
		self::assertIsString( $fixture );
		self::assertStringContainsString( "'User-Agent' => 'ran-wp-release-updater',", $updater );
		self::assertStringContainsString( "'ran-wp-release-updater' !== ( \$headers['User-Agent'] ?? null )", $fixture );
	}

	public function testReleaseVerifierRequiresTheSupportedCoreAndNeutralRuntimeMarkers(): void {
		$script = file_get_contents( dirname( __DIR__ ) . '/scripts/verify-release.sh' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release contract.
		self::assertIsString( $script );

		foreach ( array(
			"define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 10 );",
			"define( 'RAN_BOOSTER_ADDON_API_VERSION', 16 );",
			"define( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION', 2 );",
			"define( 'RAN_BOOSTER_PORTABILITY_API_VERSION', PortabilityFacade::API_VERSION );",
			'public const API_VERSION = 2;',
			'"$package_root/runtime-copy.json"',
			'"$package_root/runtime.php"',
			'for package_entry in LICENSE bootstrap.php runtime-copy.json runtime.php src; do',
		) as $marker ) {
			self::assertStringContainsString( $marker, $script );
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
