<?php

declare(strict_types=1);

namespace RAN\Tests\Deployment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RAN\Admin\BulkPackageAction;
use RAN\Admin\BulkPackageActionService;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentCheckFailure;
use RAN\Deployment\DeploymentCoordinator;
use RAN\Deployment\DeploymentOutcome;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;
use RAN\Deployment\DeploymentState;
use RAN\Deployment\WordPressWorkerWakeup;
use RAN\ManagedRepository;
use RAN\PackageSource;
use RAN\Package;
use RAN\PackageOperation;
use RAN\PackageOperationService;
use RAN\PackageRemoval\PackageRemovalService;
use RAN\Plugin;
use RAN\RepositoryProvider\PreparedArchive;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\PushEvent;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\Secrets\SecretsFile;
use RAN\Storage\Database;
use RAN\Storage\PackageMutationResult;
use RAN\Storage\PackageStorageOperation;
use RAN\Storage\PluginRepository;
use RAN\Storage\RepositorySourceGuard;
use RAN\Storage\ThemeRepository;
use RAN\Tests\RepositoryProvider\Support\InertWebhookPolicy;
use RAN\Tests\Support\RepositorySourceGuardDatabase;
use RAN\Theme;
use RAN\WordPress\WordPressUpdaterLock;
use RAN\WPBranchUpdater\V1\Contract\PreparedPackageArtifact;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionResult;
use RAN\WPBranchUpdater\V1\WordPress\WordPressCorePackageExecutor;
use ReflectionClass;
use ZipArchive;

