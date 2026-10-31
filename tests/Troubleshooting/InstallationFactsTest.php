<?php

declare(strict_types=1);

namespace RAN\Tests\Troubleshooting;

use PHPUnit\Framework\TestCase;
use RAN\Admin\AdminAddOnRegistry;
use RAN\Admin\AdminAddOnTab;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Troubleshooting\InstallationFacts;

require_once __DIR__ . '/SupportReportWordPressFunctions.php';
require_once __DIR__ . '/SupportReportProvider.php';

final class InstallationFactsTest extends TestCase {
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_core_configuration_withholds_sensitive_values_and_marks_invalid_archive_admission(): void {
		define( 'RAN_BOOSTER_GITHUB_TOKEN', 'github-private-token-canary' );
		define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', '/private/path-canary' );
		define( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES', 1 );
		$rows = ( new InstallationFacts( new ProviderRegistry() ) )->core_configuration();
		self::assertSame( 'Configured; value withheld', $rows['RAN_BOOSTER_GITHUB_TOKEN'] );
		self::assertSame( 'Configured; value withheld', $rows['RAN_BOOSTER_ENCRYPTED_SECRETS_DIR'] );
		self::assertSame( 'Invalid code value; archive admission refused', $rows['RAN_BOOSTER_MAX_ARCHIVE_BYTES'] );
		self::assertStringNotContainsString( 'canary', implode( ' ', $rows ) );
	}

	public function test_environment_uses_selected_technical_fields_and_strips_identity(): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Selected runtime version fixture for the environment boundary.
		$GLOBALS['wp_version']      = '7.0.1';
		$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.28.0 private-host-canary.test (private account)';
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Runtime-fact boundary fixture, never an actual WordPress connection.
		$GLOBALS['wpdb'] = new class() {
			public function db_version(): string {
				return '8.4.6';
			}
		};
		$rows            = ( new InstallationFacts( new ProviderRegistry() ) )->environment();
		self::assertSame( '7.0.1', $rows['WordPress'] );
		self::assertSame( '8.4.6', $rows['Database'] );
		self::assertSame( 'nginx/1.28.0', $rows['Web server'] );
		self::assertSame( PHP_SAPI, $rows['PHP SAPI'] );
		self::assertStringNotContainsString( 'private', implode( ' ', $rows ) );
		foreach ( array( 'zip', 'sodium', 'curl', 'json', 'openssl', 'mbstring' ) as $extension ) {
			self::assertContains( $rows[ 'PHP extension ' . $extension ], array( 'Present', 'Absent' ) );
		}
	}

