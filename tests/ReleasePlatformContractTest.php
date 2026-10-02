<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

// CLI-only release contract tests intentionally use direct local file/process primitives.
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions

final class ReleasePlatformContractTest extends TestCase {
	private const RUNTIME_PACKAGING_POLICY = array(
		'ran/booster-github-provider' => array(
			'repository'   => 'RocketsAreNostalgic/ran-booster-github-provider',
			'archive_root' => 'vendor/ran/booster-github-provider',
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
		'ran/updater-support'         => array(
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
		'ran/wp-branch-updater'       => array(
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
		'ran/wp-release-updater'      => array(
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

	public function test_composer_declares_the_zip_runtime_requirement(): void {
		$composer = $this->read_json( dirname( __DIR__ ) . '/composer.json' );

		self::assertSame( '*', $composer['require']['ext-zip'] ?? null );
	}

	public function test_runtime_packaging_policy_owns_the_exact_package_set_and_surfaces(): void {
		$policy = $this->read_packaging_policy();

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

	public function test_composer_lock_pins_exactly_the_policy_approved_packages(): void {
		$lock   = $this->read_json( dirname( __DIR__ ) . '/composer.lock' );
		$policy = $this->read_packaging_policy();

		$packages = is_array( $lock['packages'] ?? null )
			? $lock['packages']
			: array();
		self::assertCount( count( self::RUNTIME_PACKAGING_POLICY ), $packages );

		$by_name = array();
		foreach ( $packages as $package ) {
			self::assertIsArray( $package );
			$name = $package['name'] ?? null;
			self::assertIsString( $name );
			self::assertArrayNotHasKey( $name, $by_name );
			$by_name[ $name ] = $package;
		}

		$policy_by_name = $this->policy_by_name( $policy );
		self::assertSame( array_keys( $policy_by_name ), array_keys( $by_name ) );

		$version_pattern = '/^v?[0-9]+\.[0-9]+\.[0-9]+'
			. '(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D';
		foreach ( $policy_by_name as $name => $record ) {
			$package    = $by_name[ $name ];
			$repository = $record['repository'] ?? null;
			self::assertIsString( $repository );
			$reference = $package['source']['reference'] ?? null;
			self::assertIsString( $reference );
			self::assertMatchesRegularExpression( '/^[0-9a-f]{40}$/D', $reference );
			self::assertMatchesRegularExpression(
				$version_pattern,
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

	public function test_runtime_dependency_verifier_accepts_current_lock_and_policy(): void {
		$result = $this->run_runtime_dependency_verifier(
			dirname( __DIR__ ) . '/composer.lock',
			dirname( __DIR__ ) . '/runtime-packaging-policy.json'
		);

		self::assertSame( 0, $result['exit'], $result['stderr'] );
		$records = array_filter( explode( "\n", trim( $result['stdout'] ) ) );
		self::assertCount( count( self::RUNTIME_PACKAGING_POLICY ), $records );
	}

	public function test_runtime_dependency_packaging_projection_has_stable_typed_fields(): void {
		$lock   = $this->read_json( dirname( __DIR__ ) . '/composer.lock' );
		$policy = $this->read_packaging_policy();
		$result = $this->run_runtime_dependency_verifier(
			dirname( __DIR__ ) . '/composer.lock',
			dirname( __DIR__ ) . '/runtime-packaging-policy.json',
			true
		);
		self::assertSame( 0, $result['exit'], $result['stderr'] );

		$lock_by_name = array();
		foreach ( $lock['packages'] as $package ) {
			self::assertIsArray( $package );
			$name = $package['name'] ?? null;
			self::assertIsString( $name );
			$lock_by_name[ $name ] = $package;
		}

		$records = array_filter( explode( "\n", trim( $result['stdout'] ) ) );
		self::assertCount( count( self::RUNTIME_PACKAGING_POLICY ), $records );
		$seen_neutral_updater = false;
		foreach ( $records as $line ) {
			$fields = explode( "\t", $line );
			self::assertCount( 7, $fields );
			list( $name, $version, $reference, $repository, $root, $surface_specs, $role ) = $fields;
			self::assertArrayHasKey( $name, self::RUNTIME_PACKAGING_POLICY );
			$expected = self::RUNTIME_PACKAGING_POLICY[ $name ];
			self::assertSame( $lock_by_name[ $name ]['version'] ?? null, $version );
			self::assertSame( $lock_by_name[ $name ]['source']['reference'] ?? null, $reference );
			self::assertSame( $expected['repository'], $repository );
			self::assertSame( $expected['archive_root'], $root );
			self::assertSame( $this->surface_specs( $expected['surfaces'] ), $surface_specs );
			$expected_role = $expected['build_role'] ?? '-';
			self::assertSame( $expected_role ?? '-', $role );
			if ( 'neutral-updater' === $role ) {
				self::assertFalse( $seen_neutral_updater );
				$seen_neutral_updater = true;
			}
		}
		self::assertTrue( $seen_neutral_updater );
	}

	public function test_runtime_dependency_verifier_rejects_unexpected_package_at_the_same_count(): void {
		$lock = $this->read_json( dirname( __DIR__ ) . '/composer.lock' );
		self::assertIsArray( $lock['packages'] ?? null );
		self::assertIsArray( $lock['packages'][0] ?? null );
		$lock['packages'][0]['name'] = 'ran/unexpected-runtime';
		$path                        = $this->write_temporary_json( $lock );

		try {
			$result = $this->run_runtime_dependency_verifier(
				$path,
				dirname( __DIR__ ) . '/runtime-packaging-policy.json'
			);
			self::assertNotSame( 0, $result['exit'] );
			self::assertStringContainsString( 'unexpected production package', $result['stderr'] );
		} finally {
			$this->remove_temporary_file( $path );
		}
	}

	public function test_runtime_dependency_verifier_rejects_repository_drift(): void {
		$policy = $this->read_packaging_policy();
		self::assertIsArray( $policy['packages'][0] ?? null );
		$policy['packages'][0]['repository'] = 'RocketsAreNostalgic/not-the-locked-package';
		$path                                = $this->write_temporary_json( $policy );

		try {
			$result = $this->run_runtime_dependency_verifier(
				dirname( __DIR__ ) . '/composer.lock',
				$path
			);
			self::assertNotSame( 0, $result['exit'] );
		} finally {
			$this->remove_temporary_file( $path );
		}
	}

	public function test_runtime_dependency_verifier_rejects_nested_surface_policy(): void {
		$policy = $this->read_packaging_policy();
		self::assertIsArray( $policy['packages'][0] ?? null );
		self::assertIsArray( $policy['packages'][0]['surfaces'][0] ?? null );
		$policy['packages'][0]['surfaces'][0]['path'] = 'nested/LICENSE';
		$path = $this->write_temporary_json( $policy );

		try {
			$result = $this->run_runtime_dependency_verifier(
				dirname( __DIR__ ) . '/composer.lock',
				$path
			);
			self::assertNotSame( 0, $result['exit'] );
			self::assertStringContainsString( 'invalid top-level surface', $result['stderr'] );
		} finally {
			$this->remove_temporary_file( $path );
		}
	}

	public function test_release_scripts_consume_the_shared_typed_packaging_projection(): void {
		foreach ( array( 'build-release.sh', 'verify-release.sh' ) as $script_name ) {
			$script = $this->read_text( dirname( __DIR__ ) . '/scripts/' . $script_name );
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

	public function test_release_scripts_preserve_surface_kinds_and_reject_symlinks(): void {
		foreach ( array( 'build-release.sh', 'verify-release.sh' ) as $script_name ) {
			$script = $this->read_text( dirname( __DIR__ ) . '/scripts/' . $script_name );
			self::assertStringContainsString( 'changed kind', $script );
			self::assertStringContainsString( 'directory surface is empty', $script );
			self::assertStringContainsString( '! -L "$installed_package"', $script );
			self::assertStringContainsString( '-type l -print -quit | grep -q .', $script );
		}
	}

	public function test_core_release_file_manifest_does_not_duplicate_package_surfaces(): void {
		$manifest = $this->read_text( dirname( __DIR__ ) . '/release-files.txt' );
		self::assertStringNotContainsString( 'vendor/ran/', $manifest );
		self::assertStringContainsString( 'runtime-packaging-policy.json', $manifest );
	}

	public function test_quality_keeps_packaging_policy_in_the_repository_owned_archive_boundary(): void {
		$quality = $this->read_text( dirname( __DIR__ ) . '/.github/workflows/quality.yml' );
		$release = $this->read_text( dirname( __DIR__ ) . '/.github/workflows/release-please.yml' );
		$builder = $this->read_text( dirname( __DIR__ ) . '/scripts/build-release.sh' );

		self::assertStringContainsString( 'bash scripts/build-release.sh', $quality );
		self::assertStringContainsString( 'runtime-packaging-policy.json', $builder );
		self::assertStringContainsString( 'scripts/verify-runtime-dependencies.php', $builder );
		self::assertStringNotContainsString( 'runtime-packaging-policy.json', $release );
		self::assertStringNotContainsString( 'for trust_path in \\', $quality );
		self::assertStringNotContainsString( 'Check out locked neutral updater source', $quality );
		self::assertStringNotContainsString( 'dcd9ce2ca20769dc35d6b6bfd46042c17aa53bd3', $quality );
	}

	public function test_disposable_lifecycle_fixture_uses_the_vendored_updaters_stable_user_agent(): void {
		$release_updater_path = $this->neutral_updater_archive_root();
		$updater              = $this->read_text(
			dirname( __DIR__ )
				. '/' . $release_updater_path
				. '/src/Provider/GitHub/GitHubApiClient.php'
		);
		$fixture              = $this->read_text(
			dirname( __DIR__ )
				. '/tests/Integration/phase-4.4-core-disposable-harness.php'
		);

		self::assertMatchesRegularExpression(
			"/'User-Agent'\\s*=>\\s*'ran-wp-release-updater'/",
			$updater
		);
		self::assertStringContainsString(
			"'ran-wp-release-updater' !== ( \$headers['User-Agent'] ?? null )",
			$fixture
		);
	}

	public function test_release_verifier_requires_the_supported_core_and_policy_driven_runtime_proofs(): void {
		$script = $this->read_text( dirname( __DIR__ ) . '/scripts/verify-release.sh' );

		foreach ( array(
			"define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 13 );",
			"define( 'RAN_BOOSTER_ADDON_API_VERSION', 16 );",
			"define( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION', 2 );",
			"define( 'RAN_BOOSTER_PORTABILITY_API_VERSION', PortabilityFacade::API_VERSION );",
			'public const API_VERSION = 3;',
			'runtime-packaging-policy.json',
			'compare_runtime_package',
			'package_surface_specs',
		) as $marker ) {
			self::assertStringContainsString( $marker, $script );
		}
	}

	public function test_builder_verifies_the_archive_before_publishing_it_to_the_build_directory(): void {
		$script = $this->read_text( dirname( __DIR__ ) . '/scripts/build-release.sh' );

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
	private function read_packaging_policy(): array {
		return $this->read_json( dirname( __DIR__ ) . '/runtime-packaging-policy.json' );
	}

	/**
	 * @param array<string, mixed> $policy
	 * @return array<string, array<string, mixed>>
	 */
	private function policy_by_name( array $policy ): array {
		$by_name = array();
		foreach ( $policy['packages'] as $record ) {
			self::assertIsArray( $record );
			$name = $record['name'] ?? null;
			self::assertIsString( $name );
			$by_name[ $name ] = $record;
		}
		return $by_name;
	}

	/**
	 * @param array<int, array{path: string, kind: string}> $surfaces
	 */
	private function surface_specs( array $surfaces ): string {
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
	private function read_json( string $path ): array {
		$document = json_decode(
			(string) file_get_contents( $path ),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		self::assertIsArray( $document );
		return $document;
	}

	private function read_text( string $path ): string {
		$text = file_get_contents( $path );
		self::assertIsString( $text );
		return $text;
	}

	private function neutral_updater_archive_root(): string {
		foreach ( $this->read_packaging_policy()['packages'] as $record ) {
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

	private function trust_path_block( string $workflow ): string {
		$start = strpos( $workflow, 'for trust_path in \\' );
		self::assertIsInt( $start );

		$end = strpos( $workflow, '; do', $start );
		self::assertIsInt( $end );

		return substr( $workflow, $start, $end - $start );
	}

	/**
	 * @return array{exit: int, stdout: string, stderr: string}
	 */
	private function run_runtime_dependency_verifier(
		string $lock_path,
		string $policy_path,
		bool $packaging = false
	): array {
		$command = array(
			PHP_BINARY,
			dirname( __DIR__ ) . '/scripts/verify-runtime-dependencies.php',
		);
		if ( $packaging ) {
			$command[] = '--packaging';
		}
		$command[] = $lock_path;
		$command[] = $policy_path;

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
	private function write_temporary_json( array $document ): string {
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

	private function remove_temporary_file( string $path ): void {
		if ( is_file( $path ) ) {
			unlink( $path );
		}
	}
}
