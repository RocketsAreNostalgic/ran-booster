<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\WordPress\CoreSelfUpdatePolicy;

#[CoversClass( CoreSelfUpdatePolicy::class )]
final class CoreSelfUpdatePolicyTest extends TestCase {

	// Test fixtures intentionally use direct temporary-file operations and PHP
	// JSON encoding so this pure policy remains independent from WordPress APIs.

	private string $directory;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/ran-booster-self-update-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable focused fixture setup.
		self::assertTrue( mkdir( $this->directory, 0700 ) );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		foreach ( array( '.git', 'composer.json', 'ran-booster-release.json', 'ran-booster.php' ) as $entry ) {
			$path = $this->directory . '/' . $entry;
			if ( is_file( $path ) || is_link( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Disposable focused fixture cleanup.
				unlink( $path );
			} elseif ( is_dir( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable focused fixture cleanup.
				rmdir( $path );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable focused fixture cleanup.
		rmdir( $this->directory );
	}

	public function test_auto_mode_fails_closed_without_an_official_release_marker(): void {
		$policy = CoreSelfUpdatePolicy::detect( plugin_file: $this->plugin_file(), plugin_version: '1.2.3' );

		self::assertFalse( $policy->allows_native_discovery() );
		self::assertSame( 'auto', $policy->diagnostics()['requested_mode'] );
		self::assertSame( 'release_marker_missing_or_invalid', $policy->diagnostics()['reason'] );
	}

	public function test_auto_mode_allows_avalid_official_release_marker(): void {
		$this->write_marker( '1.2.3', str_repeat( 'a', 40 ) );

		$policy = CoreSelfUpdatePolicy::detect( plugin_file: $this->plugin_file(), plugin_version: '1.2.3' );

		self::assertTrue( $policy->allows_native_discovery() );
		self::assertSame(
			array(
				'requested_mode' => 'auto',
				'effective_mode' => 'enabled',
				'reason'         => 'verified_release',
				'marker_version' => '1.2.3',
				'marker_commit'  => str_repeat( 'a', 40 ),
			),
			$policy->diagnostics()
		);
	}

	public function test_source_checkout_wins_over_an_otherwise_valid_marker(): void {
		$this->write_marker( '1.2.3', str_repeat( 'b', 40 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable focused fixture setup.
		self::assertTrue( mkdir( $this->directory . '/.git', 0700 ) );

		$policy = CoreSelfUpdatePolicy::detect( plugin_file: $this->plugin_file(), plugin_version: '1.2.3' );

		self::assertFalse( $policy->allows_native_discovery() );
		self::assertSame( 'source_checkout', $policy->diagnostics()['reason'] );
	}

	public function test_composer_source_metadata_disables_auto_mode(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable focused fixture setup.
		self::assertIsInt( file_put_contents( $this->directory . '/composer.json', "{}\n" ) );

		$policy = CoreSelfUpdatePolicy::detect( plugin_file: $this->plugin_file(), plugin_version: '1.2.3' );

		self::assertFalse( $policy->allows_native_discovery() );
		self::assertSame( 'source_checkout', $policy->diagnostics()['reason'] );
	}

	public function test_rejects_mismatched_malformed_and_expanded_markers(): void {
		foreach (
			array(
				array(
					'schema'         => 'ran-booster-core-release',
					'schema_version' => 1,
					'version'        => '9.9.9',
					'commit'         => str_repeat( 'a', 40 ),
				),
				array(
					'schema'         => 'ran-booster-core-release',
					'schema_version' => 1,
					'version'        => '1.2.3',
					'commit'         => 'not-a-commit',
				),
				array(
					'schema'         => 'ran-booster-core-release',
					'schema_version' => 1,
					'version'        => '1.2.3',
					'commit'         => str_repeat( 'a', 40 ),
					'extra'          => true,
				),
			) as $marker
		) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable focused fixture setup.
			self::assertIsInt(
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The test writes an exact owned temporary fixture before WordPress is loaded; the native filesystem operation is part of this proof.
				file_put_contents(
					$this->directory . '/ran-booster-release.json',
					// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The standalone fixture writes deterministic JSON before the WordPress runtime is loaded.
					(string) json_encode( $marker )
				)
			);

			$policy = CoreSelfUpdatePolicy::detect( plugin_file: $this->plugin_file(), plugin_version: '1.2.3' );
			self::assertFalse( $policy->allows_native_discovery() );
		}
	}

	public function test_rejects_an_oversized_or_symlinked_marker(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable focused fixture setup.
		self::assertIsInt(
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The test writes an exact owned temporary fixture before WordPress is loaded; the native filesystem operation is part of this proof.
			file_put_contents(
				$this->directory . '/ran-booster-release.json',
				str_repeat( 'x', 4097 )
			)
		);
		self::assertFalse(
			CoreSelfUpdatePolicy::detect( plugin_file: $this->plugin_file(), plugin_version: '1.2.3' )->allows_native_discovery()
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Disposable focused fixture setup.
		unlink( $this->directory . '/ran-booster-release.json' );
		$target = $this->directory . '/marker-target.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable focused fixture setup.
		self::assertIsInt( file_put_contents( $target, "{}\n" ) );
		self::assertTrue( symlink( $target, $this->directory . '/ran-booster-release.json' ) );
		self::assertFalse(
			CoreSelfUpdatePolicy::detect( plugin_file: $this->plugin_file(), plugin_version: '1.2.3' )->allows_native_discovery()
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Disposable focused fixture cleanup.
		unlink( $target );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_enabled_override_allows_disposable_update_testing_without_amarker(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- Exercise the exact runtime configuration marker selected by this fixture.
		define( CoreSelfUpdatePolicy::CONFIGURATION, 'enabled' );

		$policy = CoreSelfUpdatePolicy::detect( plugin_file: $this->plugin_file(), plugin_version: '1.2.3' );

		self::assertTrue( $policy->allows_native_discovery() );
		self::assertSame( 'configuration_enabled', $policy->diagnostics()['reason'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_disabled_override_wins_over_avalid_marker(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- Exercise the exact runtime configuration marker selected by this fixture.
		define( CoreSelfUpdatePolicy::CONFIGURATION, 'disabled' );
		$this->write_marker( '1.2.3', str_repeat( 'c', 40 ) );

		$policy = CoreSelfUpdatePolicy::detect( plugin_file: $this->plugin_file(), plugin_version: '1.2.3' );

		self::assertFalse( $policy->allows_native_discovery() );
		self::assertSame( 'configuration_disabled', $policy->diagnostics()['reason'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_invalid_override_fails_closed(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- Exercise the exact runtime configuration marker selected by this fixture.
		define( CoreSelfUpdatePolicy::CONFIGURATION, 'development' );

		$policy = CoreSelfUpdatePolicy::detect( plugin_file: $this->plugin_file(), plugin_version: '1.2.3' );

		self::assertFalse( $policy->allows_native_discovery() );
		self::assertSame( 'invalid', $policy->diagnostics()['requested_mode'] );
		self::assertSame( 'configuration_invalid', $policy->diagnostics()['reason'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_bootstrap_policy_follows_the_installed_version_through_a_native_self_update(): void {
		$this->write_plugin_header( '1.2.3' );
		$this->write_marker( '1.2.3', str_repeat( 'a', 40 ) );
		$core_policy = $this->bootstrap_policy();
		self::assertTrue( $core_policy()->allows_native_discovery() );

		// WordPress copies the new release into place while the updating request is still running.
		$this->write_plugin_header( '1.2.4' );
		$this->write_marker( '1.2.4', str_repeat( 'b', 40 ) );
		self::assertTrue( $core_policy()->allows_native_discovery() );
		self::assertSame( '1.2.4', $core_policy()->diagnostics()['marker_version'] );

		// The installed header and marker must still name the same release.
		$this->write_marker( '1.2.3', str_repeat( 'a', 40 ) );
		self::assertSame( 'release_marker_missing_or_invalid', $core_policy()->diagnostics()['reason'] );

		// A source checkout is still refused whatever its header and marker say.
		$this->write_marker( '1.2.4', str_repeat( 'b', 40 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable focused fixture setup.
		self::assertTrue( mkdir( $this->directory . '/.git', 0700 ) );
		self::assertSame( 'source_checkout', $core_policy()->diagnostics()['reason'] );
	}

	/**
	 * Builds the policy callback from the exact production bootstrap statements.
	 *
	 * @return callable():CoreSelfUpdatePolicy
	 */
	private function bootstrap_policy(): callable {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the local source bytes whose exact statements this test executes.
		$bootstrap = (string) file_get_contents( dirname( __DIR__, 2 ) . '/ran-booster.php' );
		$start     = strpos( $bootstrap, '$core_version        = ' );
		self::assertIsInt( $start );
		$policy = strpos( $bootstrap, '$core_policy         = static fn', $start );
		self::assertIsInt( $policy );
		$end = strpos( $bootstrap, "\n", $policy );
		self::assertIsInt( $end );
		$statements = substr( $bootstrap, $start, $end - $start );
		self::assertSame( 2, substr_count( $statements, '__FILE__' ) );
		self::assertSame( 3, substr_count( $statements, ";\n" ) + 1 );

		// The fresh preference read sees no saved General Settings row.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- This isolated process supplies the bounded option-read database boundary.
		$GLOBALS['wpdb'] = new class() {

			public string $options    = 'wp_options';
			public string $last_error = '';

			public function prepare( string $query, mixed ...$arguments ): string {
				unset( $arguments );

				return $query;
			}

			public function get_var( string $query ): null {
				unset( $query );

				return null;
			}
		};
		$plugin_file     = $this->plugin_file();
		self::assertStringEndsWith( '/ran-booster.php', $plugin_file );
		$header_reader = 'function get_file_data( string $file, array $headers ): array { unset( $headers ); return array( "version" => 1 === preg_match( "/^[ \\t\\/*#@]*Version:(.*)$/mi", (string) file_get_contents( $file ), $match ) ? trim( $match[1] ) : "" ); }';
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test executes the exact bounded production statements rather than reimplementing them.
		$core_policy = eval( 'namespace RAN\\Tests\\WordPress\\CoreSelfUpdateBootstrapFixture; use RAN\\WordPress\\CoreSelfUpdatePolicy; ' . $header_reader . ' ' . str_replace( '__FILE__', '$plugin_file', $statements ) . ' return $core_policy;' );
		self::assertIsCallable( $core_policy );

		return $core_policy;
	}

	private function write_plugin_header( string $version ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable focused fixture setup.
		self::assertIsInt( file_put_contents( $this->plugin_file(), "<?php\n/*\nPlugin Name: RAN Booster\nVersion: $version\n*/\n" ) );
	}

	private function plugin_file(): string {
		return $this->directory . '/ran-booster.php';
	}

	private function write_marker( string $version, string $commit ): void {
		$marker = array(
			'schema'         => 'ran-booster-core-release',
			'schema_version' => 1,
			'version'        => $version,
			'commit'         => $commit,
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable focused fixture setup.
		self::assertIsInt(
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The test writes an exact owned temporary fixture before WordPress is loaded; the native filesystem operation is part of this proof.
			file_put_contents(
				$this->directory . '/ran-booster-release.json',
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The standalone fixture writes deterministic JSON before the WordPress runtime is loaded.
				(string) json_encode( $marker )
			)
		);
	}
}