	public function test_unknown_environment_and_malformed_versions_do_not_escape_the_boundary(): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Malformed runtime version fixture for the environment boundary.
		$GLOBALS['wp_version']      = "7.0.1\nprivate-version-canary";
		$_SERVER['SERVER_SOFTWARE'] = "private-host-canary\xff";
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Runtime-fact boundary fixture, never an actual WordPress connection.
		$GLOBALS['wpdb'] = new \stdClass();
		$rows            = ( new InstallationFacts( new ProviderRegistry() ) )->environment();
		self::assertSame( 'Unknown', $rows['WordPress'] );
		self::assertSame( 'Unknown', $rows['Database'] );
		self::assertSame( 'Unknown', $rows['Web server'] );
		foreach ( array( '1.2.3+private-customer', '1.2.3-private-client', "1.2.3\0", "1.2.3\xff", str_repeat( '1', 81 ), array(), null ) as $unshareable ) {
			self::assertSame( 'Unknown', InstallationFacts::version( $unshareable ) );
		}
		foreach ( array( '1.2.3', '1.0.0-beta.41', '8.5.0', '1.0.0-rc.2' ) as $version ) {
			self::assertSame( $version, InstallationFacts::version( $version ) );
		}
	}

	public function test_components_reuse_registered_local_metadata_and_bound_admitted_rows(): void {
		$providers = array();
		for ( $index = 0; $index < 40; ++$index ) {
			$providers[] = new SupportReportProvider( 'provider' . $index, 'Component ' . $index );
		}
		$registry = new ProviderRegistry( $providers );
		foreach ( $providers as $provider ) {
			$provider->registered = true;
		}
		$facts      = new InstallationFacts( $registry );
		$components = $facts->components();
		self::assertCount( 32, $components );
		self::assertTrue( $facts->components_truncated() );
		self::assertSame( 'Component 0', $components[0]['Name'] );
		self::assertSame( 'provider31', $components[31]['Provider'] );
		self::assertSame( 'Available locally; remote access not checked', $components[0]['Availability'] );
		self::assertSame( $facts->implementation( 'provider0' ), $components[0]['Implementation'] );
		$GLOBALS['ran_booster_support_version'] = '1.0.0+private-version-canary';
		self::assertSame( 'Unknown', $facts->core()['installed_version'] );
		$GLOBALS['ran_booster_support_version'] = '1.0.0-beta.41';
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_catalogue_joins_only_active_official_provider_without_executing_package(): void {
		require_once __DIR__ . '/ExtensionDeclarationFunctions.php';
		require_once __DIR__ . '/InactivePluginFunction.php';
		require_once __DIR__ . '/OfficialBitbucketProviderFixture.php';
		require_once __DIR__ . '/CustomBitbucketProviderFixture.php';
		$root = sys_get_temp_dir() . '/booster-components-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Isolated temporary fixture, without WordPress filesystem services.
		mkdir( $root . '/ran-booster-bitbucket', 0777, true );
		$file = $root . '/ran-booster-bitbucket/ran-booster-bitbucket.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Isolated inert plugin fixture.
		file_put_contents( $file, '<?php throw new Exception("must not execute");' );
		define( 'WP_PLUGIN_DIR', $root );
		try {
			$addons = new AdminAddOnRegistry();
			$addons->register(
				new AdminAddOnTab(
					'ran-booster-bitbucket',
					'bitbucket',
					'Bitbucket',
					static function (): never {
						throw new \RuntimeException( 'must not render' );
					}
				)
			);
			foreach ( array( false, true ) as $active ) {
				$GLOBALS['ran_booster_test_plugin_active'] = $active;
				$custom                                    = new ProviderRegistry( array( new \RAN\Booster\Bitbucket\CustomBitbucketProvider() ) );
				$rows                                      = ( new InstallationFacts( $custom, add_ons: $addons ) )->components();
				self::assertCount( 3, $rows );
				self::assertSame( 'Custom provider', $rows[0]['Name'] );
				self::assertStringContainsString( 'CredentialValidator', $rows[0]['Capabilities'] );
				self::assertArrayNotHasKey( 'Installed', $rows[0] );
				self::assertSame( 'bitbucket', $rows[1]['Tab'] );
				self::assertSame( 'Admitted', $rows[1]['Admission'] );
				self::assertArrayNotHasKey( 'Installed', $rows[1] );
				self::assertSame( 'Bitbucket Cloud', $rows[2]['Name'] );
				self::assertSame( $active ? 'Yes' : 'No', $rows[2]['Active'] );
				self::assertSame( '1.0.0-beta.41', $rows[2]['Version'] );
				self::assertArrayNotHasKey( 'Admission', $rows[2] );
				self::assertNotContains( 'WP Pusher Migrator', array_column( $rows, 'Name' ) );
			}
			$official = new ProviderRegistry( array( new \RAN\Booster\Bitbucket\BitbucketProvider() ) );
			$rows     = ( new InstallationFacts( $official, add_ons: $addons ) )->components();
			self::assertCount( 1, $rows );
			self::assertSame( 'bb', $rows[0]['Provider'] );
			self::assertSame( 'Yes', $rows[0]['Active'] );
			self::assertSame( 'ran-booster-bitbucket', $rows[0]['Add-on'] );
			$GLOBALS['ran_booster_test_plugin_active'] = false;
			$rows                                      = ( new InstallationFacts( new ProviderRegistry() ) )->components();
			self::assertCount( 1, $rows );
			self::assertSame( 'No', $rows[0]['Active'] );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove the isolated fixture.
			unlink( $file );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove the isolated fixture directory.
			rmdir( dirname( $file ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove the isolated fixture root.
			rmdir( $root );
		}
	}

	public function test_future_provider_capabilities_are_observed_without_callbacks(): void {
		$provider = new class() implements \RAN\RepositoryProvider\RepositoryProvider, \RAN\RepositoryProvider\CredentialValidator {
			use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderManualCapabilities;

			public function get_metadata(): \RAN\RepositoryProvider\ProviderMetadata {
				return new \RAN\RepositoryProvider\ProviderMetadata( \RAN\RepositoryProvider\ProviderCode::parse( 'gl' ), 'GitLab', 'https://gitlab.example/', 'Owner' );
			}
			public function get_provider_diagnostics(): \RAN\RepositoryProvider\ProviderDiagnostics {
				return new class() implements \RAN\RepositoryProvider\ProviderDiagnostics {
					// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- Fail if passive observation runs diagnostics.
					public function diagnose( \RAN\RepositoryProvider\ProviderDiagnosticRequest $request ): array {
						throw new \RuntimeException( 'Diagnostics must not run.' );
					}
				};
			}
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- Fail if passive observation validates credentials.
			public function validate_credential( string $credential_id ): \RAN\RepositoryProvider\CredentialValidationResult {
				throw new \RuntimeException( 'Credential validation must not run.' );
			}
		};
		$rows     = ( new InstallationFacts( new ProviderRegistry( array( $provider ) ) ) )->components();
		self::assertSame( 'GitLab', $rows[0]['Name'] );
		self::assertStringContainsString( 'CredentialValidator', $rows[0]['Capabilities'] );
	}

	public function test_github_attribution_is_one_captured_bootstrap_observation(): void {
		$registry   = new ProviderRegistry( array( new SupportReportProvider( 'gh', 'GitHub' ) ) );
		$generation = 1;
		$reads      = 0;
		$observe    = static function () use ( &$generation, &$reads ): array {
			++$reads;
			return array(
				'state'                  => 'missing',
				'admitted'               => false,
				'selected'               => 'external',
				'version'                => '2.3.4',
				'provider_api'           => 15,
				'compatibility_revision' => '1',
				'identity'               => array(
					'provider_code'              => 'gh',
					'selection_generation'       => $generation,
					'implementation_fingerprint' => str_repeat( 'a', 64 ),
				),
			);
		};
		$facts      = new InstallationFacts( $registry, github_observation: $observe );
		$first      = $facts->implementation( 'gh' );
		self::assertSame( 'missing', $facts->github_selection()['state'] );
		$generation = 2;
		self::assertSame( $first, $facts->implementation( 'gh' ) );
		self::assertSame( 1, $reads );
		self::assertNotSame( $first, ( new InstallationFacts( $registry, github_observation: $observe ) )->implementation( 'gh' ) );
		self::assertSame( 2, $reads );
	}

	public function test_registered_addons_share_provider_bound_without_running_renderers(): void {
		$addons = new AdminAddOnRegistry();
		for ( $index = 0; $index < 35; ++$index ) {
			$addons->register(
				new AdminAddOnTab(
					'addon' . $index,
					'addon' . $index,
					'Private Addon ' . $index,
					static function (): never {
						throw new \RuntimeException( 'A passive observation may not render add-ons.' );
					}
				)
			);
		}
		$facts      = new InstallationFacts( new ProviderRegistry(), add_ons: $addons );
		$components = $facts->components();
		self::assertCount( 32, $components );
		self::assertTrue( $facts->components_truncated() );
		self::assertSame( 'Private Addon 31', $components[31]['Name'] );
		self::assertSame( 'Unknown', $components[0]['Version'] );
		self::assertSame( 'Admitted', $components[0]['Admission'] );
		self::assertFalse( ( new InstallationFacts( new ProviderRegistry(), add_ons: new AdminAddOnRegistry() ) )->components_truncated() );
	}
}
