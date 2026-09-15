<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class ReleasePlatformContractTest extends TestCase {
	private const RUNTIME_PACKAGING_POLICY = array(
		'ran/updater-support'    => array(
			'repository'   => 'RocketsAreNostalgic/ran-updater-support',
			'archive_root' => 'vendor/ran/updater-support',
			'surfaces'     => array( 'LICENSE', 'src' ),
			'build_role'   => null,
		),
		'ran/wp-branch-updater'  => array(
			'repository'   => 'RocketsAreNostalgic/ran-wp-branch-updater',
			'archive_root' => 'vendor/ran/wp-branch-updater',
			'surfaces'     => array( 'LICENSE', 'bootstrap.php', 'src' ),
			'build_role'   => null,
		),
		'ran/wp-release-updater' => array(
			'repository'   => 'RocketsAreNostalgic/ran-wp-release-updater',
			'archive_root' => 'vendor/ran/wp-release-updater',
			'surfaces'     => array( 'LICENSE', 'bootstrap.php', 'runtime-copy.json', 'runtime.php', 'src' ),
			'build_role'   => 'neutral-updater',
		),
	);

	public function testComposerDeclaresTheZipRuntimeRequirement(): void {
		$composer = $this->readJson( dirname( __DIR__ ) . '/composer.json' );

		self::assertSame( '*', $composer['require']['ext-zip'] ?? null );
	}

	public function testRuntimePackagingPolicyOwnsTheExactPackageSetAndSurfaces(): void {
		$policy = $this->readPackagingPolicy();

		self::assertSame( 'ran-booster-runtime-packaging', $policy['schema'] ?? null );
		self::assertSame( 1, $policy['schema_version'] ?? null );
		self::assertIsArray( $policy['packages'] ?? null );

		$actual = array();
		foreach ( $policy['packages'] as $record ) {
			self::assertIsArray( $record );
			$name = $record['name'] ?? null;
			self::assertIsString( $name );
			$actual[ $name ] = array(
				'repository'   => $record['repository'] ?? null,
				'archive_root' => $record['archive_root'] ?? null,
				'surfaces'     => $record['surfaces'] ?? null,
				'build_role'   => $record['build_role'] ?? null,
			);
		}

		self::assertSame( self::RUNTIME_PACKAGING_POLICY, $actual );
	}

	public function testComposerLockPinsExactlyThePolicyApprovedPackages(): void {
		$lock   = $this->readJson( dirname( __DIR__ ) . '/composer.lock' );
		$policy = $this->readPackagingPolicy();

		$packages = is_array( $lock['packages'] ?? null ) ? $lock['packages'] : array();
		self::assertCount( count( self::RUNTIME_PACKAGING_POLICY ), $packages );

		$byName = array();
		foreach ( $packages as $package ) {
			self::assertIsArray( $package );
			$name = $package['name'] ?? null;
			self::assertIsString( $name );
			self::assertArrayNotHasKey( $name, $byName );
			$byName[ $name ] = $package;
		}

		$policyByName = array();
		foreach ( $policy['packages'] as $record ) {
			self::assertIsArray( $record );
			$name = $record['name'] ?? null;
			self::assertIsString( $name );
			$policyByName[ $name ] = $record;
		}
		self::assertSame( array_keys( $policyByName ), array_keys( $byName ) );

		foreach ( $policyByName as $name => $record ) {
			$package    = $byName[ $name ];
			$repository = $record['repository'] ?? null;
			self::assertIsString( $repository );
			$reference = $package['source']['reference'] ?? null;
			self::assertIsString( $reference );
			self::assertMatchesRegularExpression( '/^[0-9a-f]{40}$/D', $reference );
			self::assertMatchesRegularExpression(
				'/^v?[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D',
				(string) ( $package['version'] ?? '' )
			);
			self::assertSame( 'git', $package['source']['type'] ?? null );
			self::assertSame( 'https://github.com/' . $repository . '.git', $package['source']['url'] ?? null );
			self::assertSame( 'zip', $package['dist']['type'] ?? null );
			self::assertSame( $reference, $package['dist']['reference'] ?? null );
			self::assertSame(
				'https://api.github.com/repos/' . $repository . '/zipball/' . $reference,
				$package['dist']['url'] ?? null
			);
		}
	}

	public function testRuntimeDependencyVerifierAcceptsCurrentLockAndPolicy(): void {
		$result = $this->runRuntimeDependencyVerifier(
			dirname( __DIR__ ) . '/composer.lock',
			dirname( __DIR__ ) . '/runtime-packaging-policy.json'
		);

		self::assertSame( 0, $result['exit'], $result['stderr'] );
		self::assertCount( count( self::RUNTIME_PACKAGING_POLICY ), array_filter( explode( "\n", trim( $result['stdout'] ) ) ) );
	}

	public function testRuntimeDependencyVerifierRejectsUnexpectedPackageDrift(): void {
		$lock = $this->readJson( dirname( __DIR__ ) . '/composer.lock' );
		self::assertIsArray( $lock['packages'] ?? null );
		$lock['packages'][] = $lock['packages'][0];
		$path = $this->writeTemporaryJson( $lock );

		try {
			$result = $this->runRuntimeDependencyVerifier(
				$path,
				dirname( __DIR__ ) . '/runtime-packaging-policy.json'
			);
			self::assertNotSame( 0, $result['exit'] );
		} finally {
			@unlink( $path );
		}
	}

	public function testRuntimeDependencyVerifierRejectsRepositoryDrift(): void {
		$policy = $this->readPackagingPolicy();
		self::assertIsArray( $policy['packages'][0] ?? null );
		$policy['packages'][0]['repository'] = 'RocketsAreNostalgic/not-the-locked-package';
		$path = $this->writeTemporaryJson( $policy );

		try {
			$result = $this->runRuntimeDependencyVerifier(
				dirname( __DIR__ ) . '/composer.lock',
				$path
			);
			self::assertNotSame( 0, $result['exit'] );
		} finally {
			@unlink( $path );
		}
	}

	public function testRuntimeDependencyVerifierRejectsUnsafeSurfacePolicy(): void {
		$policy = $this->readPackagingPolicy();
		self::assertIsArray( $policy['packages'][0] ?? null );
		self::assertIsArray( $policy['packages'][0]['surfaces'] ?? null );
		$policy['packages'][0]['surfaces'][] = '../outside-package';
		$path = $this->writeTemporaryJson( $policy );

		try {
			$result = $this->runRuntimeDependencyVerifier(
				dirname( __DIR__ ) . '/composer.lock',
				$path
			);
			self::assertNotSame( 0, $result['exit'] );
		} finally {
			@unlink( $path );
		}
	}

	public function testReleaseScriptsConsumeTheSharedPackagingPolicyProjection(): void {
		foreach ( array( 'build-release.sh', 'verify-release.sh' ) as $scriptName ) {
			$script = file_get_contents( dirname( __DIR__ ) . '/scripts/' . $scriptName ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release contract.
			self::assertIsString( $script );
			self::assertStringContainsString( 'runtime-packaging-policy.json', $script );
			self::assertStringContainsString( 'scripts/verify-runtime-dependencies.php', $script );
			self::assertStringContainsString( '--packaging', $script );
			self::assertStringContainsString( 'package_roots=()', $script );
			self::assertStringContainsString( 'package_surfaces=()', $script );
			self::assertStringNotContainsString( "release_package_root='vendor/ran/wp-release-updater'", $script );
			self::assertStringNotContainsString( "branch_package_root='vendor/ran/wp-branch-updater'", $script );
			self::assertStringNotContainsString( "support_package_root='vendor/ran/updater-support'", $script );
			self::assertStringNotContainsString( 'dcd9ce2ca20769dc35d6b6bfd46042c17aa53bd3', $script );
		}
	}

	public function testReleaseScriptsRejectSymbolicLinksAcrossEveryPolicySurface(): void {
		foreach ( array( 'build-release.sh', 'verify-release.sh' ) as $scriptName ) {
			$script = file_get_contents( dirname( __DIR__ ) . '/scripts/' . $scriptName ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release contract.
			self::assertIsString( $script );
			self::assertStringContainsString( '-type l -print -quit | grep -q .', $script );
			self::assertStringContainsString( 'runtime dependency allowlist must not contain symbolic links.', $script );
		}
	}

	public function testCoreReleaseFileManifestDoesNotDuplicatePackageSurfaces(): void {
		$manifest = file_get_contents( dirname( __DIR__ ) . '/release-files.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release contract.
		self::assertIsString( $manifest );
		self::assertStringNotContainsString( 'vendor/ran/', $manifest );
		self::assertStringContainsString( 'runtime-packaging-policy.json', $manifest );
	}

	public function testDisposableLifecycleFixtureUsesTheVendoredUpdatersStableUserAgent(): void {
		$releaseUpdaterPath = $this->neutralUpdaterArchiveRoot();
		$updater = file_get_contents( dirname( __DIR__ ) . '/' . $releaseUpdaterPath . '/src/Provider/GitHub/GitHubReleaseService.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Vendored release contract.
		$fixture = file_get_contents( dirname( __DIR__ ) . '/tests/Integration/phase-4.4-core-disposable-harness.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Disposable lifecycle fixture contract.

		self::assertIsString( $updater );
		self::assertIsString( $fixture );
		self::assertStringContainsString( "'User-Agent' => 'ran-wp-release-updater',", $updater );
		self::assertStringContainsString( "'ran-wp-release-updater' !== ( \$headers['User-Agent'] ?? null )", $fixture );
	}

	public function testReleaseVerifierRequiresTheSupportedCoreAndPolicyDrivenRuntimeProofs(): void {
		$script = file_get_contents( dirname( __DIR__ ) . '/scripts/verify-release.sh' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release contract.
		self::assertIsString( $script );

		foreach ( array(
			"define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 10 );",
			"define( 'RAN_BOOSTER_ADDON_API_VERSION', 16 );",
			"define( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION', 2 );",
			"define( 'RAN_BOOSTER_PORTABILITY_API_VERSION', PortabilityFacade::API_VERSION );",
			'public const API_VERSION = 2;',
			'runtime-packaging-policy.json',
			'compare_runtime_package',
			'for index in "${!package_roots[@]}"; do',
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

	/**
	 * @return array<string, mixed>
	 */
	private function readPackagingPolicy(): array {
		return $this->readJson( dirname( __DIR__ ) . '/runtime-packaging-policy.json' );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function readJson( string $path ): array {
		$document = json_decode(
			(string) file_get_contents( $path ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release contract.
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		self::assertIsArray( $document );
		return $document;
	}

	private function neutralUpdaterArchiveRoot(): string {
		foreach ( $this->readPackagingPolicy()['packages'] as $record ) {
			if ( is_array( $record ) && 'neutral-updater' === ( $record['build_role'] ?? null ) ) {
				$root = $record['archive_root'] ?? null;
				self::assertIsString( $root );
				return $root;
			}
		}
		self::fail( 'Runtime packaging policy does not identify a neutral updater.' );
	}

	/**
	 * @return array{exit: int, stdout: string, stderr: string}
	 */
	private function runRuntimeDependencyVerifier( string $lockPath, string $policyPath ): array {
		$process = proc_open(
			array(
				PHP_BINARY,
				dirname( __DIR__ ) . '/scripts/verify-runtime-dependencies.php',
				$lockPath,
				$policyPath,
			),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		self::assertIsResource( $process );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit = proc_close( $process );
		self::assertIsString( $stdout );
		self::assertIsString( $stderr );
		return array(
			'exit'   => $exit,
			'stdout' => $stdout,
			'stderr' => $stderr,
		);
	}

	/**
	 * @param array<string, mixed> $document
	 */
	private function writeTemporaryJson( array $document ): string {
		$path = tempnam( sys_get_temp_dir(), 'ran-booster-runtime-policy-' );
		self::assertIsString( $path );
		$bytes = file_put_contents(
			$path,
			json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n"
		); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local temporary test fixture.
		self::assertIsInt( $bytes );
		return $path;
	}
}
