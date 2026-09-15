<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

// CLI-only release contract tests intentionally use direct local file/process primitives.
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions

final class ReleasePlatformContractTest extends TestCase {
	private const RUNTIME_PACKAGING_POLICY = array(
		'ran/updater-support'    => array(
			'repository'   => 'RocketsAreNostalgic/ran-updater-support',
			'archive_root' => 'vendor/ran/updater-support',
			'surfaces'     => array(
				array(
					'path' => 'LICENSE',
					'kind' => 'file',
				),
				array(
					'path' => 'src',
					'kind' => 'directory',
				),
			),
			'build_role'   => null,
		),
		'ran/wp-branch-updater'  => array(
			'repository'   => 'RocketsAreNostalgic/ran-wp-branch-updater',
			'archive_root' => 'vendor/ran/wp-branch-updater',
			'surfaces'     => array(
				array(
					'path' => 'LICENSE',
					'kind' => 'file',
				),
				array(
					'path' => 'bootstrap.php',
					'kind' => 'file',
				),
				array(
					'path' => 'src',
					'kind' => 'directory',
				),
			),
			'build_role'   => null,
		),
		'ran/wp-release-updater' => array(
			'repository'   => 'RocketsAreNostalgic/ran-wp-release-updater',
			'archive_root' => 'vendor/ran/wp-release-updater',
			'surfaces'     => array(
				array(
					'path' => 'LICENSE',
					'kind' => 'file',
				),
				array(
					'path' => 'bootstrap.php',
					'kind' => 'file',
				),
				array(
					'path' => 'runtime-copy.json',
					'kind' => 'file',
				),
				array(
					'path' => 'runtime.php',
					'kind' => 'file',
				),
				array(
					'path' => 'src',
					'kind' => 'directory',
				),
			),
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

		$packages = is_array( $lock['packages'] ?? null )
			? $lock['packages']
			: array();
		self::assertCount( count( self::RUNTIME_PACKAGING_POLICY ), $packages );

		$byName = array();
		foreach ( $packages as $package ) {
			self::assertIsArray( $package );
			$name = $package['name'] ?? null;
			self::assertIsString( $name );
			self::assertArrayNotHasKey( $name, $byName );
			$byName[ $name ] = $package;
		}

		$policyByName = $this->policyByName( $policy );
		self::assertSame( array_keys( $policyByName ), array_keys( $byName ) );

		$versionPattern = '/^v?[0-9]+\.[0-9]+\.[0-9]+'
			. '(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D';
		foreach ( $policyByName as $name => $record ) {
			$package    = $byName[ $name ];
			$repository = $record['repository'] ?? null;
			self::assertIsString( $repository );
			$reference = $package['source']['reference'] ?? null;
			self::assertIsString( $reference );
			self::assertMatchesRegularExpression( '/^[0-9a-f]{40}$/D', $reference );
			self::assertMatchesRegularExpression(
				$versionPattern,
				(string) ( $package['version'] ?? '' )
			);
			self::assertSame( 'git', $package['source']['type'] ?? null );
			self::assertSame(
				'https://github.com/' . $repository . '.git',
				$package['source']['url'] ?? null
			);
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
		$records = array_filter( explode( "\n", trim( $result['stdout'] ) ) );
		self::assertCount( count( self::RUNTIME_PACKAGING_POLICY ), $records );
	}

	public function testRuntimeDependencyPackagingProjectionHasStableTypedFields(): void {
		$lock   = $this->readJson( dirname( __DIR__ ) . '/composer.lock' );
		$policy = $this->readPackagingPolicy();
		$result = $this->runRuntimeDependencyVerifier(
			dirname( __DIR__ ) . '/composer.lock',
			dirname( __DIR__ ) . '/runtime-packaging-policy.json',
			true
		);
		self::assertSame( 0, $result['exit'], $result['stderr'] );

		$lockByName = array();
		foreach ( $lock['packages'] as $package ) {
			self::assertIsArray( $package );
			$name = $package['name'] ?? null;
			self::assertIsString( $name );
			$lockByName[ $name ] = $package;
		}

		$records = array_filter( explode( "\n", trim( $result['stdout'] ) ) );
		self::assertCount( count( self::RUNTIME_PACKAGING_POLICY ), $records );
		$seenNeutralUpdater = false;
		foreach ( $records as $line ) {
			$fields = explode( "\t", $line );
			self::assertCount( 7, $fields );
			list( $name, $version, $reference, $repository, $root, $surfaceSpecs, $role ) = $fields;
			self::assertArrayHasKey( $name, self::RUNTIME_PACKAGING_POLICY );
			$expected = self::RUNTIME_PACKAGING_POLICY[ $name ];
			self::assertSame( $lockByName[ $name ]['version'] ?? null, $version );
			self::assertSame( $lockByName[ $name ]['source']['reference'] ?? null, $reference );
			self::assertSame( $expected['repository'], $repository );
			self::assertSame( $expected['archive_root'], $root );
			self::assertSame( $this->surfaceSpecs( $expected['surfaces'] ), $surfaceSpecs );
			$expectedRole = $expected['build_role'] ?? '-';
			self::assertSame( $expectedRole ?? '-', $role );
			if ( 'neutral-updater' === $role ) {
				self::assertFalse( $seenNeutralUpdater );
				$seenNeutralUpdater = true;
			}
		}
		self::assertTrue( $seenNeutralUpdater );
	}

	public function testRuntimeDependencyVerifierRejectsUnexpectedPackageAtTheSameCount(): void {
		$lock = $this->readJson( dirname( __DIR__ ) . '/composer.lock' );
		self::assertIsArray( $lock['packages'] ?? null );
		self::assertIsArray( $lock['packages'][0] ?? null );
		$lock['packages'][0]['name'] = 'ran/unexpected-runtime';
		$path                        = $this->writeTemporaryJson( $lock );

		try {
			$result = $this->runRuntimeDependencyVerifier(
				$path,
				dirname( __DIR__ ) . '/runtime-packaging-policy.json'
			);
			self::assertNotSame( 0, $result['exit'] );
			self::assertStringContainsString( 'unexpected production package', $result['stderr'] );
		} finally {
			$this->removeTemporaryFile( $path );
		}
	}

	public function testRuntimeDependencyVerifierRejectsRepositoryDrift(): void {
		$policy = $this->readPackagingPolicy();
		self::assertIsArray( $policy['packages'][0] ?? null );
		$policy['packages'][0]['repository'] = 'RocketsAreNostalgic/not-the-locked-package';
		$path                                = $this->writeTemporaryJson( $policy );

		try {
			$result = $this->runRuntimeDependencyVerifier(
				dirname( __DIR__ ) . '/composer.lock',
				$path
			);
			self::assertNotSame( 0, $result['exit'] );
		} finally {
			$this->removeTemporaryFile( $path );
		}
	}

	public function testRuntimeDependencyVerifierRejectsNestedSurfacePolicy(): void {
		$policy = $this->readPackagingPolicy();
		self::assertIsArray( $policy['packages'][0] ?? null );
		self::assertIsArray( $policy['packages'][0]['surfaces'][0] ?? null );
		$policy['packages'][0]['surfaces'][0]['path'] = 'nested/LICENSE';
		$path = $this->writeTemporaryJson( $policy );

		try {
			$result = $this->runRuntimeDependencyVerifier(
				dirname( __DIR__ ) . '/composer.lock',
				$path
			);
			self::assertNotSame( 0, $result['exit'] );
			self::assertStringContainsString( 'invalid top-level surface', $result['stderr'] );
		} finally {
			$this->removeTemporaryFile( $path );
		}
	}

	public function testReleaseScriptsConsumeTheSharedTypedPackagingProjection(): void {
		foreach ( array( 'build-release.sh', 'verify-release.sh' ) as $scriptName ) {
			$script = $this->readText( dirname( __DIR__ ) . '/scripts/' . $scriptName );
			self::assertStringContainsString( 'runtime-packaging-policy.json', $script );
			self::assertStringContainsString( 'scripts/verify-runtime-dependencies.php', $script );
			self::assertStringContainsString( '--packaging', $script );
			self::assertStringContainsString( 'package_roots=()', $script );
			self::assertStringContainsString( 'package_surface_specs=()', $script );
			self::assertStringContainsString( 'surface_kind=${surface_spec%%:*}', $script );
			self::assertStringNotContainsString( 'updater_repository=', $script );
			self::assertStringNotContainsString(
				'dcd9ce2ca20769dc35d6b6bfd46042c17aa53bd3',
				$script
			);
		}
	}

	public function testReleaseScriptsPreserveSurfaceKindsAndRejectSymlinks(): void {
		foreach ( array( 'build-release.sh', 'verify-release.sh' ) as $scriptName ) {
			$script = $this->readText( dirname( __DIR__ ) . '/scripts/' . $scriptName );
			self::assertStringContainsString( 'changed kind', $script );
			self::assertStringContainsString( 'directory surface is empty', $script );
			self::assertStringContainsString( '! -L "$installed_package"', $script );
			self::assertStringContainsString( '-type l -print -quit | grep -q .', $script );
		}
	}

	public function testCoreReleaseFileManifestDoesNotDuplicatePackageSurfaces(): void {
		$manifest = $this->readText( dirname( __DIR__ ) . '/release-files.txt' );
		self::assertStringNotContainsString( 'vendor/ran/', $manifest );
		self::assertStringContainsString( 'runtime-packaging-policy.json', $manifest );
	}

	public function testQualityTreatsPackagingPolicyAsFreshEvidenceInputOnly(): void {
		$quality           = $this->readText( dirname( __DIR__ ) . '/.github/workflows/quality.yml' );
		$release           = $this->readText( dirname( __DIR__ ) . '/.github/workflows/release-please.yml' );
		$qualityTrustPaths = $this->trustPathBlock( $quality );

		self::assertStringContainsString( 'runtime-packaging-policy.json', $qualityTrustPaths );
		self::assertStringNotContainsString( 'runtime-packaging-policy.json', $release );
		self::assertStringNotContainsString( 'for trust_path in \\', $release );
		self::assertStringNotContainsString( 'Check out locked neutral updater source', $quality );
		self::assertStringContainsString(
			'git show "${source_commit}:scripts/verify-runtime-dependencies.php"',
			$quality
		);
		self::assertStringContainsString( 'php "$verifier_file" --packaging', $quality );
		self::assertStringContainsString( 'Requires PHP:', $quality );
		self::assertStringContainsString( 'Requires at least:', $quality );
		self::assertStringContainsString( '.php_floor', $quality );
		self::assertStringContainsString( '.wordpress_floor', $quality );
		self::assertStringContainsString( 'version_compare( $argv[1], $argv[2], "<=" )', $quality );
		self::assertStringContainsString( 'booster_requires_php="${booster_requires_php}.0"', $quality );
		self::assertStringContainsString( 'booster_requires_wordpress="${booster_requires_wordpress}.0"', $quality );
		self::assertStringNotContainsString( '[[ "$package_version" =~ ^v?', $quality );
		self::assertStringNotContainsString(
			'dcd9ce2ca20769dc35d6b6bfd46042c17aa53bd3',
			$quality
		);
	}

	public function testDisposableLifecycleFixtureUsesTheVendoredUpdatersStableUserAgent(): void {
		$releaseUpdaterPath = $this->neutralUpdaterArchiveRoot();
		$updater            = $this->readText(
			dirname( __DIR__ )
				. '/' . $releaseUpdaterPath
				. '/src/Provider/GitHub/GitHubReleaseService.php'
		);
		$fixture            = $this->readText(
			dirname( __DIR__ )
				. '/tests/Integration/phase-4.4-core-disposable-harness.php'
		);

		self::assertStringContainsString( "'User-Agent' => 'ran-wp-release-updater',", $updater );
		self::assertStringContainsString(
			"'ran-wp-release-updater' !== ( \$headers['User-Agent'] ?? null )",
			$fixture
		);
	}

	public function testReleaseVerifierRequiresTheSupportedCoreAndPolicyDrivenRuntimeProofs(): void {
		$script = $this->readText( dirname( __DIR__ ) . '/scripts/verify-release.sh' );

		foreach ( array(
			"define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 10 );",
			"define( 'RAN_BOOSTER_ADDON_API_VERSION', 16 );",
			"define( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION', 2 );",
			"define( 'RAN_BOOSTER_PORTABILITY_API_VERSION', PortabilityFacade::API_VERSION );",
			'public const API_VERSION = 2;',
			'runtime-packaging-policy.json',
			'compare_runtime_package',
			'package_surface_specs',
		) as $marker ) {
			self::assertStringContainsString( $marker, $script );
		}
	}

	public function testBuilderVerifiesTheArchiveBeforePublishingItToTheBuildDirectory(): void {
		$script = $this->readText( dirname( __DIR__ ) . '/scripts/build-release.sh' );

		$verify = strpos(
			$script,
			'bash "$script_dir/verify-release.sh" "$tmp_archive" '
				. '"$expected_version" "$commit"'
		);
		$move   = strpos(
			$script,
			'mv -f "$tmp_archive" "$build_dir/$archive_name"'
		);
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
	 * @param array<string, mixed> $policy
	 * @return array<string, array<string, mixed>>
	 */
	private function policyByName( array $policy ): array {
		$byName = array();
		foreach ( $policy['packages'] as $record ) {
			self::assertIsArray( $record );
			$name = $record['name'] ?? null;
			self::assertIsString( $name );
			$byName[ $name ] = $record;
		}
		return $byName;
	}

	/**
	 * @param array<int, array{path: string, kind: string}> $surfaces
	 */
	private function surfaceSpecs( array $surfaces ): string {
		return implode(
			',',
			array_map(
				static fn( array $surface ): string => $surface['kind'] . ':' . $surface['path'],
				$surfaces
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function readJson( string $path ): array {
		$document = json_decode(
			(string) file_get_contents( $path ),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		self::assertIsArray( $document );
		return $document;
	}

	private function readText( string $path ): string {
		$text = file_get_contents( $path );
		self::assertIsString( $text );
		return $text;
	}

	private function neutralUpdaterArchiveRoot(): string {
		foreach ( $this->readPackagingPolicy()['packages'] as $record ) {
			if (
				is_array( $record )
				&& 'neutral-updater' === ( $record['build_role'] ?? null )
			) {
				$root = $record['archive_root'] ?? null;
				self::assertIsString( $root );
				return $root;
			}
		}
		self::fail( 'Runtime packaging policy does not identify a neutral updater.' );
	}

	private function trustPathBlock( string $workflow ): string {
		$start = strpos( $workflow, 'for trust_path in \\' );
		self::assertIsInt( $start );

		$end = strpos( $workflow, '; do', $start );
		self::assertIsInt( $end );

		return substr( $workflow, $start, $end - $start );
	}

	/**
	 * @return array{exit: int, stdout: string, stderr: string}
	 */
	private function runRuntimeDependencyVerifier(
		string $lockPath,
		string $policyPath,
		bool $packaging = false
	): array {
		$command = array(
			PHP_BINARY,
			dirname( __DIR__ ) . '/scripts/verify-runtime-dependencies.php',
		);
		if ( $packaging ) {
			$command[] = '--packaging';
		}
		$command[] = $lockPath;
		$command[] = $policyPath;

		$process = proc_open(
			$command,
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
			json_encode(
				$document,
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
			) . "\n"
		);
		self::assertIsInt( $bytes );
		return $path;
	}

	private function removeTemporaryFile( string $path ): void {
		if ( is_file( $path ) ) {
			unlink( $path );
		}
	}
}
