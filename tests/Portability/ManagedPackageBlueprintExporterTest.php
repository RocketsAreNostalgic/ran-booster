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

	public function testItUsesOnlyTheNonCleaningManagedPackageReaders(): void {
		$plugin  = $this->package( 'plugin/example.php', 'example', 'plugin-repository-id' );
		$theme   = $this->package( 'example-theme', 'example-theme', 'theme-repository-id' );
		$plugins = $this->createMock( PluginRepository::class );
		$themes  = $this->createMock( ThemeRepository::class );

		$plugins->expects( self::once() )->method( 'allDeploymentPlugins' )->willReturn( array( 'plugin/example.php' => $plugin ) );
		$themes->expects( self::once() )->method( 'allDeploymentThemes' )->willReturn( array( 'example-theme' => $theme ) );

		$blueprint = ( new ManagedPackageBlueprintExporter( $plugins, $themes, new SecretsFile( null, array() ) ) )->export();

		self::assertSame( array( 'plugin', 'theme' ), array_column( $blueprint->packages, 'type' ) );
		self::assertSame( array( 'plugin/example.php', 'example-theme' ), array_column( $blueprint->packages, 'identifier' ) );
		self::assertSame( array( 'Plugin Example', 'Example Theme' ), array_column( $blueprint->packages, 'displayName' ) );
		self::assertStringNotContainsString( 'credential-id-canary', $blueprint->canonicalJson() );
	}

	public function testPackageOnlyExportRemainsAvailableWhenEncryptedSecretsRuntimeIsUnavailable(): void {
		$plugin  = $this->package( 'plugin/example.php', 'example', 'plugin-repository-id' );
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn( array( 'plugin/example.php' => $plugin ) );
		$themes->method( 'allDeploymentThemes' )->willReturn( array() );
		$secrets = new SecretsFile(
			constants: array(),
			providerPolicies: new ProviderSecretPolicyCatalog(),
			availability: new SecretsRuntimeAvailability( false, false )
		);

		$blueprint = ( new ManagedPackageBlueprintExporter( $plugins, $themes, $secrets ) )->export( false );

		self::assertSame( array( 'plugin/example.php' ), array_column( $blueprint->packages, 'identifier' ) );
		self::assertSame( array(), $blueprint->credentials );
	}

	public function testLifecycleSafeStateBlocksExportThroughThePackageStorageReader(): void {
		$lifecycle = $this->createStub( Database::class );
		$lifecycle->method( 'requireReady' )->willThrowException( new DatabaseLifecycleFailure( 'schema_operation_failed' ) );
		$exporter = new ManagedPackageBlueprintExporter(
			new PluginRepository( $lifecycle ),
			new ThemeRepository( $lifecycle ),
			new SecretsFile( null, array() )
		);

		try {
			$exporter->export();
			self::fail( 'Portability must not bypass the package-storage safe state.' );
		} catch ( PackageStorageFailure $failure ) {
			self::assertSame( 'ran_booster_storage_database_unsupported', $failure->getDiagnosticId() );
		}
	}

	public function testBlueprintV1RejectsReleaseManagedPackagesInsteadOfConvertingThemToBranch(): void {
		$plugin  = $this->package( 'plugin/example.php', 'example', 'plugin-repository-id', source: PackageSource::RELEASE_ASSET );
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn( array( 'plugin/example.php' => $plugin ) );
		$themes->method( 'allDeploymentThemes' )->willReturn( array() );

		try {
			( new ManagedPackageBlueprintExporter( $plugins, $themes, new SecretsFile( null, array() ) ) )->export();
			self::fail( 'Release-managed packages must not be converted to branch packages.' );
		} catch ( UnsupportedBlueprintPackages $failure ) {
			self::assertSame( 'The selected packages are unavailable to the current Blueprint format.', $failure->getMessage() );
			self::assertSame( 'Plugin Example', $failure->failures[0]->displayName );
		}
	}

	public function testBlueprintV1ReportsEverySelectedUnsupportedPackageBeforeRejectingTheExport(): void {
		$plugin  = $this->package( 'plugin/example.php', 'example', 'plugin-repository-id', source: PackageSource::RELEASE_ASSET );
		$theme   = $this->package( 'example-theme', 'example-theme', 'theme-repository-id', source: PackageSource::RELEASE_ASSET );
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn( array( 'plugin/example.php' => $plugin ) );
		$themes->method( 'allDeploymentThemes' )->willReturn( array( 'example-theme' => $theme ) );

		try {
			( new ManagedPackageBlueprintExporter( $plugins, $themes, new SecretsFile( null, array() ) ) )->export();
			self::fail( 'Every selected unsupported package must block an all-or-nothing Blueprint export.' );
		} catch ( UnsupportedBlueprintPackages $failure ) {
			self::assertSame( array( 'Plugin Example', 'Example Theme' ), array_column( $failure->failures, 'displayName' ) );
		}
	}

	public function testCredentialBearingExportFailsWithTypedLocalStoreCategory(): void {
		$plugin  = $this->package( 'plugin/example.php', 'example', 'plugin-repository-id' );
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn( array( 'plugin/example.php' => $plugin ) );
		$themes->method( 'allDeploymentThemes' )->willReturn( array() );
		$secrets = new SecretsFile(
			constants: array(),
			providerPolicies: new ProviderSecretPolicyCatalog(),
			availability: new SecretsRuntimeAvailability( false, false )
		);

		try {
			( new ManagedPackageBlueprintExporter( $plugins, $themes, $secrets ) )->export( true );
			self::fail( 'A credential-bearing export must not silently omit an unavailable managed credential.' );
		} catch ( LocalSecretStoreUnavailable $failure ) {
			self::assertSame( 'local_secret_store_unavailable', $failure::CATEGORY );
		}
	}

	public function testExplicitCredentialFlagDoesNotInspectStorageForPackagesWithoutCredentials(): void {
		$plugin  = $this->package( 'plugin/example.php', 'example', 'plugin-repository-id', credentialId: '' );
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn( array( 'plugin/example.php' => $plugin ) );
		$themes->method( 'allDeploymentThemes' )->willReturn( array() );
		$secrets = new SecretsFile(
			constants: array(),
			providerPolicies: new ProviderSecretPolicyCatalog(),
			availability: new SecretsRuntimeAvailability( false, false )
		);

		$blueprint = ( new ManagedPackageBlueprintExporter( $plugins, $themes, $secrets ) )->export( true );

		self::assertCount( 1, $blueprint->packages );
		self::assertSame( array(), $blueprint->credentials );
	}

	public function testItExportsOneFileCredentialOnlyWhenExplicitlyRequested(): void {
		$plugin  = $this->package( 'plugin/example.php', 'example', 'plugin-repository-id', false );
		$theme   = $this->package( 'example-theme', 'example-theme', 'theme-repository-id' );
		$plugins = $this->createMock( PluginRepository::class );
		$themes  = $this->createMock( ThemeRepository::class );
		$path    = sys_get_temp_dir() . '/ran-booster-exporter-' . bin2hex( random_bytes( 8 ) ) . '.php';
		$secrets = SecretsFileTestFactory::create( $path, array(), ShippedSecretPolicyCatalog::create() );
		try {
			$secrets->saveCredential(
				'gh',
				'credential-id-canary',
				array(
					'label'         => 'Shared deployment token',
					'kind'          => 'classic',
					'configuration' => array(),
				),
				'secret-canary'
			);

			$plugins->expects( self::once() )->method( 'allDeploymentPlugins' )->willReturn( array( 'plugin/example.php' => $plugin ) );
			$themes->expects( self::once() )->method( 'allDeploymentThemes' )->willReturn( array( 'example-theme' => $theme ) );

			$blueprint = ( new ManagedPackageBlueprintExporter( $plugins, $themes, $secrets ) )->export( true );

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
				$blueprint->credentials[0]->toArray()['packages']
			);
			self::assertStringNotContainsString( 'credential-id-canary', $blueprint->canonicalJson() );
		} finally {
			foreach ( array( $path, $path . '.lock' ) as $file ) {
				if ( is_file( $file ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup removes only its unique temporary sidecar.
					unlink( $file );
				}
			}
		}
	}

	public function testItExportsOnlyTheExactSelectedPackages(): void {
		$plugin  = $this->package( 'plugin/example.php', 'example', 'plugin-repository-id' );
		$theme   = $this->package( 'example-theme', 'example-theme', 'theme-repository-id' );
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn( array( 'plugin/example.php' => $plugin ) );
		$themes->method( 'allDeploymentThemes' )->willReturn( array( 'example-theme' => $theme ) );

		$blueprint = ( new ManagedPackageBlueprintExporter( $plugins, $themes, new SecretsFile( null, array() ) ) )->export(
			false,
			array(
				array(
					'type'       => 'theme',
					'identifier' => 'example-theme',
				),
			)
		);

		self::assertSame( array( 'example-theme' ), array_column( $blueprint->packages, 'identifier' ) );
	}

	public function testItTrimsSharedCredentialAssociationsToTheSelection(): void {
		$plugin  = $this->package( 'plugin/example.php', 'example', 'plugin-repository-id' );
		$theme   = $this->package( 'example-theme', 'example-theme', 'theme-repository-id' );
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$path    = sys_get_temp_dir() . '/ran-booster-exporter-' . bin2hex( random_bytes( 8 ) ) . '.php';
		$secrets = SecretsFileTestFactory::create( $path, array(), ShippedSecretPolicyCatalog::create() );
		try {
			$secrets->saveCredential(
				'gh',
				'credential-id-canary',
				array(
					'label'         => 'Shared deployment token',
					'kind'          => 'classic',
					'configuration' => array(),
				),
				'secret-canary'
			);
			$plugins->method( 'allDeploymentPlugins' )->willReturn( array( 'plugin/example.php' => $plugin ) );
			$themes->method( 'allDeploymentThemes' )->willReturn( array( 'example-theme' => $theme ) );

			$blueprint = ( new ManagedPackageBlueprintExporter( $plugins, $themes, $secrets ) )->export(
				true,
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
	#[DataProvider( 'invalidSelections' )]
	public function testItRejectsInvalidOrStaleSelections( array $selection ): void {
		$plugin  = $this->package( 'plugin/example.php', 'example', 'plugin-repository-id' );
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn( array( 'plugin/example.php' => $plugin ) );
		$themes->method( 'allDeploymentThemes' )->willReturn( array() );

		$this->expectException( InvalidArgumentException::class );
		( new ManagedPackageBlueprintExporter( $plugins, $themes, new SecretsFile( null, array() ) ) )->export( false, $selection );
	}

	/** @return iterable<string, array{list<array{type:string,identifier:string}>}> */
	public static function invalidSelections(): iterable {
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

	private function package(
		string $identifier,
		string $slug,
		string $providerRepositoryId,
		bool $private = true,
		string $credentialId = 'credential-id-canary',
		PackageSource $source = PackageSource::BRANCH
	): Package {
		$package = $this->createStub( Package::class );
		$package->method( 'getIdentifier' )->willReturn( $identifier );
		$package->method( 'getDisplayName' )->willReturn( 'example-theme' === $identifier ? 'Example Theme' : 'Plugin Example' );
		$package->method( 'getSlug' )->willReturn( $slug );
		$package->method( 'getProviderCode' )->willReturn( 'gh' );
		$package->method( 'getProviderRepositoryId' )->willReturn( $providerRepositoryId );
		$package->method( 'getRepository' )->willReturn( new ManagedRepository( 'gh', 'owner/repository', $providerRepositoryId, 'main', $private, $credentialId ) );
		$package->method( 'getBranch' )->willReturn( 'main' );
		$package->method( 'isPrivate' )->willReturn( $private );
		$package->method( 'getSubdirectory' )->willReturn( null );
		$package->method( 'getCredentialId' )->willReturn( $credentialId );
		$package->method( 'getSource' )->willReturn( $source );
		$package->method( 'getSourceRevision' )->willReturn( 1 );

		return $package;
	}
}
