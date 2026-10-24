<?php

declare(strict_types=1);

namespace RAN\Tests\Portability;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\AddOn\Portability\PortabilityCandidate;
use RAN\AddOn\ReleaseTracking\NativeReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\ProspectiveReleaseFacade;
use RAN\AddOn\ReleaseTracking\ProspectiveReleaseResult;
use RAN\Deployment\DeploymentCoordinator;
use RAN\Deployment\DeploymentPolicy;
use RAN\ManagedRepository;
use RAN\PackageSource;
use RAN\Plugin;
use RAN\PackageOperationService;
use RAN\PackageRemoval\PackageRemovalService;
use RAN\Portability\BlueprintPackage;
use RAN\Portability\BlueprintCredential;
use RAN\Portability\BlueprintRepositoryVerifier;
use RAN\Portability\BlueprintReviewer;
use RAN\Portability\PackageBlueprint;
use RAN\Portability\PortabilityApplicationService;
use RAN\Portability\TargetPackageAction;
use RAN\Portability\TargetPackageReason;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryReleaseCandidate;
use RAN\RepositoryProvider\RepositoryReleaseCandidateList;
use RAN\RepositoryProvider\RepositoryReleaseCandidateListing;
use RAN\RepositoryProvider\RepositoryReleaseInspection;
use RAN\RepositoryProvider\RepositoryReleaseInspector;
use RAN\RepositoryProvider\RepositoryReleaseMetadata;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargets;
use RAN\Secrets\SecretsFile;
use RAN\Storage\Database;
use RAN\Storage\PackageStorageFailure;
use RAN\Storage\PluginRepository;
use RAN\Storage\RepositorySourceGuard;
use RAN\Storage\ThemeRepository;
use RAN\Storage\PackageMutationResult;
use RAN\Storage\PackageStorageOperation;
use RAN\Tests\Support\RepositorySourceGuardDatabase;
use RAN\WordPress\ManagedReleaseConfiguration;
use RAN\WordPress\ManagedReleaseStore;
use RAN\WordPress\ManagedReleaseTargetRegistrar;
use RAN\WordPress\WordPressUpdaterLock;
use ReflectionClass;
use RuntimeException;

require_once __DIR__ . '/../Support/PackageOperationGlobalWordPressFunctions.php';
require_once __DIR__ . '/../Support/PackageOperationWordPressFunctions.php';
require_once __DIR__ . '/../Runtime/RuntimeSupportWordPressFunctions.php';
require_once __DIR__ . '/WpPusherCoexistenceWordPressFunctions.php';
require_once __DIR__ . '/../Support/RepositorySourceGuardDatabase.php';