/** Real admission, archive acquisition and branch execution; WordPress mutation is observed at its executor seam. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class InstalledPackageSlugRoutesTest extends TestCase {
	use \RAN\Tests\Support\QueuedSelectionFixture;

	private AttemptRepositoryDatabase $database;
	private DeploymentAttemptRepository $attempts;
	/** @var PluginRepository&\PHPUnit\Framework\MockObject\MockObject */
	private PluginRepository $plugins;
	/** @var ThemeRepository&\PHPUnit\Framework\MockObject\MockObject */
	private ThemeRepository $themes;
	private ProviderRegistry $providers;
	/** @var RepositoryProvider&WebhookNormalizer&\PHPUnit\Framework\MockObject\MockObject */
	private RepositoryProvider $provider;
	private \RAN\RepositoryProvider\GitHubProviderSelection $selection;
	private RepositorySourceGuard $source_guard;
	private string $directory;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		require_once __DIR__ . '/AdmittedBranchHostAdapterWordPressFunctions.php';
		require_once __DIR__ . '/DeploymentCoordinatorWordPressFunctions.php';
		require_once __DIR__ . '/WordPressWorkerWakeupWordPressFunctions.php';
		require_once __DIR__ . '/../Support/PackageOperationWordPressFunctions.php';
		require_once __DIR__ . '/PackageMutationGuardWordPressFunctions.php';
		require_once __DIR__ . '/DeploymentWorkerPhpFunctions.php';
		require_once __DIR__ . '/../Storage/StorageTestEnvironment.php';
		require_once __DIR__ . '/../Support/RepositoryAdminWordPressFunctions.php';
		require_once __DIR__ . '/../Activity/ActivityWordPressFunctions.php';
		require_once __DIR__ . '/../Logging/LoggingWordPressFunctions.php';
		$this->directory = sys_get_temp_dir() . '/installed-slug-' . bin2hex( random_bytes( 8 ) );
		foreach ( array( $this->directory, ABSPATH . 'wp-admin/includes', WP_CONTENT_DIR, WP_PLUGIN_DIR, get_theme_root() ) as $path ) {
			if ( ! is_dir( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create only isolated PHPUnit fixture directories.
				self::assertTrue( mkdir( $path, 0700, true ) );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Isolated empty WordPress bootstrap fixture.
		file_put_contents( ABSPATH . 'wp-admin/includes/file.php', "<?php\n" );
		$GLOBALS['ran_booster_admitted_temp_root']      = $this->directory;
		$GLOBALS['ran_booster_storage_test_options']    = array();
		$GLOBALS['ran_booster_admitted_http_responses'] = array( 200 );
		$GLOBALS['ran_booster_worker_doing_cron']       = true;
		$this->database                                 = new AttemptRepositoryDatabase();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated database double supplies the real updater lock.
		$GLOBALS['wpdb'] = $this->database;
		$provider        = $this->createMockForIntersectionOfInterfaces( array( RepositoryProvider::class, WebhookNormalizer::class ) );
		self::assertInstanceOf( RepositoryProvider::class, $provider );
		self::assertInstanceOf( WebhookNormalizer::class, $provider );
		$provider->method( 'get_metadata' )->willReturn( new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' ) );
		$provider->method( 'get_webhook_policy' )->willReturn( new InertWebhookPolicy( ProviderCode::parse( 'gh' ), array( 'x-fixture-signature' ) ) );
		$provider->method( 'normalize_webhook' )->willReturn( \RAN\RepositoryProvider\WebhookEnvelope::events( new PushEvent( ProviderCode::parse( 'gh' ), 'owner/example', 'R_example', 'main', str_repeat( 'a', 40 ), 'installed-slug-delivery' ) ) );
		$this->providers    = new ProviderRegistry( array( $provider ) );
		$this->provider     = $provider;
		$this->selection    = $this->queued_selection( $this->database, $this->providers );
		$this->attempts     = new DeploymentAttemptRepository(
			$this->database,
			'wp_ran_booster_deployment_attempts',
			database_lifecycle: $this->createStub( Database::class ),
			selection: $this->selection
		);
		$this->plugins      = $this->createMock( PluginRepository::class );
		$this->themes       = $this->createMock( ThemeRepository::class );
		$this->source_guard = new RepositorySourceGuard( new RepositorySourceGuardDatabase(), $this->createStub( Database::class ) );
		WordPressWorkerWakeupCron::reset();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		$this->remove_tree( $this->directory );
	}

	/** @return iterable<string, array{string, string}> */
	public static function manual_update_refusals(): iterable {
		yield 'stale form' => array( 'stale', DeploymentOutcome::CODE_DEPLOYMENT_SNAPSHOT_CHANGED );
		yield 'package disabled for deployments' => array( 'disabled', DeploymentOutcome::CODE_POLICY_BLOCKED );
		yield 'release-managed package' => array( 'release', DeploymentOutcome::CODE_DEPLOYMENT_RELEASE_SOURCE_BLOCKED );
	}

	#[DataProvider( 'manual_update_refusals' )]
	public function test_a_manual_update_refused_before_admission_carries_its_closed_code( string $scenario, string $code ): void {
		$package = $this->package( 'plugin', 'mypackage', 'packages/MyPackage' );
		$this->inventory( 'plugin', $package );
		$expected = array();
		if ( 'disabled' === $scenario ) {
			$package->set_deployment_policy( DeploymentPolicy::DISABLED );
			$expected = array( 'expected_deployment_policy' => 'disabled' );
		} elseif ( 'release' === $scenario ) {
			$package->set_source( PackageSource::RELEASE_ASSET, 1 );
			$expected = array( 'expected_source' => 'release_asset' );
		}
		$command = $this->update_command( 'plugin', $package, $expected );
		if ( 'stale' === $scenario ) {
			$package->set_deployment_policy( DeploymentPolicy::MANUAL );
		}

		try {
			$this->coordinator( $this->createMock( WordPressCorePackageExecutor::class ) )->execute_manual( $command );
			self::fail( $scenario );
		} catch ( DeploymentCheckFailure $failure ) {
			self::assertSame( $code, $failure->outcome_code, $scenario );
		}
		self::assertSame( array(), $this->database->rows, 'Nothing is admitted or recorded.' );
	}

	/** @return iterable<string, array{string, string, string, string}> */
	public static function update_routes(): iterable {
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			foreach ( array( 'packages/MyPackage', 'packages/renamed-source' ) as $subdirectory ) {
				foreach ( array( 'manual', 'bulk', 'webhook' ) as $route ) {
					foreach ( array( 'mypackage', 'MyPackage' ) as $slug ) {
						yield "$type $subdirectory $route $slug" => array( $type, $subdirectory, $route, $slug );
					}
				}
			}
		}
	}

	#[DataProvider( 'update_routes' )]
	public function test_installed_folder_survives_every_update_route( string $type, string $subdirectory, string $route, string $slug ): void {
		$package    = $this->package( $type, $slug, $subdirectory );
		$identifier = (string) $package->get_identifier();
		$this->inventory( $type, $package );
		$this->database->management[ $identifier ]      = (string) $package->get_adoption_token();
		$this->database->management_rows[ $identifier ] = array(
			'provider'               => 'gh',
			'provider_repository_id' => 'R_example',
			'repository'             => 'owner/example',
			'branch'                 => 'main',
			'private'                => 0,
			'credential_id'          => null,
			'subdirectory'           => $subdirectory,
			'deployment_policy'      => 'automatic',
			'source'                 => 'branch',
			'source_revision'        => 1,
		);
		$this->archive( $type, $subdirectory );
		$details  = array();
		$executor = $this->createMock( WordPressCorePackageExecutor::class );
		$executor->expects( self::once() )->method( 'update_' . $type )->with(
			self::isInstanceOf( PreparedPackageArtifact::class ),
			$slug,
			$subdirectory,
			$identifier
		)->willReturnCallback(
			static function ( PreparedPackageArtifact $artifact, string $slug, ?string $source, string $installed ) use ( $type, $package, $subdirectory, &$details ): CorePackageExecutionResult {
				self::assertSame( $subdirectory, $source );
				self::assertSame( $package->get_slug(), $slug );
				self::assertSame( $package->get_identifier(), $installed );
				$wrong        = 'plugin' === $type
					? ( new WordPressCorePackageExecutor() )->update_plugin( $artifact, 'wrong-folder', $source, $installed )
					: ( new WordPressCorePackageExecutor() )->update_theme( $artifact, 'wrong-folder', $source, $installed );
				$details[]    = $wrong->get_detail();
				$foreign      = 'plugin' === $type
					? ( new WordPressCorePackageExecutor() )->update_plugin( $artifact, $slug, $source, 'foreign/package.php' )
					: ( new WordPressCorePackageExecutor() )->update_theme( $artifact, $slug, $source, 'foreign' );
				$details[]    = $foreign->get_detail();
				$case_changed = 'plugin' === $type
					? ( new WordPressCorePackageExecutor() )->update_plugin( $artifact, strtoupper( $slug ), $source, $installed )
					: ( new WordPressCorePackageExecutor() )->update_theme( $artifact, strtoupper( $slug ), $source, $installed );
				$details[]    = $case_changed->get_detail();
				( new ReflectionClass( $package ) )->getProperty( 'version' )->setValue( $package, '2.0.0' );
				return CorePackageExecutionResult::succeeded();
			}
		);
		$coordinator = $this->coordinator( $executor );
		if ( 'manual' === $route ) {
			$result = $coordinator->execute_manual( $this->update_command( $type, $package ) );
			self::assertSame( 'succeeded', $result['status'], $result['outcome_code'] );
		} else {
			if ( 'bulk' === $route ) {
				$service = new BulkPackageActionService( $this->plugins, $this->themes, $this->providers, new SecretsFile(), $coordinator, new WordPressUpdaterLock() );
				$result  = $service->execute(
					BulkPackageAction::from_input(
						$type,
						array(
							'bulk_action'     => BulkPackageAction::QUEUE_UPDATE,
							'identifiers'     => array( $identifier ),
							'adoption_tokens' => array( hash( 'sha256', $identifier ) => $package->get_adoption_token() ),
						)
					)
				);
				self::assertSame( 1, $result->queued );
			} else {
				$body    = 'installed folder update';
				$secret  = str_repeat( 's', 32 );
				$secrets = $this->createStub( SecretsFile::class );
				$secrets->method( 'webhook_materials' )->willReturn(
					array(
						'fixture' => array(
							'scope'        => 'repository',
							'target'       => 'owner/example',
							'authority_id' => 'R_example',
							'secret'       => $secret,
						),
					)
				);
				$processor = new \RAN\Webhook\WebhookProcessor( $this->providers, $coordinator, new \RAN\Webhook\SignedWebhookVerifier( $secrets ), $this->selection );
				$response  = $processor->handle(
					'gh',
					static fn (): array => array(
						'body'    => $body,
						'headers' => array( 'x-fixture-signature' => 'sha256=' . hash_hmac( 'sha256', $body, $secret ) ),
					)
				);
				self::assertSame( 202, $response->get_status() );
				self::assertSame( 1, $response->get_data()['accepted_targets'] );
			}
			$attempt = $this->attempts->claim_next();
			self::assertNotNull( $attempt );
			self::assertSame( $slug, $attempt->get_request()->package_slug );
			self::assertSame( $subdirectory, $attempt->get_request()->subdirectory );
			$outcome = $coordinator->execute_claimed( $attempt );
			self::assertSame( DeploymentState::SUCCEEDED, $outcome->get_state(), $outcome->get_code() );
		}
		self::assertSame( $slug, $package->get_slug() );
		self::assertSame( array( 'identifier_mismatch', 'identifier_mismatch', 'identifier_mismatch' ), $details );
		self::assertSame( '2.0.0', $package->get_version() );
		self::assertSame( $identifier, $package->get_identifier() );
		self::assertSame( 'succeeded', $this->database->rows[0]['state'] );
		$request = DeploymentRequest::from_json( (string) $this->database->rows[0]['request_json'] );
		self::assertSame( $slug, $request->package_slug );
		self::assertSame( $subdirectory, $request->subdirectory );
	}

	/** @return iterable<string, array{string, bool, bool}> */
	public static function link_targets(): iterable {
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			foreach ( array( false, true ) as $exact ) {
				foreach ( array( false, true ) as $managed ) {
					yield $type . ( $exact ? ' identifier' : ' slug' ) . ( $managed ? ' repeat' : ' fresh' ) => array( $type, $exact, $managed );
				}
			}
		}
	}

	#[DataProvider( 'link_targets' )]
	public function test_link_only_uses_installed_identity_by_slug_or_exact_identifier( string $type, bool $exact, bool $managed ): void {
		$package = $this->package( $type, 'mypackage', 'packages/MyPackage' );
		$this->inventory( $type, $package );
		$repository = 'plugin' === $type ? $this->plugins : $this->themes;
		$repository->expects( self::once() )->method( $exact ? ( 'plugin' === $type ? 'installed_plugin_from_file' : 'installed_theme_from_stylesheet' ) : 'from_slug' )
			->with( $exact ? $package->get_identifier() : 'mypackage' )->willReturn( $package );
		$repository->expects( self::once() )->method( 'adopt' )->with( $package )->willReturn(
			$managed
				? PackageMutationResult::conflict( PackageStorageOperation::INSERT, 'ran_booster_storage_adoption_conflict', 'Existing package management data was found.' )
				: PackageMutationResult::changed( PackageStorageOperation::INSERT )
		);
		$service               = $this->link_service();
		$input                 = $this->install_input() + array( 'dry-run' => '1' );
		$input['subdirectory'] = $exact ? 'packages/MyPackage' : 'packages/mypackage';
		if ( $exact ) {
			$input['exact_identifier']                           = '1';
			$input[ 'plugin' === $type ? 'file' : 'stylesheet' ] = (string) $package->get_identifier();
		}
		$result = $service->execute( PackageOperation::from_input( 'install-' . $type, $input ) );
		self::assertSame( $managed ? 'already-managed' : 'linked', $result['status'] );
		self::assertArrayHasKey( 'package', $result );
		self::assertSame( $package, $result['package'] ?? null );
		self::assertSame( 'mypackage', $package->get_slug() );
		self::assertSame( $input['subdirectory'], $package->get_subdirectory() );
	}

	/** @return iterable<string, array{string, string}> */
	public static function conflicting_management(): iterable {
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			foreach ( array( 'identifier', 'provider', 'repository-id', 'repository', 'subdirectory' ) as $mismatch ) {
				yield "$type $mismatch" => array( $type, $mismatch );
			}
		}
	}

	#[DataProvider( 'conflicting_management' )]
	public function test_repeat_link_refuses_conflicting_management( string $type, string $mismatch ): void {
		$requested = $this->package( $type, 'mypackage', 'packages/MyPackage' );
		$existing  = $this->package( $type, 'mypackage', 'packages/MyPackage' );
		if ( 'identifier' === $mismatch ) {
			( new ReflectionClass( $existing ) )->getProperty( 'plugin' === $type ? 'file' : 'stylesheet' )->setValue(
				$existing,
				'plugin' === $type ? 'mypackage/foreign.php' : 'foreign-theme'
			);
		} elseif ( 'subdirectory' === $mismatch ) {
			$existing->set_subdirectory( 'packages/foreign-source' );
		} else {
			$existing->set_repository(
				new ManagedRepository(
					'provider' === $mismatch ? 'fixture' : 'gh',
					'repository' === $mismatch ? 'owner/other' : 'owner/example',
					'repository-id' === $mismatch ? 'R_other' : 'R_example',
					'main'
				)
			);
		}
		$this->inventory( $type, $requested, $existing );
		$repository = 'plugin' === $type ? $this->plugins : $this->themes;
		$repository->method( 'plugin' === $type ? 'installed_plugin_from_file' : 'installed_theme_from_stylesheet' )->willReturn( $requested );
		$repository->method( 'adopt' )->willReturn(
			PackageMutationResult::conflict(
				PackageStorageOperation::INSERT,
				'ran_booster_storage_adoption_conflict',
				'Existing package management data was found.'
			)
		);
		$input   = $this->install_input() + array(
			'dry-run'                                  => '1',
			'exact_identifier'                         => '1',
			'plugin' === $type ? 'file' : 'stylesheet' => (string) $requested->get_identifier(),
		);
		$service = $this->link_service();
		$this->expectException( \RAN\Storage\PackageStorageFailure::class );
		$service->execute( PackageOperation::from_input( 'install-' . $type, $input ) );
	}

	private function link_service(): PackageOperationService {
		$coordinator = $this->createMock( DeploymentCoordinator::class );
		$coordinator->expects( self::never() )->method( 'execute_manual' );
		$lock = new WordPressUpdaterLock();
		return new PackageOperationService(
			$this->plugins,
			$this->themes,
			$coordinator,
			new PackageRemovalService(
				$this->plugins,
				$this->themes,
				$this->createStub( \RAN\PackageRemoval\PackageRemovalGateway::class ),
				$this->attempts,
				$lock
			),
			$lock,
			$this->source_guard
		);
	}

	/** @return array<string, array{string}> */
	public static function package_types(): array {
		return array(
			'plugin' => array( 'plugin' ),
			'theme'  => array( 'theme' ),
		);
	}

	#[DataProvider( 'package_types' )]
	public function test_new_subdirectory_install_keeps_lowercase_destination( string $type ): void {
		$command = PackageOperation::from_input( 'install-' . $type, $this->install_input() );
		self::assertSame( 'mypackage', $command->package_slug );
		self::assertSame( 'packages/MyPackage', $command->subdirectory );
		$package = $this->package( $type, 'mypackage', 'packages/MyPackage' );
		$this->inventory( $type, $package );
		$repository = 'plugin' === $type ? $this->plugins : $this->themes;
		$repository->method( 'from_slug' )->willReturn( $package );
		$repository->method( 'adopt' )->willReturn( PackageMutationResult::changed( PackageStorageOperation::INSERT ) );
		$this->archive( $type, 'packages/MyPackage' );
		$executor = $this->createMock( WordPressCorePackageExecutor::class );
		$executor->expects( self::once() )->method( 'install_' . $type )->with( self::isInstanceOf( PreparedPackageArtifact::class ), 'mypackage', 'packages/MyPackage' )->willReturnCallback(
			static function () use ( $package ): CorePackageExecutionResult {
				( new ReflectionClass( $package ) )->getProperty( 'version' )->setValue( $package, '2.0.0' );
				return CorePackageExecutionResult::succeeded();
			}
		);
		$result = $this->coordinator( $executor )->execute_manual( $command );
		self::assertSame( 'succeeded', $result['status'], $result['outcome_code'] );
	}


	private function inventory( string $type, Plugin|Theme $package, Plugin|Theme|null $existing = null ): void {
		( 'plugin' === $type ? $this->plugins : $this->themes )->method( 'from_slug' )->willReturn( $package );
		$this->plugins->method( 'all_deployment_plugins' )->willReturn( 'plugin' === $type ? array( $package ) : array() );
		$this->themes->method( 'all_deployment_themes' )->willReturn( 'theme' === $type ? array( $package ) : array() );
		( 'plugin' === $type ? $this->plugins : $this->themes )->method( 'plugin' === $type ? 'booster_plugin_from_file' : 'booster_theme_from_stylesheet' )->willReturn( $existing ?? $package );
	}

	private function package( string $type, string $slug, string $subdirectory ): Plugin|Theme {
		$reflection = new ReflectionClass( 'plugin' === $type ? Plugin::class : Theme::class );
		$package    = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty( 'plugin' === $type ? 'file' : 'stylesheet' )->setValue( $package, 'plugin' === $type ? $slug . '/package.php' : $slug );
		$reflection->getProperty( 'version' )->setValue( $package, '1.0.0' );
		$package->set_repository( new ManagedRepository( 'gh', 'owner/example', 'R_example', 'main' ) );
		$package->set_subdirectory( $subdirectory );
		$package->set_deployment_policy( DeploymentPolicy::AUTOMATIC );
		$package->set_adoption_token( 'gh:R_example:11111111-1111-4111-8111-111111111111' );
		return $package;
	}

	/** @param array<string, string> $expected Overrides for the submitted snapshot. */
	private function update_command( string $type, Package $package, array $expected = array() ): PackageOperation {
		return PackageOperation::from_input(
			'update-' . $type,
			$expected + array(
				'plugin' === $type ? 'file' : 'stylesheet' => $package->get_identifier(),
				'repository'                               => 'owner/example',
				'expected_provider'                        => 'gh',
				'expected_provider_repository_id'          => 'R_example',
				'expected_repository'                      => 'owner/example',
				'expected_branch'                          => 'main',
				'expected_credential_id'                   => '',
				'expected_subdirectory'                    => $package->get_subdirectory(),
				'expected_private'                         => false,
				'expected_package_slug'                    => $package->get_slug(),
				'expected_deployment_policy'               => 'automatic',
				'expected_source'                          => 'branch',
				'expected_source_revision'                 => '1',
				'expected_adoption_token'                  => \RAN\AdoptionIdentity::of( $package ),
			)
		);
	}

	/** @return array<string, string> */
	private function install_input(): array {
		return array(
			'repository'                          => 'owner/example',
			'provider'                            => 'gh',
			'provider_repository_id'              => 'R_example',
			'provider_repository_identity_source' => 'resolved',
			'branch'                              => 'main',
			'package_slug'                        => 'mypackage',
			'subdirectory'                        => 'packages/MyPackage',
			'deployment_policy'                   => 'automatic',
		);
	}

	private function archive( string $type, string $subdirectory ): void {
		$path = $this->directory . '/source.zip';
		$zip  = new ZipArchive();
		self::assertTrue( $zip->open( $path, ZipArchive::CREATE ) );
		$zip->addFromString( 'repository/' . $subdirectory . ( 'plugin' === $type ? '/package.php' : '/style.css' ), 'plugin' === $type ? "<?php\n/* Plugin Name: Example\nVersion: 2.0.0\n*/\n" : "/* Theme Name: Example\nVersion: 2.0.0\n*/\n" );
		$zip->close();
		$GLOBALS['ran_booster_admitted_download_fixture'] = $path;
		$archive = $this->createStub( PreparedArchive::class );
		$archive->method( 'get_url' )->willReturn( 'https://example.test/source.zip' );
		$archive->method( 'get_resolved_ref' )->willReturn( str_repeat( 'a', 40 ) );
		$this->provider->expects( self::once() )->method( 'prepare_archive' )->willReturn( $archive );
	}

	private function coordinator( WordPressCorePackageExecutor $executor ): DeploymentCoordinator {
		return new DeploymentCoordinator( $this->attempts, $this->plugins, $this->themes, $this->providers, new WordPressWorkerWakeup( $this->attempts ), $this->directory . '/.maintenance', new WordPressUpdaterLock(), source_guard: $this->source_guard, branch_executor: $executor );
	}

	private function remove_tree( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( new \FilesystemIterator( $path ) as $item ) {
			self::assertInstanceOf( \SplFileInfo::class, $item );
			if ( $item->isDir() && ! $item->isLink() ) {
				$this->remove_tree( $item->getPathname() );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this test's private fixtures.
				unlink( $item->getPathname() );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only this test's private fixtures.
		rmdir( $path );
	}
}
