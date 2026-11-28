<?php

declare(strict_types=1);

namespace RAN\Tests\Deployment;

require_once __DIR__ . '/AdmittedBranchHostAdapterWordPressFunctions.php';

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\AdmittedBranchHostAdapter;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;
use RAN\Deployment\ReleaseArtifactCustodian;
use RAN\ManagedRepository;
use RAN\PackageSource;
use RAN\Plugin;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryReleaseArtifactCustody;
use RAN\Storage\Database;
use RAN\Storage\PluginRepository;
use RAN\Storage\RepositorySourceGuard;
use RAN\Storage\ThemeRepository;
use RAN\Tests\Support\RepositorySourceGuardDatabase;
use RAN\WordPress\WordPressUpdaterLock;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RAN\WPBranchUpdater\V1\WordPress\WordPressCorePackageExecutor;
use ReflectionClass;
use RuntimeException;

/** Theme roots, exact managed identity and archive temp paths (#1188). */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class AdmittedBranchPathsTest extends TestCase {
	private string $base;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		require_once __DIR__ . '/AdmittedBranchPathsWordPressFunctions.php';
		$this->base = sys_get_temp_dir() . '/ran-booster-admitted-paths-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable default and secondary theme roots.
		mkdir( $this->base . '/secondary/registered', 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable default and secondary theme roots.
		mkdir( $this->base . '/themes', 0700 );
		$GLOBALS['ran_booster_paths_theme_roots'] = array(
			''           => $this->base . '/themes',
			'registered' => $this->base . '/secondary',
		);
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		foreach ( array( '/secondary/registered', '/secondary', '/themes', '' ) as $directory ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only the disposable test-owned directories.
			rmdir( $this->base . $directory );
		}
	}

	public function test_theme_destination_and_collision_use_the_theme_own_registered_root(): void {
		$adapter = ( new ReflectionClass( AdmittedBranchHostAdapter::class ) )->newInstanceWithoutConstructor();
		$root    = static fn ( string $operation, ?string $installed ): string => ( new \ReflectionMethod( $adapter, 'destination_root' ) )->invoke(
			$adapter,
			new BranchDeploymentDeclaration( '1', 'theme', 'registered', 'owner/registered', 'R_registered', 'main', null, $operation, null, $installed )
		);
		$exists  = static fn ( string $slug ): bool => ( new \ReflectionMethod( $adapter, 'destination_exists' ) )->invoke( $adapter, 'theme', $slug );

		self::assertSame( $this->base . '/secondary', $root( 'update', 'registered' ) );
		self::assertSame( $this->base . '/secondary', $root( 'install', null ) );
		self::assertTrue( $exists( 'registered' ), 'An install must not overwrite a theme in a secondary root.' );
		self::assertFalse( $exists( 'absent' ) );
	}

	public function test_a_managed_plugin_in_a_shared_folder_is_resolved_by_its_exact_identifier(): void {
		$database = new AttemptRepositoryDatabase();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- This isolated process assigns the WordPress database double.
		$GLOBALS['wpdb']                             = $database;
		$GLOBALS['ran_booster_storage_test_options'] = array();
		$token                                       = 'bb:R_shared:11111111-1111-4111-8111-111111111111';
		$plugins                                     = new PathsPluginRepository();
		foreach ( array( 'shared/one.php', 'shared/two.php' ) as $identifier ) {
			$plugins->managed[ $identifier ] = $this->plugin( $identifier, $token );
		}
		$database->management['shared/two.php']      = $token;
		$database->management_rows['shared/two.php'] = array(
			'provider'               => 'bb',
			'provider_repository_id' => 'R_shared',
			'repository'             => 'owner/shared',
			'branch'                 => 'main',
			'private'                => 0,
			'credential_id'          => null,
			'subdirectory'           => null,
			'deployment_policy'      => DeploymentPolicy::AUTOMATIC->value,
			'source'                 => 'branch',
			'source_revision'        => 1,
		);
		$attempts                                    = new DeploymentAttemptRepository(
			$database,
			'wp_ran_booster_deployment_attempts',
			static fn (): DateTimeImmutable => new DateTimeImmutable( '2026-11-28 00:00:00 UTC' ),
			static fn ( int $length ): string => str_repeat( 'a', $length ),
			$this->createStub( Database::class )
		);
		$attempt                                     = $attempts->admit_and_claim_manual(
			'update',
			'plugin',
			'bb',
			'R_shared',
			new DeploymentRequest( 'owner/shared', null, false, 'main', 'shared', null, DeploymentPolicy::AUTOMATIC, 7, 1048576, 'shared/two.php', $token ),
			'main',
			PackageSource::BRANCH->value,
			1
		);
		$adapter                                     = new AdmittedBranchHostAdapter(
			$attempt,
			$attempts,
			$plugins,
			new PathsThemeRepository(),
			new ProviderRegistry(),
			new RepositorySourceGuard( new RepositorySourceGuardDatabase(), $this->createStub( Database::class ) ),
			new WordPressUpdaterLock(),
			( new ReflectionClass( WordPressCorePackageExecutor::class ) )->newInstanceWithoutConstructor(),
			$this->base . '/maintenance'
		);

		$declaration = $adapter->declaration();
		self::assertSame( 'shared/two.php', $adapter->frozen_target( $declaration, false )['identifier'] ?? null );
		$adapter->recheck_managed( $declaration );
	}

	public function test_release_custody_copies_under_the_word_press_temp_directory_without_a_doubled_separator(): void {
		define( 'RAN_BOOSTER_MAX_DOWNLOAD_BYTES', 1048576 );
		$GLOBALS['ran_booster_admitted_temp_root'] = $this->base . '/themes/';
		$source                                    = $this->base . '/secondary/registered/release.zip';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable provider custody bytes.
		file_put_contents( $source, 'release bytes' );
		$custody = new class( $source ) implements RepositoryReleaseArtifactCustody {
			public function __construct( private string $source ) {}
			public function inspect( callable $inspection ): mixed {
				return $inspection( $this->source );
			}
			public function discard(): bool {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- The provider discards its own disposable custody file.
				return unlink( $this->source );
			}
			public function resolved_ref(): string {
				return str_repeat( 'a', 40 );
			}
			public function version(): string {
				return '1.0.0';
			}
			public function size(): int {
				return strlen( 'release bytes' );
			}
			public function sha256(): string {
				return hash( 'sha256', 'release bytes' );
			}
		};

		$prepared = ReleaseArtifactCustodian::claim( $custody );
		try {
			self::assertMatchesRegularExpression( '#\A' . preg_quote( $this->base, '#' ) . '/themes/ran-booster-release-[0-9a-f]{32}/archive\.zip\z#D', $prepared->get_path() );
		} finally {
			$prepared->cleanup();
		}
	}

	private function plugin( string $identifier, string $token ): Plugin {
		$plugin = Plugin::from_wp_array(
			$identifier,
			array(
				'Name'        => 'Shared',
				'PluginURI'   => '',
				'Version'     => '1.0.0',
				'Description' => '',
				'Author'      => '',
				'AuthorURI'   => '',
				'TextDomain'  => '',
				'DomainPath'  => '',
				'Network'     => false,
				'Title'       => 'Shared',
				'AuthorName'  => '',
			)
		);
		$plugin->set_adoption_token( $token );
		$plugin->set_repository( new ManagedRepository( 'bb', 'owner/shared', 'R_shared', 'main' ) );
		$plugin->set_deployment_policy( DeploymentPolicy::AUTOMATIC );
		$plugin->set_source( PackageSource::BRANCH, 1 );
		return $plugin;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This lane-local collaborator stays beside the only test that uses it.
final class PathsPluginRepository extends PluginRepository {
	/** @var array<string, Plugin> */
	public array $managed = array();

	public function __construct() {}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- Every managed plugin shares one folder; the source filter does not apply.
	public function all_deployment_plugins( ?PackageSource $source = null ): array {
		return $this->managed;
	}

	public function booster_plugin_from_file( $file ) {
		return $this->managed[ (string) $file ] ?? throw new RuntimeException( 'Missing managed plugin.' );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This lane-local collaborator stays beside the only test that uses it.
final class PathsThemeRepository extends ThemeRepository {
	public function __construct() {}
}
