<?php

declare(strict_types=1);

namespace Tests\Portability;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RAN\ManagedRepository;
use RAN\Package;
use RAN\PackageSource;
use RAN\Portability\LocalSecretStoreUnavailable;
use RAN\Portability\ManagedPackageBlueprintExporter;
use RAN\Portability\UnsupportedBlueprintPackages;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\SecretsRuntimeAvailability;
use RAN\Storage\Database;
use RAN\Storage\DatabaseLifecycleFailure;
use RAN\Storage\PackageStorageFailure;
use Tests\RepositoryProvider\Support\ShippedSecretPolicyCatalog;
use Tests\Secrets\SecretsFileTestFactory;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;

require_once dirname( __DIR__ ) . '/Storage/StorageTestEnvironment.php';

#[CoversClass( ManagedPackageBlueprintExporter::class )]
final class ManagedPackageBlueprintExporterTest extends TestCase {

	public function test_it_uses_only_the_non_cleaning_managed_package_readers(): void {
		$plugin  = $this->package( 'plugin/example.php', 'example', 'plugin-repository-id' );
		$theme   = $this->package( 'example-theme', 'example-theme', 'theme-repository-id' );
		$plugins = $this->createMock( PluginRepository::class );
		$themes  = $this->createMock( ThemeRepository::class );

		$plugins->expects( self::once() )->method( 'all_deployment_plugins' )->willReturn( array( 'plugin/example.php' => $plugin ) );
		$themes->expects( self::once() )->method( 'all_deployment_themes' )->willReturn( array( 'example-theme' => $theme ) );

		$blueprint = ( new ManagedPackageBlueprintExporter( $plugins, $themes, new SecretsFile( null, array() ) ) )->export();

		self::assertSame( array( 'plugin', 'theme' ), array_column( $blueprint->packages, 'type' ) );
		self::assertSame( array( 'plugin/example.php', 'example-theme' ), array_column( $blueprint->packages, 'identifier' ) );
		self::assertSame( array( 'Plugin Example', 'Example Theme' ), array_column( $blueprint->packages, 'display_name' ) );
		self::assertStringNotContainsString( 'credential-id-canary', $blueprint->canonical_json() );
	}

	public function test_package_only_export_remains_available_when_encrypted_secrets_runtime_is_unavailable(): void {
		$plugin  = $this->package( 'plugin/example.php', 'example', 'plugin-repository-id' );
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array( 'plugin/example.php' => $plugin ) );
		$themes->method( 'all_deployment_themes' )->willReturn( array() );
		$secrets = new SecretsFile(
			constants: array(),
			provider_policies: new ProviderSecretPolicyCatalog(),
			availability: new SecretsRuntimeAvailability( false, false )
		);

		$blueprint = ( new ManagedPackageBlueprintExporter( $plugins, $themes, $secrets ) )->export();

