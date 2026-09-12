<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class ReleasePlatformContractTest extends TestCase {
	private const RUNTIME_PACKAGES     = array(
		'ran/updater-support'    => array(
			'version'    => 'dev-main',
			'commit'     => '4afba1191b81602741ada8948fcf78a6c7510659',
			'repository' => 'https://github.com/RocketsAreNostalgic/ran-updater-support.git',
		),
		'ran/wp-branch-updater'  => array(
			'version'    => 'v1.0.0-beta.2',
			'commit'     => 'bc0f6608f591ee9c48b71de90e4d651462455b47',
			'repository' => 'https://github.com/RocketsAreNostalgic/ran-wp-branch-updater.git',
		),
		'ran/wp-release-updater' => array(
			'version'    => 'v0.1.0-beta.4',
			'commit'     => 'dcd9ce2ca20769dc35d6b6bfd46042c17aa53bd3',
			'repository' => 'https://github.com/RocketsAreNostalgic/ran-wp-release-updater.git',
		),
	);
	private const RELEASE_UPDATER_PATH = 'vendor/ran/wp-release-updater';

	public function testComposerDeclaresTheZipRuntimeRequirement(): void {
		$composer = json_decode(
			(string) file_get_contents( dirname( __DIR__ ) . '/composer.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release contract.
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		self::assertSame( '*', $composer['require']['ext-zip'] ?? null );
	}

	public function testReleaseScriptsStageAndVerifyAllRuntimePackages(): void {
		foreach ( array( 'build-release.sh', 'verify-release.sh' ) as $scriptName ) {
			$script = file_get_contents( dirname( __DIR__ ) . '/scripts/' . $scriptName ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release contract.
			self::assertIsString( $script );
			self::assertStringContainsString( "release_package_root='vendor/ran/wp-release-updater'", $script );
			self::assertStringContainsString( "branch_package_root='vendor/ran/wp-branch-updater'", $script );
			self::assertStringContainsString( "support_package_root='vendor/ran/updater-support'", $script );
			self::assertStringContainsString( "release_updater_commit='dcd9ce2ca20769dc35d6b6bfd46042c17aa53bd3'", $script );
			self::assertStringContainsString( 'scripts/verify-runtime-dependencies.php', $script );
			self::assertStringContainsString( 'git -C "$updater_repository" archive "$release_updater_commit" | tar -xf - -C "$updater_checkout"', $script );
			self::assertStringNotContainsString( 'ran/wp-github-release-updater', $script );
		}
	}

	public function testReleaseScriptsRejectSymbolicLinksAcrossEveryRuntimePackage(): void {
		$requiredRuntimePaths = array(
			'"$release_installed_package/LICENSE"',
			'"$release_installed_package/bootstrap.php"',
			'"$release_installed_package/runtime-copy.json"',
			'"$release_installed_package/runtime.php"',
			'"$release_installed_package/src"',
			'"$branch_installed_package/LICENSE"',
			'"$branch_installed_package/bootstrap.php"',
			'"$branch_installed_package/src"',
			'"$support_installed_package/LICENSE"',
			'"$support_installed_package/src"',
		);

		foreach ( array( 'build-release.sh', 'verify-release.sh' ) as $scriptName ) {
			$script = file_get_contents( dirname( __DIR__ ) . '/scripts/' . $scriptName ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release contract.
			self::assertIsString( $script );
			foreach ( $requiredRuntimePaths as $path ) {
				self::assertStringContainsString( $path, $script );
			}
			self::assertStringContainsString( '-type l -print -quit | grep -q .', $script );
		}
	}

	public function testComposerLockPinsTheExactThreeRuntimePackages(): void {
		$lock = json_decode(
			(string) file_get_contents( dirname( __DIR__ ) . '/composer.lock' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release contract.
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		$packages = is_array( $lock['packages'] ?? null ) ? $lock['packages'] : array();
		self::assertCount( 3, $packages );

		$byName = array();
		foreach ( $packages as $package ) {
			self::assertIsArray( $package );
			$name = $package['name'] ?? null;
			self::assertIsString( $name );
			$byName[ $name ] = $package;
		}

		self::assertSame( array_keys( self::RUNTIME_PACKAGES ), array_keys( $byName ) );

		foreach ( self::RUNTIME_PACKAGES as $name => $expected ) {
			$package = $byName[ $name ];
			self::assertSame( $expected['version'], $package['version'] ?? null );
			self::assertSame( 'git', $package['source']['type'] ?? null );
			self::assertSame( $expected['repository'], $package['source']['url'] ?? null );
			self::assertSame( $expected['commit'], $package['source']['reference'] ?? null );
			self::assertSame( 'zip', $package['dist']['type'] ?? null );
			self::assertSame( $expected['commit'], $package['dist']['reference'] ?? null );
		}
	}

	public function testDisposableLifecycleFixtureUsesTheVendoredUpdatersStableUserAgent(): void {
		$updater = file_get_contents( dirname( __DIR__ ) . '/' . self::RELEASE_UPDATER_PATH . '/src/Provider/GitHub/GitHubReleaseService.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Vendored release contract.
		$fixture = file_get_contents( dirname( __DIR__ ) . '/tests/Integration/phase-4.4-core-disposable-harness.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Disposable lifecycle fixture contract.

		self::assertIsString( $updater );
		self::assertIsString( $fixture );
		self::assertStringContainsString( "'User-Agent' => 'ran-wp-release-updater',", $updater );
		self::assertStringContainsString( "'ran-wp-release-updater' !== ( \$headers['User-Agent'] ?? null )", $fixture );
	}

	public function testReleaseVerifierRequiresTheSupportedCoreAndThreeRuntimeMarkers(): void {
		$script = file_get_contents( dirname( __DIR__ ) . '/scripts/verify-release.sh' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release contract.
		self::assertIsString( $script );

		foreach ( array(
			"define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 10 );",
			"define( 'RAN_BOOSTER_ADDON_API_VERSION', 16 );",
			"define( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION', 2 );",
			"define( 'RAN_BOOSTER_PORTABILITY_API_VERSION', PortabilityFacade::API_VERSION );",
			'public const API_VERSION = 2;',
			'"$release_package_root/runtime-copy.json"',
			'"$release_package_root/runtime.php"',
			'compare_runtime_package "$support_installed_package" "$extract_dir/ran-booster/$support_package_root" LICENSE src',
			'compare_runtime_package "$branch_installed_package" "$extract_dir/ran-booster/$branch_package_root" LICENSE bootstrap.php src',
			'compare_runtime_package "$release_installed_package" "$extract_dir/ran-booster/$release_package_root" LICENSE bootstrap.php runtime-copy.json runtime.php src',
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