final class PortabilityApplicationServiceTest extends TestCase {
	/** @var array{provider_code:string,selection_generation:int,implementation_fingerprint:string}|null */
	private ?array $provider_expectation = null;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$GLOBALS['ran_booster_wp_pusher_active_plugins']  = array();
		$GLOBALS['ran_booster_wp_pusher_network_plugins'] = array();
	}

	public function test_each_service_preview_owns_one_observation_lifetime(): void {
		$plugins  = $this->createStub( PluginRepository::class );
		$themes   = $this->createStub( ThemeRepository::class );
		$provider = $this->provider();
		$plugins->method( 'is_installed' )->willReturn( false );
		$plugins->method( 'has_management_record' )->willReturn( false );
		$provider->expects( self::exactly( 2 ) )->method( 'resolve_repository' )->willReturn( $this->descriptor() );
		$service  = $this->service( $plugins, $themes, $provider );
		$packages = array();
		for ( $row = 0; $row < 128; ++$row ) {
			$packages[] = $this->package( 'example-' . $row . '/example.php' );
		}
		$blueprint = new PackageBlueprint( $packages );
		foreach ( array( $service->review( $blueprint ), $service->review( $blueprint ) ) as $review ) {
			self::assertCount( 128, $review );
			foreach ( $review as $item ) {
				self::assertSame( \RAN\Portability\TargetPackageAction::INSTALL, $item->action );
			}
		}
	}

	public function test_new_format_fields_cannot_be_ignored_by_branch_application(): void {
		$plugins  = $this->createMock( PluginRepository::class );
		$themes   = $this->createMock( ThemeRepository::class );
		$provider = $this->provider();
		$plugins->expects( self::exactly( 4 ) )->method( 'is_installed' )->willReturn( true );
		$plugins->expects( self::exactly( 4 ) )->method( 'has_management_record' )->willReturn( false );
		$themes->expects( self::never() )->method( 'is_installed' );
		$provider->expects( self::once() )->method( 'resolve_repository' )->willReturn( $this->descriptor() );
		$service = $this->service( $plugins, $themes, $provider );
		foreach ( array(
			new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/repository', '', null, new \RAN\WordPress\ManagedReleaseConfiguration( 'example', 'example.php' ) ),
		) as $package ) {
			$blueprint = new PackageBlueprint( array( $package ) );
			$item      = $service->review( $blueprint )[0];
			self::assertSame( 'none', $item->reason->value );
			foreach ( array( 'install', 'managed', 'blocked' ) as $forged_action ) {
				self::assertSame( 'skipped', $service->apply( $blueprint, 0, $forged_action, array(), null, true, true, null, $this->provider_expectation )['status'] );
			}
		}
	}

	public function test_selected_initial_target_appearing_after_review_never_becomes_adoption(): void {
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->expects( self::exactly( 2 ) )->method( 'is_installed' )->willReturn( false, true );
		$plugins->method( 'has_management_record' )->willReturn( false );
		$provider = $this->provider();
		$provider->expects( self::never() )->method( 'resolve_repository' );
		$service   = $this->service( $plugins, $this->createStub( ThemeRepository::class ), $provider );
		$package   = new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/repository', 'main', null, null, array( 'commit' => str_repeat( 'a', 40 ) ) );
		$blueprint = new PackageBlueprint( array( $package ) );
		$service->review( $blueprint );
		self::assertSame( 'skipped', $service->apply( $blueprint, 0, 'install', array(), null, true, true, null, $this->provider_expectation )['status'] );
	}

	#[DataProvider( 'unsupported_initial_identifiers' )]
	public function test_selected_initial_identity_refuses_before_provider_work( string $identifier ): void {
		$provider = $this->provider();
		$provider->expects( self::never() )->method( 'resolve_repository' );
		$service   = $this->service( $this->createStub( PluginRepository::class ), $this->createStub( ThemeRepository::class ), $provider );
		$package   = new BlueprintPackage( 'plugin', $identifier, 'Example', 'gh', 'repository-id', 'owner/repository', 'main', null, null, array( 'commit' => str_repeat( 'a', 40 ) ) );
		$blueprint = new PackageBlueprint( array( $package ) );
		self::assertSame( 'unsupported_blueprint_operation', $service->review( $blueprint )[0]->reason->value );
		self::assertSame( 'skipped', $service->apply( $blueprint, 0, 'install', array(), null, false, true, null, $this->provider_expectation )['status'] );
	}

	/** @return iterable<string,array{string}> */
	public static function unsupported_initial_identifiers(): iterable {
		yield 'single file' => array( 'example.php' );
		yield 'nested main file' => array( 'example/includes/main.php' );
		yield 'unsupported root' => array( 'Example/main.php' );
		yield 'too long for producer' => array( 'example/' . str_repeat( 'a', 180 ) . '.php' );
	}

	#[DataProvider( 'selected_initial_packages' )]
	public function test_selected_initial_install_uses_exact_domain_intent_or_refuses_unsupported_runtime( string $type, string $provider_code, int $commit_length ): void {
		$plugins  = $this->createStub( PluginRepository::class );
		$themes   = $this->createStub( ThemeRepository::class );
		$provider = $this->createMock( RepositoryProvider::class );
		$provider->method( 'get_metadata' )->willReturn( new ProviderMetadata( ProviderCode::parse( $provider_code ), 'Provider', 'https://provider.example.test/', 'Owner', archive_api_version: 2 ) );
		$capable = defined( \RAN\WPBranchUpdater\V1\Runtime\BranchUpdater::class . '::ADMITTED_API_VERSION' )
			&& 3 === constant( \RAN\WPBranchUpdater\V1\Runtime\BranchUpdater::class . '::ADMITTED_API_VERSION' )
			&& ( 'gh' !== $provider_code || 40 === $commit_length );
		$provider->expects( $capable ? self::exactly( 2 ) : self::never() )->method( 'resolve_repository' )->willReturn(
			new RepositoryDescriptor( ProviderCode::parse( $provider_code ), 'owner/repository', 'example', 'repository-id', false, 'main', null )
		);
		$identifier  = 'plugin' === $type ? 'example/example.php' : 'example';
		$commit      = str_repeat( 'a', $commit_length );
		$coordinator = $this->createMock( DeploymentCoordinator::class );
		$coordinator->expects( $capable ? self::once() : self::never() )->method( 'execute_manual' )->willReturnCallback(
			static function ( \RAN\PackageOperation $operation ) use ( $identifier, $commit ): array {
				self::assertSame( $commit, $operation->selected_commit );
				self::assertSame( $identifier, $operation->expected_identifier );
				self::assertSame( 'main', $operation->branch );
				self::assertSame( 'packages/example', $operation->subdirectory );
				self::assertSame( \RAN\Deployment\DeploymentPolicy::DISABLED, $operation->deployment_policy );
				self::assertNull( $operation->ref );
				return array(
					'status'         => 'failed',
					'correlation_id' => str_repeat( 'b', 32 ),
					'outcome_code'   => \RAN\Deployment\DeploymentOutcome::CODE_PROVIDER_UNAVAILABLE,
				);
			}
		);
		$operations = new PackageOperationService( $plugins, $themes, $coordinator, ( new ReflectionClass( PackageRemovalService::class ) )->newInstanceWithoutConstructor(), $this->createStub( WordPressUpdaterLock::class ), new RepositorySourceGuard( new \stdClass(), $this->createStub( Database::class ) ) );
		$service    = $this->service( $plugins, $themes, $provider, $operations );
		$package    = new BlueprintPackage( $type, $identifier, 'Example', $provider_code, 'repository-id', 'owner/repository', 'main', 'packages/example', null, array( 'commit' => $commit ) );
		$blueprint  = new PackageBlueprint( array( $package ) );
		$review     = $service->review( $blueprint )[0];
		self::assertSame( $capable ? 'install' : 'blocked', $review->action->value );
		$result = $service->apply( $blueprint, 0, 'install', array(), null, false, true, null, $review->provider_expectation ?? $this->provider_expectation );
		self::assertSame( $capable ? 'failed' : 'skipped', $result['status'] );
		self::assertSame( 'unknown', $service->compare_local( $package )['installed_equality'] );
	}

	/** @return iterable<string,array{string,string,int}> */
	public static function selected_initial_packages(): iterable {
		yield 'GitHub plugin' => array( 'plugin', 'gh', 40 );
		yield 'GitHub theme' => array( 'theme', 'gh', 40 );
		yield 'GitHub SHA256 refuses early' => array( 'plugin', 'gh', 64 );
		yield 'neutral SHA256 plugin' => array( 'plugin', 'bb', 64 );
		yield 'neutral theme' => array( 'theme', 'bb', 40 );
	}

	public function test_selected_repository_conflict_refuses_before_any_local_or_provider_probe(): void {
		$plugins  = $this->createMock( PluginRepository::class );
		$themes   = $this->createMock( ThemeRepository::class );
		$provider = $this->provider();
		$plugins->expects( self::never() )->method( 'is_installed' );
		$themes->expects( self::never() )->method( 'is_installed' );
		$provider->expects( self::never() )->method( 'resolve_repository' );
		$branch  = $this->package();
		$release = new BlueprintPackage( 'plugin', 'release/release.php', 'Release', $branch->provider, $branch->provider_repository_id, $branch->repository, '', null, new \RAN\WordPress\ManagedReleaseConfiguration( 'release', 'release.php' ) );
		$service = $this->service( $plugins, $themes, $provider );
		$result  = $service->apply( new PackageBlueprint( array( $branch, $release ) ), 0, 'install', array(), null, true, true, array( 0, 1 ), $this->provider_expectation );
		self::assertSame( 'skipped', $result['status'] );
		self::assertStringContainsString( 'selected packages conflict', $result['message'] );
	}

	public function test_release_preview_verifies_repository_without_claiming_apply_time_readiness(): void {
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->expects( self::once() )->method( 'is_installed' )->with( 'example/example.php' )->willReturn( true );
		$plugins->expects( self::once() )->method( 'has_management_record' )->with( 'example/example.php' )->willReturn( false );
		$provider = $this->provider();
		$provider->expects( self::once() )->method( 'resolve_repository' )->willReturn( $this->descriptor() );
		$release = new \RAN\WordPress\ManagedReleaseConfiguration( 'example', 'example.php' );
		$package = new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/repository', '', null, $release );

		$result = $this->service( $plugins, $this->createStub( ThemeRepository::class ), $provider )->review( new PackageBlueprint( array( $package ) ) )[0];

		self::assertSame( TargetPackageAction::ADOPT, $result->action );
		self::assertSame( TargetPackageReason::NONE, $result->reason );
	}

	public function test_release_install_preview_enriches_the_first_exact_candidate(): void {
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->expects( self::once() )->method( 'is_installed' )->with( 'example/example.php' )->willReturn( false );
		$plugins->expects( self::once() )->method( 'has_management_record' )->with( 'example/example.php' )->willReturn( false );
		$provider = $this->provider();
		$provider->expects( self::once() )->method( 'resolve_repository' )->willReturn( $this->descriptor() );
		$facade = $this->createMock( ProspectiveReleaseFacade::class );
		$facade->expects( self::exactly( 2 ) )->method( 'nonce_action' )->willReturnCallback(
			static function ( string $operation, string $type, ?array $expectation ): string {
				self::assertContains( $operation, array( 'list_candidates', 'inspect' ) );
				self::assertSame( 'plugin', $type );
				self::assertNull( $expectation );
				return 'nonce-' . $operation;
			}
		);
		$facade->expects( self::once() )->method( 'list_candidates' )->willReturnCallback(
			static function ( string $type, array $request, string $channel, string $nonce ): ProspectiveReleaseResult {
				self::assertSame( 'plugin', $type );
				self::assertSame(
					array(
						'provider'               => 'gh',
						'repository'             => 'owner/repository',
						'provider_repository_id' => 'repository-id',
						'credential_id'          => null,
						'private'                => false,
						'deployment_policy'      => 'manual',
					),
					$request
				);
				self::assertSame( 'stable', $channel );
				self::assertSame( wp_create_nonce( 'nonce-list_candidates' ), $nonce );
				return ProspectiveReleaseResult::success(
					'release_candidates_available',
					array(
						'candidates' => array(
							array(
								'release_id' => '42',
								'tag'        => 'v1.2.3',
								'version'    => '1.2.3',
							),
						),
					)
				);
			}
		);
		$facade->expects( self::once() )->method( 'inspect' )->willReturnCallback(
			static function ( string $type, array $request, string $release_id, string $tag, string $channel, string $nonce ): ProspectiveReleaseResult {
				unset( $request );
				self::assertSame( 'plugin', $type );
				self::assertSame( '42', $release_id );
				self::assertSame( 'v1.2.3', $tag );
				self::assertSame( 'stable', $channel );
				self::assertSame( wp_create_nonce( 'nonce-inspect' ), $nonce );
				return ProspectiveReleaseResult::success(
					'release_ready',
					array(
						'release_id'   => '42',
						'tag'          => 'v1.2.3',
						'version'      => '1.2.3',
						'channel'      => 'stable',
						'package_root' => 'example',
						'main_file'    => 'example.php',
						'fingerprint'  => 'v2:' . str_repeat( 'a', 64 ),
					)
				);
			}
		);
		$package = new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/repository', '', null, new ManagedReleaseConfiguration( 'example', 'example.php' ) );

		$result = $this->service( $plugins, $this->createStub( ThemeRepository::class ), $provider, prospective: $facade )->review( new PackageBlueprint( array( $package ) ) )[0];

		self::assertSame( TargetPackageAction::INSTALL, $result->action );
		self::assertSame( TargetPackageReason::NONE, $result->reason );
		self::assertSame(
			array(
				'release_id'   => '42',
				'tag'          => 'v1.2.3',
				'version'      => '1.2.3',
				'channel'      => 'stable',
				'fingerprint'  => 'v2:' . str_repeat( 'a', 64 ),
				'package_root' => 'example',
				'main_file'    => 'example.php',
			),
			$result->release_review
		);
	}

	public function test_release_install_preview_refuses_selection_changed_during_inspection(): void {
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'is_installed' )->willReturn( false );
		$plugins->method( 'has_management_record' )->willReturn( false );
		$provider = $this->provider();
		$provider->method( 'resolve_repository' )->willReturn( $this->descriptor() );
		$catalog   = new ProviderSecretPolicyCatalog();
		$secrets   = new SecretsFile( null, array(), $catalog );
		$registry  = new ProviderRegistry( array( $provider ), $catalog );
		$selection = GitHubSelectionTestFixture::bind( $registry );
		$store     = ( new \ReflectionProperty( $selection, 'store' ) )->getValue( $selection );
		$database  = ( new \ReflectionProperty( $store, 'connection' ) )->getValue( $store );
		$state     = new \ReflectionProperty( $selection, 'state' );
		$facade    = $this->createMock( ProspectiveReleaseFacade::class );
		$facade->method( 'nonce_action' )->willReturn( 'nonce' );
		$facade->method( 'list_candidates' )->willReturn(
			ProspectiveReleaseResult::success(
				'release_candidates_available',
				array(
					'candidates' => array(
						array(
							'release_id' => '42',
							'tag'        => 'v1.2.3',
							'version'    => '1.2.3',
						),
					),
				)
			)
		);
		$facade->method( 'inspect' )->willReturnCallback(
			static function () use ( $database, $state, $selection ): ProspectiveReleaseResult {
				foreach ( $database->selection->rows as $row ) {
					if ( 'ran_booster_github_selection' === $row->option_name ) {
						$row->option_value = str_replace( '"selection_generation":0', '"selection_generation":1', $row->option_value );
					}
				}
				$current                         = $state->getValue( $selection );
				$current['selection_generation'] = 1;
				$state->setValue( $selection, $current );
				return ProspectiveReleaseResult::success(
					'release_ready',
					array(
						'release_id'   => '42',
						'tag'          => 'v1.2.3',
						'version'      => '1.2.3',
						'channel'      => 'stable',
						'package_root' => 'example',
						'main_file'    => 'example.php',
						'fingerprint'  => 'v2:' . str_repeat( 'a', 64 ),
					)
				);
			}
		);
		$service = new PortabilityApplicationService( new BlueprintReviewer( $plugins, $this->createStub( ThemeRepository::class ) ), new BlueprintRepositoryVerifier( $registry, $secrets, $selection ), ( new ReflectionClass( PackageOperationService::class ) )->newInstanceWithoutConstructor(), $secrets, prospective_releases: $facade );
		$package = new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/repository', '', null, new ManagedReleaseConfiguration( 'example', 'example.php' ) );

		$result = $service->review( new PackageBlueprint( array( $package ) ) )[0];

		self::assertSame( TargetPackageAction::BLOCKED, $result->action );
		self::assertSame( TargetPackageReason::PROVIDER_SELECTION_CHANGED, $result->reason );
	}

	public function test_release_install_preview_does_not_inspect_a_second_candidate_after_first_inspection_fails(): void {
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->method( 'is_installed' )->willReturn( false );
		$plugins->method( 'has_management_record' )->willReturn( false );
		$provider = $this->provider();
		$provider->method( 'resolve_repository' )->willReturn( $this->descriptor() );
		$facade = $this->createMock( ProspectiveReleaseFacade::class );
		$facade->method( 'nonce_action' )->willReturn( 'nonce' );
		$facade->expects( self::once() )->method( 'list_candidates' )->willReturn(
			ProspectiveReleaseResult::success(
				'release_candidates_available',
				array(
					'candidates' => array(
						array(
							'release_id' => 'first',
							'tag'        => 'v1.2.3',
							'version'    => '1.2.3',
						),
						array(
							'release_id' => 'second',
							'tag'        => 'v1.2.2',
							'version'    => '1.2.2',
						),
					),
				)
			)
		);
		$facade->expects( self::once() )->method( 'inspect' )->willReturnCallback(
			static function ( string $type, array $request, string $release_id, string $tag, string $channel, string $nonce ): ProspectiveReleaseResult {
				unset( $type, $request, $tag, $channel, $nonce );
				self::assertSame( 'first', $release_id );
				return ProspectiveReleaseResult::failure( 'release_invalid' );
			}
		);
		$package = new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/repository', '', null, new ManagedReleaseConfiguration( 'example', 'example.php' ) );

		$result = $this->service( $plugins, $this->createStub( ThemeRepository::class ), $provider, prospective: $facade )->review( new PackageBlueprint( array( $package ) ) )[0];

		self::assertSame( TargetPackageAction::BLOCKED, $result->action );
		self::assertSame( TargetPackageReason::RELEASE_UNAVAILABLE, $result->reason );
	}

	public function test_release_install_apply_requires_the_review_tuple_before_provider_or_credential_work(): void {
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->method( 'is_installed' )->willReturn( false );
		$plugins->method( 'has_management_record' )->willReturn( false );
		$provider = $this->provider();
		$provider->expects( self::never() )->method( 'resolve_repository' );
		$package = new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/repository', '', null, new ManagedReleaseConfiguration( 'example', 'example.php' ) );

		$result = $this->service( $plugins, $this->createStub( ThemeRepository::class ), $provider )->apply( new PackageBlueprint( array( $package ) ), 0, 'install', array(), null, false, true, null, $this->provider_expectation );

		self::assertSame( 'skipped', $result['status'] );
		self::assertSame( TargetPackageReason::RELEASE_REVIEW_CHANGED->message(), $result['message'] );
	}

	public function test_release_install_apply_refuses_a_legacy_fingerprint_before_provider_or_credential_work(): void {
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->method( 'is_installed' )->willReturn( false );
		$plugins->method( 'has_management_record' )->willReturn( false );
		$provider = $this->provider();
		$provider->expects( self::never() )->method( 'resolve_repository' );
		$package               = new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/repository', '', null, new ManagedReleaseConfiguration( 'example', 'example.php' ) );
		$review                = $this->release_review();
		$review['fingerprint'] = str_repeat( 'a', 64 );

		$result = $this->service( $plugins, $this->createStub( ThemeRepository::class ), $provider )->apply( new PackageBlueprint( array( $package ) ), 0, 'install', array(), null, false, true, null, $this->provider_expectation, $review );

		self::assertSame( 'skipped', $result['status'] );
		self::assertSame( TargetPackageReason::RELEASE_REVIEW_CHANGED->message(), $result['message'] );
	}

	public function test_release_install_apply_refuses_a_changed_fresh_review_without_installing(): void {
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->expects( self::once() )->method( 'is_installed' )->willReturn( false );
		$plugins->expects( self::once() )->method( 'has_management_record' )->willReturn( false );
		$provider = $this->provider();
		$provider->expects( self::once() )->method( 'resolve_repository' )->willReturn( $this->descriptor() );
		$facade = $this->createMock( ProspectiveReleaseFacade::class );
		$facade->method( 'nonce_action' )->willReturn( 'nonce' );
		$facade->method( 'list_candidates' )->willReturn(
			ProspectiveReleaseResult::success(
				'release_candidates_available',
				array(
					'candidates' => array(
						array(
							'release_id' => 'fresh',
							'tag'        => 'v1.2.3',
							'version'    => '1.2.3',
						),
					),
				)
			)
		);
		$facade->method( 'inspect' )->willReturn(
			ProspectiveReleaseResult::success(
				'release_ready',
				array(
					'release_id'   => 'fresh',
					'tag'          => 'v1.2.3',
					'version'      => '1.2.3',
					'channel'      => 'stable',
					'package_root' => 'example',
					'main_file'    => 'example.php',
					'fingerprint'  => 'v2:' . str_repeat( 'a', 64 ),
				)
			)
		);
		$facade->expects( self::never() )->method( 'install' );
		$package = new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/repository', '', null, new ManagedReleaseConfiguration( 'example', 'example.php' ) );
		$review  = array(
			'release_id'   => 'reviewed',
			'tag'          => 'v1.2.3',
			'version'      => '1.2.3',
			'channel'      => 'stable',
			'fingerprint'  => 'v2:' . str_repeat( 'a', 64 ),
			'package_root' => 'example',
			'main_file'    => 'example.php',
		);

		$result = $this->service( $plugins, $this->createStub( ThemeRepository::class ), $provider, prospective: $facade )->apply( new PackageBlueprint( array( $package ) ), 0, 'install', array(), null, false, true, null, $this->provider_expectation, $review );

		self::assertSame( 'skipped', $result['status'] );
		self::assertSame( TargetPackageReason::RELEASE_REVIEW_CHANGED->message(), $result['message'] );
	}

	public function test_release_install_apply_binds_reviewed_release_and_reads_back_management(): void {
		$plugin = Plugin::from_wp_array(
			'example/example.php',
			array(
				'Name'        => 'Example',
				'PluginURI'   => '',
				'Version'     => '1.2.3',
				'Description' => '',
				'Author'      => '',
				'AuthorURI'   => '',
				'TextDomain'  => '',
				'DomainPath'  => '',
				'Network'     => false,
				'Title'       => 'Example',
				'AuthorName'  => '',
			)
		);
		$plugin->set_repository( new ManagedRepository( 'gh', 'owner/repository', 'repository-id', 'main', false, null ) );
		$plugin->set_source( PackageSource::RELEASE_ASSET, 1 );
		$plugin->set_deployment_policy( DeploymentPolicy::DISABLED );
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->expects( self::exactly( 3 ) )->method( 'is_installed' )->with( 'example/example.php' )->willReturn( false, false, true );
		$plugins->expects( self::exactly( 3 ) )->method( 'has_management_record' )->with( 'example/example.php' )->willReturn( false, false, true );
		$plugins->expects( self::once() )->method( 'booster_plugin_from_file' )->with( 'example/example.php' )->willReturn( $plugin );
		$provider = $this->provider();
		$provider->expects( self::once() )->method( 'resolve_repository' )->willReturn( $this->descriptor() );
		$facade = $this->createMock( ProspectiveReleaseFacade::class );
		$facade->method( 'nonce_action' )->willReturn( 'nonce' );
		$facade->method( 'list_candidates' )->willReturn(
			ProspectiveReleaseResult::success(
				'release_candidates_available',
				array(
					'candidates' => array(
						array(
							'release_id' => '42',
							'tag'        => 'v1.2.3',
							'version'    => '1.2.3',
						),
					),
				)
			)
		);
		$facade->method( 'inspect' )->willReturn(
			ProspectiveReleaseResult::success(
				'release_ready',
				array(
					'release_id'   => '42',
					'tag'          => 'v1.2.3',
					'version'      => '1.2.3',
					'channel'      => 'stable',
					'package_root' => 'example',
					'main_file'    => 'example.php',
					'fingerprint'  => 'v2:' . str_repeat( 'a', 64 ),
				)
			)
		);
		$facade->expects( self::once() )->method( 'install' )->willReturnCallback(
			static function ( string $type, array $request, string $release_id, string $tag, string $fingerprint, string $channel, string $nonce, ?array $expectation, ?string $identifier, DeploymentPolicy $policy ): ProspectiveReleaseResult {
				self::assertSame( 'plugin', $type );
				self::assertSame( '42', $release_id );
				self::assertSame( 'v1.2.3', $tag );
				self::assertSame( 'v2:' . str_repeat( 'a', 64 ), $fingerprint );
				self::assertSame( 'stable', $channel );
				self::assertSame( 'example/example.php', $identifier );
				self::assertSame( DeploymentPolicy::DISABLED, $policy );
				self::assertSame( 'disabled', $request['deployment_policy'] );
				self::assertNotNull( $expectation );
				self::assertSame( wp_create_nonce( 'nonce' ), $nonce );
				return ProspectiveReleaseResult::success( 'installed' );
			}
		);
		$config = new ManagedReleaseConfiguration( 'example', 'example.php' );
		$store  = $this->createStub( ManagedReleaseStore::class );
		$store->method( 'configuration' )->willReturn( $config );
		$package = new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/repository', '', null, $config );
		$review  = array(
			'release_id'   => '42',
			'tag'          => 'v1.2.3',
			'version'      => '1.2.3',
			'channel'      => 'stable',
			'fingerprint'  => 'v2:' . str_repeat( 'a', 64 ),
			'package_root' => 'example',
			'main_file'    => 'example.php',
		);

		$result = $this->service( $plugins, $this->createStub( ThemeRepository::class ), $provider, prospective: $facade, store: $store )->apply( new PackageBlueprint( array( $package ) ), 0, 'install', array(), null, false, true, null, $this->provider_expectation, $review );

		self::assertSame( 'installed', $result['status'] );
	}

	public function test_release_install_refuses_when_the_target_appears_during_fresh_inspection(): void {
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->expects( self::exactly( 2 ) )->method( 'is_installed' )->with( 'example/example.php' )->willReturn( false, true );
		$plugins->expects( self::exactly( 2 ) )->method( 'has_management_record' )->with( 'example/example.php' )->willReturn( false, false );
		$provider = $this->provider();
		$provider->expects( self::once() )->method( 'resolve_repository' )->willReturn( $this->descriptor() );
		$facade = $this->createMock( ProspectiveReleaseFacade::class );
		$facade->method( 'nonce_action' )->willReturn( 'nonce' );
		$facade->expects( self::once() )->method( 'list_candidates' )->willReturn( $this->release_candidates() );
		$facade->expects( self::once() )->method( 'inspect' )->willReturn( $this->release_inspection() );
		$facade->expects( self::never() )->method( 'install' );
		$package = $this->release_package();

		$result = $this->service( $plugins, $this->createStub( ThemeRepository::class ), $provider, prospective: $facade )->apply( new PackageBlueprint( array( $package ) ), 0, 'install', array(), null, false, true, null, $this->provider_expectation, $this->release_review() );

		self::assertSame( 'skipped', $result['status'] );
		self::assertSame( 'none', $result['credential_state'] );
	}

	public function test_transferred_credential_remains_temporary_through_preview_and_fresh_release_inspection_without_persistence_on_rejection(): void {
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'is_installed' )->willReturn( false );
		$plugins->method( 'has_management_record' )->willReturn( false );
		$credential = new BlueprintCredential(
			'gh',
			'Imported PAT',
			'classic',
			array(),
			'temporary-secret',
			array(
				array(
					'type'       => 'plugin',
					'identifier' => 'example/example.php',
				),
			)
		);
		$blueprint  = new PackageBlueprint( array( $this->release_package() ), array( $credential ) );
		$secrets    = $this->createMock( SecretsFile::class );
		$secrets->expects( self::exactly( 2 ) )->method( 'with_temporary_credential' )->willReturnCallback(
			static function ( ProviderCode|string $provider, array $metadata, string $secret, callable $operation ): mixed {
				self::assertSame( 'gh', $provider instanceof ProviderCode ? $provider->value : $provider );
				self::assertSame( 'Imported PAT', $metadata['label'] );
				self::assertSame( 'temporary-secret', $secret );
				return $operation( 'temporary-id' );
			}
		);
		$secrets->method( 'credential_material' )->willReturnCallback(
			static fn ( ProviderCode|string $provider, ?string $id ): ?array => 'temporary-id' === $id
				? array(
					'id'       => 'temporary-id',
					'provider' => $provider instanceof ProviderCode ? $provider->value : $provider,
					'source'   => 'temporary',
					'kind'     => 'classic',
					'secret'   => 'temporary-secret',
				)
				: null
		);
		$secrets->expects( self::never() )->method( 'import_credentials_if_absent' );
		$provider = $this->provider();
		$provider->expects( self::exactly( 2 ) )->method( 'resolve_repository' )->willReturnCallback(
			function ( \RAN\RepositoryProvider\RepositoryLookupRequest $request ): RepositoryDescriptor {
				self::assertSame( 'temporary-id', $request->credential_id );
				return new RepositoryDescriptor( ProviderCode::parse( 'gh' ), 'owner/repository', 'example', 'repository-id', false, 'main', 'temporary-id' );
			}
		);
		$facade = $this->createMock( ProspectiveReleaseFacade::class );
		$facade->method( 'nonce_action' )->willReturn( 'nonce' );
		$facade->expects( self::exactly( 2 ) )->method( 'list_candidates' )->willReturnCallback(
			function ( string $type, array $request ): ProspectiveReleaseResult {
				self::assertSame( 'plugin', $type );
				self::assertSame( 'temporary-id', $request['credential_id'] );
				return $this->release_candidates();
			}
		);
		$inspections = 0;
		$facade->expects( self::exactly( 2 ) )->method( 'inspect' )->willReturnCallback(
			function ( string $type, array $request ) use ( &$inspections ): ProspectiveReleaseResult {
				self::assertSame( 'plugin', $type );
				self::assertSame( 'temporary-id', $request['credential_id'] );
				++$inspections;
				return 1 === $inspections ? $this->release_inspection() : ProspectiveReleaseResult::failure( 'release_invalid' );
			}
		);
		$facade->expects( self::never() )->method( 'install' );
		$service = $this->service( $plugins, $this->createStub( ThemeRepository::class ), $provider, prospective: $facade, secrets: $secrets );

		$preview = $service->review(
			$blueprint,
			array(
				0 => array(
					'action'    => \RAN\Portability\BlueprintCredentialAction::IMPORT,
					'target_id' => null,
				),
			)
		)[0];
		self::assertSame( TargetPackageReason::NONE, $preview->reason );
		self::assertSame( TargetPackageAction::INSTALL, $preview->action );
		self::assertNotNull( $preview->release_review );
		$result = $service->apply(
			$blueprint,
			0,
			'install',
			array(
				0 => array(
					'action'    => \RAN\Portability\BlueprintCredentialAction::IMPORT,
					'target_id' => null,
				),
			),
			null,
			false,
			true,
			null,
			$this->provider_expectation,
			$preview->release_review
		);

		self::assertSame( 'skipped', $result['status'] );
		self::assertSame( 'none', $result['credential_state'] );
	}

	public function test_successful_transferred_release_install_passes_the_persistent_imported_id_to_native_install(): void {
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->expects( self::exactly( 4 ) )->method( 'is_installed' )->willReturn( false, false, false, true );
		$plugins->expects( self::exactly( 4 ) )->method( 'has_management_record' )->willReturn( false, false, false, true );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $this->release_managed_plugin( 'persisted-id' ) );
		$credential     = new BlueprintCredential(
			'gh',
			'Imported PAT',
			'classic',
			array(),
			'temporary-secret',
			array(
				array(
					'type'       => 'plugin',
					'identifier' => 'example/example.php',
				),
			)
		);
		$blueprint      = new PackageBlueprint( array( $this->release_package() ), array( $credential ) );
		$transfer_state = new class() {
			public bool $persisted = false;
		};
		$secrets        = $this->createMock( SecretsFile::class );
		$secrets->expects( self::exactly( 2 ) )->method( 'with_temporary_credential' )->willReturnCallback(
			static function ( ProviderCode|string $provider, array $metadata, string $secret, callable $operation ): mixed {
				self::assertSame( 'gh', (string) $provider );
				self::assertSame( 'Imported PAT', $metadata['label'] );
				self::assertSame( 'temporary-secret', $secret );
				return $operation( 'temporary-id' );
			}
		);
		$secrets->method( 'credential_profiles' )->willReturnCallback( static fn (): array => $transfer_state->persisted ? array( 'persisted-id' => array( 'id' => 'persisted-id' ) ) : array() );
		$secrets->method( 'credential_material' )->willReturnCallback(
			static fn ( ProviderCode|string $provider, ?string $id ): ?array => 'gh' === (string) $provider && in_array( $id, array( 'temporary-id', 'persisted-id' ), true ) ? array(
				'id'       => $id,
				'provider' => 'gh',
				'source'   => 'temporary-id' === $id ? 'temporary' : 'file',
				'kind'     => 'classic',
				'secret'   => 'temporary-secret',
			) : null
		);
		$secrets->expects( self::once() )->method( 'import_credentials_if_absent' )->willReturnCallback(
			static function () use ( $transfer_state ): array {
				$transfer_state->persisted = true;
				return array( 'persisted-id' );
			}
		);
		$provider = $this->provider();
		$provider->method( 'resolve_repository' )->willReturnCallback( static fn ( \RAN\RepositoryProvider\RepositoryLookupRequest $request ): RepositoryDescriptor => new RepositoryDescriptor( ProviderCode::parse( 'gh' ), 'owner/repository', 'example', 'repository-id', false, 'main', $request->credential_id ) );
		$facade = $this->createMock( ProspectiveReleaseFacade::class );
		$facade->method( 'nonce_action' )->willReturn( 'nonce' );
		$facade->method( 'list_candidates' )->willReturn( $this->release_candidates() );
		$facade->method( 'inspect' )->willReturn( $this->release_inspection() );
		$facade->expects( self::once() )->method( 'install' )->willReturnCallback(
			static function ( string $type, array $request ): ProspectiveReleaseResult {
				self::assertSame( 'plugin', $type );
				self::assertSame( 'persisted-id', $request['credential_id'] );
				return ProspectiveReleaseResult::success( 'installed' );
			}
		);
		$store = $this->createStub( ManagedReleaseStore::class );
		$store->method( 'configuration' )->willReturn( new ManagedReleaseConfiguration( 'example', 'example.php' ) );
		$service = $this->service( $plugins, $this->createStub( ThemeRepository::class ), $provider, prospective: $facade, store: $store, secrets: $secrets );
		$preview = $service->review(
			$blueprint,
			array(
				0 => array(
					'action'    => \RAN\Portability\BlueprintCredentialAction::IMPORT,
					'target_id' => null,
				),
			)
		)[0];
		self::assertSame( TargetPackageAction::INSTALL, $preview->action );
		self::assertNotNull( $preview->release_review );
		$result = $service->apply(
			$blueprint,
			0,
			'install',
			array(
				0 => array(
					'action'    => \RAN\Portability\BlueprintCredentialAction::IMPORT,
					'target_id' => null,
				),
			),
			null,
			false,
			true,
			null,
			$this->provider_expectation,
			$preview->release_review
		);

		self::assertSame( 'installed', $result['status'], $result['message'] );
		self::assertSame( 'transferred_available', $result['credential_state'] );
	}

	public function test_transferred_release_target_choice_uses_the_saved_profile_for_preview_fresh_review_and_native_install(): void {
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->expects( self::exactly( 4 ) )->method( 'is_installed' )->willReturn( false, false, false, true );
		$plugins->expects( self::exactly( 4 ) )->method( 'has_management_record' )->willReturn( false, false, false, true );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $this->release_managed_plugin( 'saved-target-id' ) );
		$credential = new BlueprintCredential(
			'gh',
			'Imported PAT',
			'classic',
			array(),
			'temporary-secret',
			array(
				array(
					'type'       => 'plugin',
					'identifier' => 'example/example.php',
				),
			)
		);
		$blueprint  = new PackageBlueprint( array( $this->release_package() ), array( $credential ) );
		$secrets    = $this->createMock( SecretsFile::class );
		$secrets->expects( self::never() )->method( 'with_temporary_credential' );
		$secrets->method( 'credential_profiles' )->willReturn( array( 'saved-target-id' => array( 'id' => 'saved-target-id' ) ) );
		$secrets->method( 'credential_material' )->willReturnCallback(
			static fn ( ProviderCode|string $provider, ?string $id ): ?array => 'gh' === (string) $provider && 'saved-target-id' === $id ? array(
				'id'       => 'saved-target-id',
				'provider' => 'gh',
				'source'   => 'file',
				'kind'     => 'classic',
				'secret'   => 'temporary-secret',
			) : null
		);
		$secrets->expects( self::never() )->method( 'import_credentials_if_absent' );
		$provider = $this->provider();
		$provider->method( 'resolve_repository' )->willReturnCallback( static fn ( \RAN\RepositoryProvider\RepositoryLookupRequest $request ): RepositoryDescriptor => new RepositoryDescriptor( ProviderCode::parse( 'gh' ), 'owner/repository', 'example', 'repository-id', false, 'main', $request->credential_id ) );
		$facade = $this->createMock( ProspectiveReleaseFacade::class );
		$facade->method( 'nonce_action' )->willReturn( 'nonce' );
		$facade->expects( self::exactly( 2 ) )->method( 'list_candidates' )->willReturnCallback(
			function ( string $type, array $request ): ProspectiveReleaseResult {
				self::assertSame( 'plugin', $type );
				self::assertSame( 'saved-target-id', $request['credential_id'] );
				return $this->release_candidates();
			}
		);
		$facade->expects( self::exactly( 2 ) )->method( 'inspect' )->willReturnCallback(
			function ( string $type, array $request ): ProspectiveReleaseResult {
				self::assertSame( 'plugin', $type );
				self::assertSame( 'saved-target-id', $request['credential_id'] );
				return $this->release_inspection();
			}
		);
		$facade->expects( self::once() )->method( 'install' )->willReturnCallback(
			static function ( string $type, array $request ): ProspectiveReleaseResult {
				self::assertSame( 'plugin', $type );
				self::assertSame( 'saved-target-id', $request['credential_id'] );
				return ProspectiveReleaseResult::success( 'installed' );
			}
		);
		$store = $this->createStub( ManagedReleaseStore::class );
		$store->method( 'configuration' )->willReturn( new ManagedReleaseConfiguration( 'example', 'example.php' ) );
		$service = $this->service( $plugins, $this->createStub( ThemeRepository::class ), $provider, prospective: $facade, store: $store, secrets: $secrets );
		$preview = $service->review(
			$blueprint,
			array(
				0 => array(
					'action'    => \RAN\Portability\BlueprintCredentialAction::TARGET,
					'target_id' => 'saved-target-id',
				),
			)
		)[0];
		self::assertSame( TargetPackageAction::INSTALL, $preview->action );
		self::assertNotNull( $preview->release_review );
		$result = $service->apply(
			$blueprint,
			0,
			'install',
			array(
				0 => array(
					'action'    => \RAN\Portability\BlueprintCredentialAction::TARGET,
					'target_id' => 'saved-target-id',
				),
			),
			null,
			false,
			true,
			null,
			$this->provider_expectation,
			$preview->release_review
		);

		self::assertSame( 'installed', $result['status'], $result['message'] );
		self::assertSame( 'target_selected', $result['credential_state'] );
	}

	public function test_release_install_refuses_mismatched_authoritative_postinstall_readback(): void {
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->expects( self::exactly( 3 ) )->method( 'is_installed' )->with( 'example/example.php' )->willReturn( false, false, true );
		$plugins->expects( self::exactly( 3 ) )->method( 'has_management_record' )->with( 'example/example.php' )->willReturn( false, false, true );
		$installed = Plugin::from_wp_array(
			'example/example.php',
			array(
				'Name'        => 'Example',
				'PluginURI'   => '',
				'Version'     => '1.2.3',
				'Description' => '',
				'Author'      => '',
				'AuthorURI'   => '',
				'TextDomain'  => '',
				'DomainPath'  => '',
				'Network'     => false,
				'Title'       => 'Example',
				'AuthorName'  => '',
			)
		);
		$installed->set_repository( new ManagedRepository( 'gh', 'owner/other', 'other-id', 'main', false, null ) );
		$installed->set_source( PackageSource::RELEASE_ASSET, 1 );
		$installed->set_deployment_policy( DeploymentPolicy::DISABLED );
		$plugins->expects( self::once() )->method( 'booster_plugin_from_file' )->with( 'example/example.php' )->willReturn( $installed );
		$provider = $this->provider();
		$provider->expects( self::once() )->method( 'resolve_repository' )->willReturn( $this->descriptor() );
		$facade = $this->createMock( ProspectiveReleaseFacade::class );
		$facade->method( 'nonce_action' )->willReturn( 'nonce' );
		$facade->method( 'list_candidates' )->willReturn( $this->release_candidates() );
		$facade->method( 'inspect' )->willReturn( $this->release_inspection() );
		$facade->expects( self::once() )->method( 'install' )->willReturn( ProspectiveReleaseResult::success( 'installed' ) );
		$configuration = new ManagedReleaseConfiguration( 'example', 'example.php' );
		$store         = $this->createStub( ManagedReleaseStore::class );
		$store->method( 'configuration' )->willReturn( $configuration );

		$result = $this->service( $plugins, $this->createStub( ThemeRepository::class ), $provider, prospective: $facade, store: $store )->apply( new PackageBlueprint( array( $this->release_package() ) ), 0, 'install', array(), null, false, true, null, $this->provider_expectation, $this->release_review() );

		self::assertSame( 'failed', $result['status'] );
		self::assertSame( 'none', $result['credential_state'] );
	}

	public function test_release_install_binds_and_reads_back_a_theme_management_record(): void {
		$themes = $this->createMock( ThemeRepository::class );
		$themes->expects( self::exactly( 3 ) )->method( 'is_installed' )->with( 'example-theme' )->willReturn( false, false, true );
		$themes->expects( self::exactly( 3 ) )->method( 'has_management_record' )->with( 'example-theme' )->willReturn( false, false, true );
		$installed = $this->createStub( \RAN\Theme::class );
		$installed->method( 'get_source' )->willReturn( PackageSource::RELEASE_ASSET );
		$installed->method( 'get_identifier' )->willReturn( 'example-theme' );
		$installed->method( 'get_display_name' )->willReturn( 'Example Theme' );
		$installed->method( 'get_provider_code' )->willReturn( 'gh' );
		$installed->method( 'get_provider_repository_id' )->willReturn( 'repository-id' );
		$installed->method( 'get_repository' )->willReturn( new ManagedRepository( 'gh', 'owner/repository', 'repository-id', 'main', false, null ) );
		$installed->method( 'get_subdirectory' )->willReturn( null );
		$installed->method( 'get_credential_id' )->willReturn( '' );
		$installed->method( 'is_private' )->willReturn( false );
		$installed->method( 'get_deployment_policy' )->willReturn( DeploymentPolicy::DISABLED );
		$themes->expects( self::once() )->method( 'booster_theme_from_stylesheet' )->with( 'example-theme' )->willReturn( $installed );
		$provider = $this->provider();
		$provider->expects( self::once() )->method( 'resolve_repository' )->willReturn( $this->descriptor() );
		$facade = $this->createMock( ProspectiveReleaseFacade::class );
		$facade->method( 'nonce_action' )->willReturn( 'nonce' );
		$facade->method( 'list_candidates' )->willReturn( $this->release_candidates() );
		$facade->method( 'inspect' )->willReturn( $this->release_theme_inspection() );
		$facade->expects( self::once() )->method( 'install' )->willReturn( ProspectiveReleaseResult::success( 'installed' ) );
		$configuration = new ManagedReleaseConfiguration( 'example-theme', 'style.css' );
		$store         = $this->createStub( ManagedReleaseStore::class );
		$store->method( 'configuration' )->willReturn( $configuration );

		$result = $this->service( $this->createStub( PluginRepository::class ), $themes, $provider, prospective: $facade, store: $store )->apply( new PackageBlueprint( array( $this->release_theme_package() ) ), 0, 'install', array(), null, false, true, null, $this->provider_expectation, $this->release_theme_review() );

		self::assertSame( 'installed', $result['status'] );
	}

	#[DataProvider( 'release_install_failure_outcomes' )]
	public function test_release_install_apply_reports_uncertain_native_outcomes_without_adoption( string $code, string $message ): void {
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'is_installed' )->willReturn( false );
		$plugins->method( 'has_management_record' )->willReturn( false );
		$provider = $this->provider();
		$provider->method( 'resolve_repository' )->willReturn( $this->descriptor() );
		$facade = $this->createMock( ProspectiveReleaseFacade::class );
		$facade->method( 'nonce_action' )->willReturn( 'nonce' );
		$facade->method( 'list_candidates' )->willReturn(
			ProspectiveReleaseResult::success(
				'release_candidates_available',
				array(
					'candidates' => array(
						array(
							'release_id' => '42',
							'tag'        => 'v1.2.3',
							'version'    => '1.2.3',
						),
					),
				)
			)
		);
		$facade->method( 'inspect' )->willReturn(
			ProspectiveReleaseResult::success(
				'release_ready',
				array(
					'release_id'   => '42',
					'tag'          => 'v1.2.3',
					'version'      => '1.2.3',
					'channel'      => 'stable',
					'package_root' => 'example',
					'main_file'    => 'example.php',
					'fingerprint'  => 'v2:' . str_repeat( 'a', 64 ),
				)
			)
		);
		$facade->expects( self::once() )->method( 'install' )->willReturn( ProspectiveReleaseResult::failure( $code ) );
		$package = new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/repository', '', null, new ManagedReleaseConfiguration( 'example', 'example.php' ) );

		$result = $this->service( $plugins, $this->createStub( ThemeRepository::class ), $provider, prospective: $facade )->apply( new PackageBlueprint( array( $package ) ), 0, 'install', array(), null, false, true, null, $this->provider_expectation, $this->release_review() );

		self::assertSame( 'failed', $result['status'] );
		self::assertStringContainsString( $message, $result['message'] );
		self::assertNotSame( 'installed', $result['status'] );
		self::assertNotSame( 'adopted', $result['status'] );
	}

	/** @return iterable<string,array{string,string}> */
	public static function release_install_failure_outcomes(): iterable {
		yield 'files installed but management missing' => array( 'installed_but_unmanaged', 'management was not established' );
		yield 'management state uncertain' => array( 'management_state_uncertain', 'management state' );
		yield 'cleanup uncertain' => array( 'installation_cleanup_failed', 'cleanup' );
	}

	#[DataProvider( 'release_apply_modes' )]
	public function test_release_apply_adopts_an_installed_plugin_through_the_final_release_facade( string $mode ): void {
		$writes  = 0;
		$revoked = false;
		$plugin  = Plugin::from_wp_array(
			'example/example.php',
			array(
				'Name'        => 'Example',
				'PluginURI'   => '',
				'Version'     => '1.0.0',
				'Description' => '',
				'Author'      => '',
				'AuthorURI'   => '',
				'TextDomain'  => '',
				'DomainPath'  => '',
				'Network'     => false,
				'Title'       => 'Example',
				'AuthorName'  => '',
			)
		);
		$plugins = $this->createMock( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'is_installed' )->willReturn( true );
		$plugins->method( 'has_management_record' )->willReturn( false );
		$plugins->method( 'installed_plugin_from_file' )->willReturn( $plugin );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $plugin );
		$store  = $this->createStub( ManagedReleaseStore::class );
		$config = new ManagedReleaseConfiguration( 'example', 'example.php' );
		$store->method( 'configuration' )->willReturn( $config );
		$plugins->method( 'adopt_release' )->willReturnCallback(
			static function () use ( &$writes ): PackageMutationResult {
				++$writes;
				return PackageMutationResult::changed( PackageStorageOperation::INSERT );
			}
		);
		$provider = $this->createMockForIntersectionOfInterfaces( array( RepositoryProvider::class, RepositoryReleaseMetadata::class, RepositoryReleaseCandidateListing::class, RepositoryReleaseInspector::class, RepositoryReleaseNativeTargets::class ) );
		$provider->method( 'get_metadata' )->willReturn( new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' ) );
		$provider->method( 'resolve_repository' )->willReturn( new RepositoryDescriptor( ProviderCode::parse( 'gh' ), 'owner/repository', 'example', 'repository-id', false, 'main', 'profile' ) );
		$provider->method( 'expected_update_uri' )->willReturn( 'https://github.com/owner/repository' );
		$provider->method( 'release_details_url' )->willReturn( 'https://github.com/owner/repository/releases/tag/v2.0.0' );
		$provider->method( 'list_release_candidates' )->willReturn( new RepositoryReleaseCandidateList( array( new RepositoryReleaseCandidate( '1', 'v2.0.0', '2.0.0', false, '2026-01-01T00:00:00Z', array( 'example.zip' ) ) ) ) );
		$provider->method( 'inspect_release' )->willReturnCallback(
			static function () use ( &$revoked, $mode ): RepositoryReleaseInspection {
				$revoked = 'revoked' === $mode;
				return new RepositoryReleaseInspection( '1', 'v2.0.0', '2.0.0', str_repeat( 'a', 40 ), 'example', 'example.php', 'v1:' . str_repeat( 'b', 64 ) );
			}
		);
		$provider->method( 'has_registered_native_target' )->willReturn( false );
		self::assertInstanceOf( RepositoryProvider::class, $provider );
		$catalog   = new ProviderSecretPolicyCatalog();
		$registry  = new ProviderRegistry( array( $provider ), $catalog );
		$selection = GitHubSelectionTestFixture::bind( $registry );
		$lock      = $this->createStub( WordPressUpdaterLock::class );
		$lock->method( 'acquire' )->willReturn( 'lock' );
		$lock->method( 'release' )->willReturn( 'uncertain' !== $mode );
		$lock->method( 'current_token' )->willReturn( 'lock' );
		$guard      = new RepositorySourceGuard( new RepositorySourceGuardDatabase(), $this->createStub( Database::class ) );
		$registrar  = new ManagedReleaseTargetRegistrar( $plugins, $themes, $store, $lock, $registry, $selection, $guard );
		$facade     = new NativeReleaseTrackingFacade( $plugins, $themes, $store, $registrar, $lock, $registry, metadata_eligible: static fn (): bool => true, source_guard: $guard );
		$operations = new PackageOperationService( $plugins, $themes, $this->createStub( DeploymentCoordinator::class ), ( new ReflectionClass( PackageRemovalService::class ) )->newInstanceWithoutConstructor(), $lock, $guard );
		$secrets    = $this->createMock( SecretsFile::class );
		$secrets->method( 'credential_profiles' )->willReturnCallback(
			static function ( ProviderCode|string $provider ) use ( &$revoked ): array {
				self::assertSame( 'gh', $provider instanceof ProviderCode ? $provider->value : $provider );
				return $revoked ? array() : array( 'profile' => array() );
			}
		);
		$secrets->method( 'credential_material' )->willReturn(
			array(
				'id'       => 'profile',
				'provider' => 'gh',
				'kind'     => 'classic',
				'secret'   => 'synthetic-preview-material',
			)
		);
		$service   = new PortabilityApplicationService( new BlueprintReviewer( $plugins, $themes ), new BlueprintRepositoryVerifier( $registry, $secrets, $selection ), $operations, $secrets, $facade, $store );
		$blueprint = new PackageBlueprint( array( new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/repository', '', null, $config ) ) );
		$item      = $service->review( $blueprint, array(), array( 0 => 'profile' ) )[0];
		self::assertSame( TargetPackageReason::NONE, $item->reason );
		self::assertSame( TargetPackageAction::ADOPT, $item->action );
		self::assertSame( $selection->expectation(), $item->provider_expectation );
		if ( 'revoked' === $mode ) {
			try {
				$service->apply( $blueprint, 0, 'adopt', array(), 'profile', true, true, null, $item->provider_expectation );
				self::fail( 'The final credential verification must refuse a revoked target profile.' );
			} catch ( RuntimeException $failure ) {
				self::assertStringContainsString( 'not ready for published release tracking', $failure->getMessage() );
			}
			self::assertSame( 0, $writes );
			return;
		}

		$result = $service->apply( $blueprint, 0, 'adopt', array(), 'profile', true, true, null, $item->provider_expectation );
		if ( 'uncertain' === $mode ) {
			self::assertSame( 'failed', $result['status'] );
			self::assertStringContainsString( 'could not confirm the release adoption outcome', $result['message'] );
			self::assertNotSame( 'adopted', $result['status'] );
			self::assertSame( 1, $writes );
			return;
		}

		self::assertSame( 'adopted', $result['status'] );
		self::assertSame( 1, $writes );
		self::assertSame( PackageSource::RELEASE_ASSET, $plugin->get_source() );
		self::assertSame( DeploymentPolicy::DISABLED, $plugin->get_deployment_policy() );
	}

	/** @return iterable<string,array{string}> */
	public static function release_apply_modes(): iterable {
		yield 'success' => array( 'success' );
		yield 'revoked target credential' => array( 'revoked' );
		yield 'uncertain lock release' => array( 'uncertain' );
	}

	public function test_unselected_release_does_not_block_branch_and_changed_selection_is_rechecked(): void {
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->expects( self::once() )->method( 'is_installed' )->willReturn( false );
		$plugins->expects( self::once() )->method( 'has_management_record' )->willReturn( false );
		$provider = $this->provider();
		$provider->expects( self::never() )->method( 'resolve_repository' );
		$branch    = $this->package();
		$release   = new BlueprintPackage( 'plugin', 'release/release.php', 'Release', $branch->provider, $branch->provider_repository_id, $branch->repository, '', null, new \RAN\WordPress\ManagedReleaseConfiguration( 'release', 'release.php' ) );
		$blueprint = new PackageBlueprint( array( $branch, $release ) );
		$service   = $this->service( $plugins, $this->createStub( ThemeRepository::class ), $provider );
		self::assertSame( 'failed', $service->apply( $blueprint, 0, 'install', array(), null, false, false, array( 0 ), $this->provider_expectation )['status'] );
		self::assertSame( 'skipped', $service->apply( $blueprint, 0, 'install', array(), null, false, true, array( 0, 1 ), $this->provider_expectation )['status'] );
	}

	/** @param array<int,mixed> $selection */
	#[DataProvider( 'invalid_selections' )]
	public function test_invalid_selection_refuses_before_local_or_provider_access( array $selection ): void {
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->expects( self::never() )->method( 'is_installed' );
		$provider = $this->provider();
		$provider->expects( self::never() )->method( 'resolve_repository' );
		$this->expectException( InvalidArgumentException::class );
		$this->service( $plugins, $this->createStub( ThemeRepository::class ), $provider )->apply( new PackageBlueprint( array( $this->package() ) ), 0, 'install', array(), null, true, true, $selection, $this->provider_expectation );
	}

	/** @return iterable<string,array{array<int,mixed>}> */
	public static function invalid_selections(): iterable {
		yield 'empty' => array( array() );
		yield 'missing current row' => array( array( 1 ) );
		yield 'duplicate' => array( array( 0, 0 ) );
		yield 'unknown row' => array( array( 0, 1 ) );
		yield 'negative' => array( array( 0, -1 ) );
		yield 'string' => array( array( 0, '0' ) );
		yield 'non-list' => array( array( 1 => 0 ) );
	}

	#[DataProvider( 'package_counts' )]
	public function test_sequential_apply_classifies_each_selected_package_once( int $count ): void {
		$plugins  = $this->createMock( PluginRepository::class );
		$themes   = $this->createMock( ThemeRepository::class );
		$provider = $this->provider();
		$packages = array();
		for ( $index = 0; $index < $count; ++$index ) {
			$packages[] = $this->package( 'example-' . $index . '/example.php' );
		}
		$identifiers = array();
		$plugins->expects( self::exactly( $count ) )->method( 'is_installed' )->willReturnCallback(
			static function ( string $identifier ) use ( &$identifiers ): bool {
				$identifiers[] = $identifier;
				return false;
			}
		);
		$plugins->expects( self::exactly( $count ) )->method( 'has_management_record' )->willReturn( false );
		$themes->expects( self::never() )->method( 'is_installed' );
		$themes->expects( self::never() )->method( 'has_management_record' );
		$provider->expects( self::never() )->method( 'resolve_repository' );
		$service   = $this->service( $plugins, $themes, $provider );
		$blueprint = new PackageBlueprint( $packages );
		foreach ( $packages as $row => $package ) {
			$result = $service->apply( $blueprint, $row, 'install', array(), null, false, false, null, $this->provider_expectation );
			self::assertSame( 'failed', $result['status'] );
		}
		self::assertSame( array_map( static fn ( BlueprintPackage $package ): string => $package->identifier, $packages ), $identifiers );
	}

	/** @return iterable<string, array{int}> */
	public static function package_counts(): iterable {
		yield 'ten' => array( 10 );
		yield 'twenty' => array( 20 );
		yield 'fifty' => array( 50 );
	}

	#[DataProvider( 'invalid_rows' )]
	public function test_invalid_row_is_rejected_before_any_local_or_provider_probe( int $row ): void {
		$plugins  = $this->createMock( PluginRepository::class );
		$themes   = $this->createMock( ThemeRepository::class );
		$provider = $this->provider();
		$plugins->expects( self::never() )->method( 'is_installed' );
		$plugins->expects( self::never() )->method( 'has_management_record' );
		$themes->expects( self::never() )->method( 'is_installed' );
		$provider->expects( self::never() )->method( 'resolve_repository' );

		$this->expectException( InvalidArgumentException::class );
		$this->service( $plugins, $themes, $provider )->apply( new PackageBlueprint( array( $this->package() ) ), $row, 'install', array(), null, true, true, null, $this->provider_expectation );
	}

	/** @return iterable<string, array{int}> */
	public static function invalid_rows(): iterable {
		yield 'negative' => array( -1 );
		yield 'one past end' => array( 1 );
	}

	public function test_unrelated_theme_probe_cannot_abort_or_mutate_the_selected_plugin_review(): void {
		$plugins  = $this->createMock( PluginRepository::class );
		$themes   = $this->createMock( ThemeRepository::class );
		$provider = $this->provider();
		$plugins->expects( self::once() )->method( 'is_installed' )->willReturn( false );
		$plugins->expects( self::once() )->method( 'has_management_record' )->willReturn( false );
		$themes->expects( self::never() )->method( 'is_installed' );
		$themes->method( 'is_installed' )->willReturnCallback(
			static function (): never {
				throw PackageStorageFailure::query_failed();
			}
		);
		$provider->expects( self::never() )->method( 'resolve_repository' );
		$blueprint = new PackageBlueprint( array( new BlueprintPackage( 'theme', 'other-theme', 'Other', 'gh', 'other-id', 'owner/other', 'main', null ), $this->package() ) );

		$result = $this->service( $plugins, $themes, $provider )->apply( $blueprint, 1, 'adopt', array(), null, true, true, null, $this->provider_expectation );

		self::assertSame( 'skipped', $result['status'] );
		self::assertStringContainsString( 'changed since review', $result['message'] );
	}

	public function test_selected_database_failure_still_aborts_before_provider_access(): void {
		$plugins  = $this->createMock( PluginRepository::class );
		$provider = $this->provider();
		$plugins->method( 'is_installed' )->willReturn( false );
		$plugins->method( 'has_management_record' )->willThrowException( PackageStorageFailure::unsupported_database() );
		$provider->expects( self::never() )->method( 'resolve_repository' );

		$this->expectException( PackageStorageFailure::class );
		$this->expectExceptionMessage( 'database requirements' );
		$this->service( $plugins, $this->createStub( ThemeRepository::class ), $provider )->apply( new PackageBlueprint( array( $this->package() ) ), 0, 'install', array(), null, true, true, null, $this->provider_expectation );
	}

	#[DataProvider( 'early_refusals' )]
	public function test_capability_and_consent_refusals_precede_provider_access( bool $installed, bool $adopt, bool $can_install, string $status ): void {
		$plugins  = $this->createStub( PluginRepository::class );
		$provider = $this->provider();
		$plugins->method( 'is_installed' )->willReturn( $installed );
		$plugins->method( 'has_management_record' )->willReturn( false );
		$provider->expects( self::never() )->method( 'resolve_repository' );

		$result = $this->service( $plugins, $this->createStub( ThemeRepository::class ), $provider )->apply( new PackageBlueprint( array( $this->package() ) ), 0, $installed ? 'adopt' : 'install', array(), null, $adopt, $can_install, null, $this->provider_expectation );

		self::assertSame( $status, $result['status'] );
		self::assertSame( 'none', $result['credential_state'] );
	}

	/** @return iterable<string, array{bool,bool,bool,string}> */
	public static function early_refusals(): iterable {
		yield 'install without capability or adoption consent' => array( false, false, false, 'failed' );
		yield 'install without capability with adoption consent' => array( false, true, false, 'failed' );
		yield 'adopt without capability or consent' => array( true, false, false, 'failed' );
		yield 'adopt without capability with consent' => array( true, true, false, 'failed' );
		yield 'adopt without consent' => array( true, false, true, 'skipped' );
	}

	public function test_failed_provider_identity_cannot_reach_package_mutation(): void {
		$plugins  = $this->createMock( PluginRepository::class );
		$provider = $this->provider();
		$plugins->method( 'is_installed' )->willReturn( true );
		$plugins->method( 'has_management_record' )->willReturn( false );
		$plugins->expects( self::never() )->method( 'installed_plugin_from_file' );
		$provider->expects( self::once() )->method( 'resolve_repository' )->willReturn( $this->descriptor( 'different-id' ) );

		$result = $this->service( $plugins, $this->createStub( ThemeRepository::class ), $provider )->apply( new PackageBlueprint( array( $this->package() ) ), 0, 'adopt', array(), null, true, true, null, $this->provider_expectation );

		self::assertSame( 'skipped', $result['status'] );
		self::assertSame( 'none', $result['credential_state'] );
	}

	#[DataProvider( 'apply_entrypoints' )]
	public function test_provider_callback_cannot_bypass_fresh_installed_target_lookup( bool $candidate ): void {
		$plugins  = $this->createMock( PluginRepository::class );
		$themes   = $this->createStub( ThemeRepository::class );
		$provider = $this->provider();
		$changed  = (object) array( 'value' => false );
		$plugins->method( 'is_installed' )->willReturn( true );
		$plugins->method( 'has_management_record' )->willReturn( false );
		$plugins->expects( self::once() )->method( 'installed_plugin_from_file' )->willReturnCallback(
			static function () use ( &$changed ): never {
				self::assertTrue( $changed->value );
				throw new RuntimeException( 'Selected target changed during provider verification.' );
			}
		);
		$provider->method( 'resolve_repository' )->willReturnCallback(
			function () use ( &$changed ): RepositoryDescriptor {
				$changed->value = true;
				return $this->descriptor();
			}
		);
		$lock = $this->createMock( WordPressUpdaterLock::class );
		$lock->expects( self::once() )->method( 'acquire' )->willReturn( 'test-lock' );
		$lock->expects( self::once() )->method( 'release' )->with( 'test-lock' )->willReturn( true );
		$operations = new PackageOperationService(
			$plugins,
			$themes,
			$this->createStub( DeploymentCoordinator::class ),
			( new ReflectionClass( PackageRemovalService::class ) )->newInstanceWithoutConstructor(),
			$lock,
			new RepositorySourceGuard( new \stdClass(), $this->createStub( Database::class ) )
		);
		$service    = $this->service( $plugins, $themes, $provider, $operations );
		$plugins->expects( self::never() )->method( 'adopt' );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Selected target changed during provider verification.' );
		if ( $candidate ) {
			$input          = new PortabilityCandidate( 'plugin', 'example/example.php', 'Example', 'gh', 'owner/repository', 'main' );
			$review         = $service->review_candidate( $input );
			$changed->value = false;
			$service->apply_candidate( $input, $review->fingerprint, $review->provider_expectation );
		} else {
			$service->apply( new PackageBlueprint( array( $this->package() ) ), 0, 'adopt', array(), null, true, true, null, $this->provider_expectation );
		}
	}

	#[DataProvider( 'source_failures' )]
	public function test_post_provider_source_admission_failure_prevents_adoption( bool $unsupported ): void {
		$plugins   = $this->createMock( PluginRepository::class );
		$themes    = $this->createStub( ThemeRepository::class );
		$provider  = $this->provider();
		$database  = new \RAN\Tests\Support\RepositorySourceGuardDatabase();
		$lifecycle = $this->createMock( Database::class );
		$plugins->method( 'is_installed' )->willReturn( true );
		$plugins->method( 'has_management_record' )->willReturn( false );
		$installed = $this->createStub( \RAN\Plugin::class );
		$installed->method( 'get_identifier' )->willReturn( 'example/example.php' );
		$installed->method( 'get_provider_code' )->willReturn( 'gh' );
		$installed->method( 'get_provider_repository_id' )->willReturn( 'repository-id' );
		$plugins->expects( self::once() )->method( 'installed_plugin_from_file' )->willReturn( $installed );
		$plugins->expects( self::never() )->method( 'adopt' );
		$provider->expects( self::once() )->method( 'resolve_repository' )->willReturnCallback(
			function () use ( $database ): RepositoryDescriptor {
				$database->rows[] = (object) array(
					'provider'               => 'gh',
					'provider_repository_id' => 'repository-id',
					'type'                   => 2,
					'package'                => 'release-owner',
					'source'                 => 'release_asset',
				);
				return $this->descriptor();
			}
		);
		if ( $unsupported ) {
			$lifecycle->expects( self::once() )->method( 'require_ready' )->willThrowException( PackageStorageFailure::unsupported_database() );
		} else {
			$lifecycle->expects( self::once() )->method( 'require_ready' );
		}
		$lock = $this->createMock( WordPressUpdaterLock::class );
		$lock->expects( self::once() )->method( 'acquire' )->willReturn( 'test-lock' );
		$lock->expects( self::once() )->method( 'release' )->with( 'test-lock' )->willReturn( true );
		$operations = new PackageOperationService(
			$plugins,
			$themes,
			$this->createStub( DeploymentCoordinator::class ),
			( new ReflectionClass( PackageRemovalService::class ) )->newInstanceWithoutConstructor(),
			$lock,
			new RepositorySourceGuard( $database, $lifecycle )
		);
		$this->expectException( PackageStorageFailure::class );
		$this->expectExceptionMessage( $unsupported ? 'database requirements' : 'already supplies releases' );
		$this->service( $plugins, $themes, $provider, $operations )->apply( new PackageBlueprint( array( $this->package() ) ), 0, 'adopt', array(), null, true, true, null, $this->provider_expectation );
	}

	/** @return iterable<string, array{bool}> */
	public static function source_failures(): iterable {
		yield 'release owner added during provider verification' => array( false );
		yield 'database unsupported after provider verification' => array( true );
	}

	/** @return iterable<string, array{bool}> */
	public static function apply_entrypoints(): iterable {
		yield 'uploaded Blueprint' => array( false );
		yield 'public adoption candidate' => array( true );
	}

	/** @return RepositoryProvider&\PHPUnit\Framework\MockObject\MockObject */
	private function provider(): RepositoryProvider {
		$provider = $this->createMock( RepositoryProvider::class );
		$provider->method( 'get_metadata' )->willReturn( new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://provider.example.test/', 'Owner' ) );
		return $provider;
	}

	private function descriptor( string $identity = 'repository-id' ): RepositoryDescriptor {
		return new RepositoryDescriptor( ProviderCode::parse( 'gh' ), 'owner/repository', 'example', $identity, false, 'main', null );
	}

	private function package( string $identifier = 'example/example.php' ): BlueprintPackage {
		return new BlueprintPackage( 'plugin', $identifier, 'Example', 'gh', 'repository-id', 'owner/repository', 'main', null );
	}

	private function release_package(): BlueprintPackage {
		return new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/repository', '', null, new ManagedReleaseConfiguration( 'example', 'example.php' ) );
	}

	private function release_managed_plugin( string $credential_id ): Plugin {
		$plugin = Plugin::from_wp_array(
			'example/example.php',
			array(
				'Name'        => 'Example',
				'PluginURI'   => '',
				'Version'     => '1.2.3',
				'Description' => '',
				'Author'      => '',
				'AuthorURI'   => '',
				'TextDomain'  => '',
				'DomainPath'  => '',
				'Network'     => false,
				'Title'       => 'Example',
				'AuthorName'  => '',
			)
		);
		$plugin->set_repository( new ManagedRepository( 'gh', 'owner/repository', 'repository-id', 'main', false, $credential_id ) );
		$plugin->set_source( PackageSource::RELEASE_ASSET, 1 );
		$plugin->set_deployment_policy( DeploymentPolicy::DISABLED );
		return $plugin;
	}

	private function release_theme_package(): BlueprintPackage {
		return new BlueprintPackage( 'theme', 'example-theme', 'Example Theme', 'gh', 'repository-id', 'owner/repository', '', null, new ManagedReleaseConfiguration( 'example-theme', 'style.css' ) );
	}

	private function release_candidates(): ProspectiveReleaseResult {
		return ProspectiveReleaseResult::success(
			'release_candidates_available',
			array(
				'candidates' => array(
					array(
						'release_id' => '42',
						'tag'        => 'v1.2.3',
						'version'    => '1.2.3',
					),
				),
			)
		);
	}

	private function release_inspection(): ProspectiveReleaseResult {
		return ProspectiveReleaseResult::success( 'release_ready', $this->release_review() );
	}

	private function release_theme_inspection(): ProspectiveReleaseResult {
		return ProspectiveReleaseResult::success( 'release_ready', $this->release_theme_review() );
	}

	/** @return array{release_id:string,tag:string,version:string,channel:string,fingerprint:string,package_root:string,main_file:string} */
	private function release_review(): array {
		return array(
			'release_id'   => '42',
			'tag'          => 'v1.2.3',
			'version'      => '1.2.3',
			'channel'      => 'stable',
			'fingerprint'  => 'v2:' . str_repeat( 'a', 64 ),
			'package_root' => 'example',
			'main_file'    => 'example.php',
		);
	}

	/** @return array{release_id:string,tag:string,version:string,channel:string,fingerprint:string,package_root:string,main_file:string} */
	private function release_theme_review(): array {
		return array_replace(
			$this->release_review(),
			array(
				'package_root' => 'example-theme',
				'main_file'    => 'style.css',
			)
		);
	}

	private function service( PluginRepository $plugins, ThemeRepository $themes, RepositoryProvider $provider, ?PackageOperationService $operations = null, ?ProspectiveReleaseFacade $prospective = null, ?ManagedReleaseStore $store = null, ?SecretsFile $secrets = null ): PortabilityApplicationService {
		$catalog                    = new ProviderSecretPolicyCatalog();
		$secrets                  ??= new SecretsFile( null, array(), $catalog );
		$registry                   = new ProviderRegistry( array( $provider ), $catalog );
		$selection                  = GitHubSelectionTestFixture::bind( $registry );
		$this->provider_expectation = $selection->expectation();
		return new PortabilityApplicationService(
			new BlueprintReviewer( $plugins, $themes ),
			new BlueprintRepositoryVerifier( $registry, $secrets, $selection ),
			$operations ?? ( new ReflectionClass( PackageOperationService::class ) )->newInstanceWithoutConstructor(),
			$secrets,
			release_store: $store ?? new ManagedReleaseStore(),
			prospective_releases: $prospective
		);
	}
}