		self::assertSame( array( 'plugin/example.php' ), array_column( $blueprint->packages, 'identifier' ) );
		self::assertSame( array(), $blueprint->credentials );
	}

	public function test_lifecycle_safe_state_blocks_export_through_the_package_storage_reader(): void {
		$lifecycle = $this->createStub( Database::class );
		$lifecycle->method( 'require_ready' )->willThrowException( new DatabaseLifecycleFailure( 'schema_operation_failed' ) );
		$exporter = new ManagedPackageBlueprintExporter(
			new PluginRepository( $lifecycle ),
			new ThemeRepository( $lifecycle ),
			new SecretsFile( null, array() )
		);

		try {
			$exporter->export();
			self::fail( 'Portability must not bypass the package-storage safe state.' );
		} catch ( PackageStorageFailure $failure ) {
			self::assertSame( 'ran_booster_storage_database_unsupported', $failure->get_diagnostic_id() );
		}
	}

	public function test_blueprint_v1_rejects_release_managed_packages_instead_of_converting_them_to_branch(): void {
		$plugin  = $this->package( 'plugin/example.php', 'example', 'plugin-repository-id', source: PackageSource::RELEASE_ASSET );
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array( 'plugin/example.php' => $plugin ) );
		$themes->method( 'all_deployment_themes' )->willReturn( array() );

		try {
			( new ManagedPackageBlueprintExporter( $plugins, $themes, new SecretsFile( null, array() ) ) )->export();
			self::fail( 'Release-managed packages must not be converted to branch packages.' );
		} catch ( UnsupportedBlueprintPackages $failure ) {
			self::assertSame( 'The selected packages are unavailable to the current Blueprint format.', $failure->getMessage() );
			self::assertSame( 'Plugin Example', $failure->failures[0]->display_name );
		}
	}

	public function test_blueprint_v1_reports_every_selected_unsupported_package_before_rejecting_the_export(): void {
		$plugin  = $this->package( 'plugin/example.php', 'example', 'plugin-repository-id', source: PackageSource::RELEASE_ASSET );
		$theme   = $this->package( 'example-theme', 'example-theme', 'theme-repository-id', source: PackageSource::RELEASE_ASSET );
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array( 'plugin/example.php' => $plugin ) );
		$themes->method( 'all_deployment_themes' )->willReturn( array( 'example-theme' => $theme ) );

		try {
			( new ManagedPackageBlueprintExporter( $plugins, $themes, new SecretsFile( null, array() ) ) )->export();
			self::fail( 'Every selected unsupported package must block an all-or-nothing Blueprint export.' );
		} catch ( UnsupportedBlueprintPackages $failure ) {
			self::assertSame( array( 'Plugin Example', 'Example Theme' ), array_column( $failure->failures, 'display_name' ) );
		}
	}

	public function test_credential_bearing_export_fails_with_typed_local_store_category(): void {
		$plugin  = $this->package( 'plugin/example.php', 'example', 'plugin-repository-id' );
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array( 'plugin/example.php' => $plugin ) );
		$themes->method( 'all_deployment_themes' )->willReturn( array() );
		$secrets = new SecretsFile(
			constants: array(),
			provider_policies: new ProviderSecretPolicyCatalog(),
			availability: new SecretsRuntimeAvailability( false, false )
		);

		try {
			( new ManagedPackageBlueprintExporter( $plugins, $themes, $secrets ) )->export( array( 'gh' => array( 'credential-id-canary' ) ) );
			self::fail( 'A credential-bearing export must not silently omit an unavailable managed credential.' );
		} catch ( LocalSecretStoreUnavailable $failure ) {
			self::assertSame( 'local_secret_store_unavailable', $failure::CATEGORY );
		}
	}

	public function test_empty_credential_selection_does_not_inspect_storage_for_packages_without_credentials(): void {
		$plugin  = $this->package( 'plugin/example.php', 'example', 'plugin-repository-id', credential_id: '' );
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array( 'plugin/example.php' => $plugin ) );
		$themes->method( 'all_deployment_themes' )->willReturn( array() );
		$secrets = new SecretsFile(
			constants: array(),
			provider_policies: new ProviderSecretPolicyCatalog(),
			availability: new SecretsRuntimeAvailability( false, false )
		);

		$blueprint = ( new ManagedPackageBlueprintExporter( $plugins, $themes, $secrets ) )->export();

		self::assertCount( 1, $blueprint->packages );
		self::assertSame( array(), $blueprint->credentials );
	}

	public function test_it_exports_one_file_credential_only_when_explicitly_requested(): void {
		$plugin  = $this->package( 'plugin/example.php', 'example', 'plugin-repository-id', false );
		$theme   = $this->package( 'example-theme', 'example-theme', 'theme-repository-id' );
		$plugins = $this->createMock( PluginRepository::class );
		$themes  = $this->createMock( ThemeRepository::class );
		$path    = sys_get_temp_dir() . '/ran-booster-exporter-' . bin2hex( random_bytes( 8 ) ) . '.php';
		$secrets = SecretsFileTestFactory::create( $path, array(), ShippedSecretPolicyCatalog::create() );
		try {
			$secrets->save_credential(
				'gh',
				'credential-id-canary',
				array(
					'label'         => 'Shared deployment token',
					'kind'          => 'classic',
					'configuration' => array(),
				),
				'secret-canary'
			);

			$plugins->expects( self::once() )->method( 'all_deployment_plugins' )->willReturn( array( 'plugin/example.php' => $plugin ) );
			$themes->expects( self::once() )->method( 'all_deployment_themes' )->willReturn( array( 'example-theme' => $theme ) );

			$blueprint = ( new ManagedPackageBlueprintExporter( $plugins, $themes, $secrets ) )->export( array( 'gh' => array( 'credential-id-canary' ) ) );

			self::assertCount( 1, $blueprint->credentials );
			self::assertSame( 'secret-canary', $blueprint->credentials[0]->secret );
			self::assertSame(
				array(
					array(
						'type'       => 'plugin',
						'identifier' => 'plugin/example.php',
					),
					array(
						'type'       => 'theme',
						'identifier' => 'example-theme',
					),
				),
				$blueprint->credentials[0]->to_array()['packages']
			);
			self::assertStringNotContainsString( 'credential-id-canary', $blueprint->canonical_json() );
		} finally {
			foreach ( array( $path, $path . '.lock' ) as $file ) {
				if ( is_file( $file ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup removes only its unique temporary sidecar.
					unlink( $file );
				}
			}
		}
	}

	public function test_it_exports_only_the_exact_selected_credential_subset_without_kind_preference(): void {
		$plugin  = $this->package( 'plugin/classic.php', 'classic', 'classic-repository-id', credential_id: 'classic-profile' );
		$theme   = $this->package( 'fine-theme', 'fine-theme', 'fine-repository-id', credential_id: 'fine-profile' );
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$path    = sys_get_temp_dir() . '/ran-booster-exporter-' . bin2hex( random_bytes( 8 ) ) . '.php';
		$secrets = SecretsFileTestFactory::create( $path, array(), ShippedSecretPolicyCatalog::create() );
		try {
			$secrets->save_credential(
				'gh',
				'classic-profile',
				array(
					'label'         => 'Classic',
					'kind'          => 'classic',
					'configuration' => array(),
				),
				'classic-secret-canary'
			);
			$secrets->save_credential(
				'gh',
				'fine-profile',
				array(
					'label'         => 'Fine-grained',
					'kind'          => 'fine-grained',
					'configuration' => array( 'owner' => 'example' ),
				),
				'fine-secret-canary'
			);
			$plugins->method( 'all_deployment_plugins' )->willReturn( array( 'plugin/classic.php' => $plugin ) );
			$themes->method( 'all_deployment_themes' )->willReturn( array( 'fine-theme' => $theme ) );
			$exporter = new ManagedPackageBlueprintExporter( $plugins, $themes, $secrets );

			$classic = $exporter->export( array( 'gh' => array( 'classic-profile' ) ) );
			$both    = $exporter->export( array( 'gh' => array( 'classic-profile', 'fine-profile' ) ) );

			self::assertSame( array( 'classic' ), array_column( $classic->credentials, 'kind' ) );
			self::assertSame( array( 'classic', 'fine-grained' ), array_column( $both->credentials, 'kind' ) );
			self::assertSame( array( 'plugin/classic.php' ), array_column( $classic->credentials[0]->packages, 'identifier' ) );
			self::assertStringNotContainsString( 'fine-secret-canary', $classic->canonical_json() );
			self::assertStringNotContainsString( 'classic-profile', $both->canonical_json() );
			self::assertStringNotContainsString( 'fine-profile', $both->canonical_json() );
		} finally {
			foreach ( array( $path, $path . '.lock' ) as $file ) {
				if ( is_file( $file ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup removes only its unique temporary sidecar.
					unlink( $file );
				}
			}
		}
	}

	public function test_provider_and_manual_expiry_observations_do_not_block_or_enter_the_blueprint(): void {
		$plugin  = $this->package( 'plugin/expiring.php', 'expiring', 'expiring-repository-id', credential_id: 'expiring-profile' );
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array( 'plugin/expiring.php' => $plugin ) );
		$themes->method( 'all_deployment_themes' )->willReturn( array() );
		$secrets = new class() extends SecretsFile {
			public function __construct() {
				parent::__construct( null, array() );
			}
			public function assert_managed_storage_ready(): void {
			}
			public function credential_material( \RAN\RepositoryProvider\ProviderCode|string $provider, ?string $id = null ): ?array {
				$provider_code = $provider instanceof \RAN\RepositoryProvider\ProviderCode ? $provider->value : $provider;

				return array(
					'id'                  => $id,
					'provider'            => $provider_code,
					'label'               => 'Normally expiring token',
					'kind'                => 'fine-grained',
					'configuration'       => array( 'owner' => 'RocketsAreNostalgic' ),
					'source'              => 'file',
					'immutable'           => false,
					'configured'          => true,
					'self_destruct'       => false,
					'destroy_on'          => null,
					'manual_expires_on'   => '2099-01-01',
					'provider_expires_at' => '2099-01-02T03:04:05Z',
					'provider_checked_at' => '2026-08-08T12:00:00Z',
					'secret'              => 'expiry-observation-secret-canary',
				);
			}
		};

		$blueprint = ( new ManagedPackageBlueprintExporter( $plugins, $themes, $secrets ) )->export(
			array( 'gh' => array( 'expiring-profile' ) )
		);
		$json      = $blueprint->canonical_json();

		self::assertCount( 1, $blueprint->credentials );
		self::assertSame( 'fine-grained', $blueprint->credentials[0]->kind );
		self::assertStringNotContainsString( 'manual_expires_on', $json );
		self::assertStringNotContainsString( 'provider_expires_at', $json );
		self::assertStringNotContainsString( 'provider_checked_at', $json );
		self::assertStringNotContainsString( '2099-01', $json );
		self::assertStringNotContainsString( '2026-08-08', $json );
	}

	public function test_it_rejects_aselected_self_destruct_credential(): void {
		$plugin  = $this->package( 'plugin/lifecycle.php', 'lifecycle', 'lifecycle-repository-id', credential_id: 'lifecycle-profile-canary' );
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$path    = sys_get_temp_dir() . '/ran-booster-exporter-' . bin2hex( random_bytes( 8 ) ) . '.php';
		$secrets = SecretsFileTestFactory::create( $path, array(), ShippedSecretPolicyCatalog::create() );
		try {
			$secrets->save_credential(
				'gh',
				'lifecycle-profile-canary',
				array(
					'label'         => 'Lifecycle characterization credential',
					'kind'          => 'classic',
					'configuration' => array(),
					'self_destruct' => true,
					'destroy_on'    => '2099-12-31',
				),
				'lifecycle-secret-canary'
			);
			$plugins->method( 'all_deployment_plugins' )->willReturn( array( 'plugin/lifecycle.php' => $plugin ) );
			$themes->method( 'all_deployment_themes' )->willReturn( array() );

			$this->expectException( InvalidArgumentException::class );
			( new ManagedPackageBlueprintExporter( $plugins, $themes, $secrets ) )->export( array( 'gh' => array( 'lifecycle-profile-canary' ) ) );
		} finally {
			foreach ( array( $path, $path . '.lock' ) as $file ) {
				if ( is_file( $file ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup removes only its unique temporary sidecar.
					unlink( $file );
				}
			}
		}
	}

	public function test_identical_material_across_two_source_profiles_deduplicates_by_selected_package_associations(): void {
		$plugin  = $this->package( 'plugin/profile-a.php', 'profile-a', 'profile-a-repository-id', credential_id: 'profile-a-canary' );
		$theme   = $this->package( 'profile-b-theme', 'profile-b-theme', 'profile-b-repository-id', credential_id: 'profile-b-canary' );
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$path    = sys_get_temp_dir() . '/ran-booster-exporter-' . bin2hex( random_bytes( 8 ) ) . '.php';
		$secrets = SecretsFileTestFactory::create( $path, array(), ShippedSecretPolicyCatalog::create() );
		try {
			foreach ( array( 'profile-a-canary', 'profile-b-canary' ) as $profile_id ) {
				$secrets->save_credential(
					'gh',
					$profile_id,
					array(
						'label'         => 'Identical material characterization credential',
						'kind'          => 'classic',
						'configuration' => array(),
					),
					'identical-material-secret-canary'
				);
			}
			$plugins->method( 'all_deployment_plugins' )->willReturn( array( 'plugin/profile-a.php' => $plugin ) );
			$themes->method( 'all_deployment_themes' )->willReturn( array( 'profile-b-theme' => $theme ) );
			$exporter = new ManagedPackageBlueprintExporter( $plugins, $themes, $secrets );

			$profile_a = $exporter->export(
				array( 'gh' => array( 'profile-a-canary' ) ),
				array(
					array(
						'type'       => 'plugin',
						'identifier' => 'plugin/profile-a.php',
					),
				)
			);
			$profile_b = $exporter->export(
				array( 'gh' => array( 'profile-b-canary' ) ),
				array(
					array(
						'type'       => 'theme',
						'identifier' => 'profile-b-theme',
					),
				)
			);
			$both      = $exporter->export( array( 'gh' => array( 'profile-a-canary', 'profile-b-canary' ) ) );

			self::assertCount( 1, $profile_a->credentials );
			self::assertCount( 1, $profile_b->credentials );
			self::assertCount( 1, $both->credentials );

			$profile_arecord = $profile_a->credentials[0]->to_array();
			$profile_brecord = $profile_b->credentials[0]->to_array();
			$both_record     = $both->credentials[0]->to_array();
			self::assertSame(
				array(
					array(
						'type'       => 'plugin',
						'identifier' => 'plugin/profile-a.php',
					),
				),
				$profile_arecord['packages']
			);
			self::assertSame(
				array(
					array(
						'type'       => 'theme',
						'identifier' => 'profile-b-theme',
					),
				),
				$profile_brecord['packages']
			);
			self::assertSame(
				array(
					array(
						'type'       => 'plugin',
						'identifier' => 'plugin/profile-a.php',
					),
					array(
						'type'       => 'theme',
						'identifier' => 'profile-b-theme',
					),
				),
				$both_record['packages']
			);

			unset( $profile_arecord['packages'], $profile_brecord['packages'], $both_record['packages'] );
			self::assertSame( $profile_arecord, $profile_brecord );
			self::assertSame( $profile_arecord, $both_record );
			foreach ( array( $profile_a, $profile_b, $both ) as $blueprint ) {
				self::assertStringNotContainsString( 'profile-a-canary', $blueprint->canonical_json() );
				self::assertStringNotContainsString( 'profile-b-canary', $blueprint->canonical_json() );
			}
		} finally {
			foreach ( array( $path, $path . '.lock' ) as $file ) {
				if ( is_file( $file ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup removes only its unique temporary sidecar.
					unlink( $file );
				}
			}
		}
	}

	public function test_it_exports_only_the_exact_selected_packages(): void {
		$plugin  = $this->package( 'plugin/example.php', 'example', 'plugin-repository-id' );
		$theme   = $this->package( 'example-theme', 'example-theme', 'theme-repository-id' );
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array( 'plugin/example.php' => $plugin ) );
		$themes->method( 'all_deployment_themes' )->willReturn( array( 'example-theme' => $theme ) );

		$blueprint = ( new ManagedPackageBlueprintExporter( $plugins, $themes, new SecretsFile( null, array() ) ) )->export(
			array(),
			array(
				array(
					'type'       => 'theme',
					'identifier' => 'example-theme',
				),
			)
		);

		self::assertSame( array( 'example-theme' ), array_column( $blueprint->packages, 'identifier' ) );
	}

	public function test_it_trims_shared_credential_associations_to_the_selection(): void {
		$plugin  = $this->package( 'plugin/example.php', 'example', 'plugin-repository-id' );
		$theme   = $this->package( 'example-theme', 'example-theme', 'theme-repository-id' );
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$path    = sys_get_temp_dir() . '/ran-booster-exporter-' . bin2hex( random_bytes( 8 ) ) . '.php';
		$secrets = SecretsFileTestFactory::create( $path, array(), ShippedSecretPolicyCatalog::create() );
		try {
			$secrets->save_credential(
				'gh',
				'credential-id-canary',
				array(
					'label'         => 'Shared deployment token',
					'kind'          => 'classic',
					'configuration' => array(),
				),
				'secret-canary'
			);
			$plugins->method( 'all_deployment_plugins' )->willReturn( array( 'plugin/example.php' => $plugin ) );
			$themes->method( 'all_deployment_themes' )->willReturn( array( 'example-theme' => $theme ) );

			$blueprint = ( new ManagedPackageBlueprintExporter( $plugins, $themes, $secrets ) )->export(
				array( 'gh' => array( 'credential-id-canary' ) ),
				array(
					array(
						'type'       => 'plugin',
						'identifier' => 'plugin/example.php',
					),
				)
			);

			self::assertCount( 1, $blueprint->credentials );
			self::assertSame(
				array(
					array(
						'type'       => 'plugin',
						'identifier' => 'plugin/example.php',
					),
				),
				$blueprint->credentials[0]->packages
			);
		} finally {
			foreach ( array( $path, $path . '.lock' ) as $file ) {
				if ( is_file( $file ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup removes only its unique temporary sidecar.
					unlink( $file );
				}
			}
		}
	}

	/** @param list<array{type:string,identifier:string}> $selection */
	#[DataProvider( 'invalid_selections' )]
	public function test_it_rejects_invalid_or_stale_selections( array $selection ): void {
		$plugin  = $this->package( 'plugin/example.php', 'example', 'plugin-repository-id' );
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array( 'plugin/example.php' => $plugin ) );
		$themes->method( 'all_deployment_themes' )->willReturn( array() );

		$this->expectException( InvalidArgumentException::class );
		( new ManagedPackageBlueprintExporter( $plugins, $themes, new SecretsFile( null, array() ) ) )->export( array(), $selection );
	}

	/** @return iterable<string, array{list<array{type:string,identifier:string}>}> */
	public static function invalid_selections(): iterable {
		yield 'empty' => array( array() );
		yield 'duplicate' => array(
			array(
				array(
					'type'       => 'plugin',
					'identifier' => 'plugin/example.php',
				),
				array(
					'type'       => 'plugin',
					'identifier' => 'plugin/example.php',
				),
			),
		);
		yield 'unknown' => array(
			array(
				array(
					'type'       => 'plugin',
					'identifier' => 'unknown/unknown.php',
				),
			),
		);
		yield 'wrong type' => array(
			array(
				array(
					'type'       => 'theme',
					'identifier' => 'plugin/example.php',
				),
			),
		);
	}

	/** @param array<string, list<mixed>> $selection */
	#[DataProvider( 'invalid_credential_selections' )]
	public function test_it_rejects_invalid_stale_or_unrelated_credential_selections( array $selection ): void {
		$plugin  = $this->package( 'plugin/example.php', 'example', 'plugin-repository-id' );
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array( 'plugin/example.php' => $plugin ) );
		$themes->method( 'all_deployment_themes' )->willReturn( array() );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'The managed package credential selection is invalid.' );
		( new ManagedPackageBlueprintExporter( $plugins, $themes, new SecretsFile( null, array() ) ) )->export( $selection );
	}

	/** @return iterable<string, array{array<string, list<mixed>>}> */
	public static function invalid_credential_selections(): iterable {
		yield 'integer profile' => array( array( 'gh' => array( 123 ) ) );
		yield 'boolean profile' => array( array( 'gh' => array( true ) ) );
		yield 'null profile' => array( array( 'gh' => array( null ) ) );
		yield 'array profile' => array( array( 'gh' => array( array() ) ) );
		yield 'object profile' => array( array( 'gh' => array( new \stdClass() ) ) );
		yield 'unknown profile' => array( array( 'gh' => array( 'unknown-profile' ) ) );
		yield 'wrong provider' => array( array( 'bb' => array( 'credential-id-canary' ) ) );
		yield 'constant' => array( array( 'gh' => array( SecretsFile::CONSTANT_PROFILE ) ) );
		yield 'duplicate' => array( array( 'gh' => array( 'credential-id-canary', 'credential-id-canary' ) ) );
		yield 'invalid provider' => array( array( 'GitHub' => array( 'credential-id-canary' ) ) );
		yield 'invalid profile' => array( array( 'gh' => array( 'invalid profile' ) ) );
	}

	private function package(
		string $identifier,
		string $slug,
		string $provider_repository_id,
		bool $is_private = true,
		string $credential_id = 'credential-id-canary',
		PackageSource $source = PackageSource::BRANCH
	): Package {
		$package = $this->createStub( Package::class );
		$package->method( 'get_identifier' )->willReturn( $identifier );
		$package->method( 'get_display_name' )->willReturn( 'example-theme' === $identifier ? 'Example Theme' : 'Plugin Example' );
		$package->method( 'get_slug' )->willReturn( $slug );
		$package->method( 'get_provider_code' )->willReturn( 'gh' );
		$package->method( 'get_provider_repository_id' )->willReturn( $provider_repository_id );
		$package->method( 'get_repository' )->willReturn( new ManagedRepository( 'gh', 'owner/repository', $provider_repository_id, 'main', $is_private, $credential_id ) );
		$package->method( 'get_branch' )->willReturn( 'main' );
		$package->method( 'is_private' )->willReturn( $is_private );
		$package->method( 'get_subdirectory' )->willReturn( null );
		$package->method( 'get_credential_id' )->willReturn( $credential_id );
		$package->method( 'get_source' )->willReturn( $source );
		$package->method( 'get_source_revision' )->willReturn( 1 );

		return $package;
	}
}
