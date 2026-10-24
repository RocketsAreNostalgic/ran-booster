<?php

declare(strict_types=1);

namespace RAN\Tests\Portability;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\AddOn\Portability\PortabilityCandidate;
use RAN\Deployment\DeploymentCoordinator;
use RAN\PackageOperationService;
use RAN\PackageRemoval\PackageRemovalService;
use RAN\Portability\BlueprintPackage;
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
use RAN\Secrets\SecretsFile;
use RAN\Storage\Database;
use RAN\Storage\PackageStorageFailure;
use RAN\Storage\PluginRepository;
use RAN\Storage\RepositorySourceGuard;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\WordPressUpdaterLock;
use ReflectionClass;
use RuntimeException;

require_once __DIR__ . '/../Support/PackageOperationGlobalWordPressFunctions.php';
require_once __DIR__ . '/../Support/PackageOperationWordPressFunctions.php';
require_once __DIR__ . '/../Runtime/RuntimeSupportWordPressFunctions.php';
require_once __DIR__ . '/WpPusherCoexistenceWordPressFunctions.php';

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

	private function service( PluginRepository $plugins, ThemeRepository $themes, RepositoryProvider $provider, ?PackageOperationService $operations = null ): PortabilityApplicationService {
		$catalog                    = new ProviderSecretPolicyCatalog();
		$secrets                    = new SecretsFile( null, array(), $catalog );
		$registry                   = new ProviderRegistry( array( $provider ), $catalog );
		$selection                  = GitHubSelectionTestFixture::bind( $registry );
		$this->provider_expectation = $selection->expectation();
		return new PortabilityApplicationService(
			new BlueprintReviewer( $plugins, $themes ),
			new BlueprintRepositoryVerifier( $registry, $secrets, $selection ),
			$operations ?? ( new ReflectionClass( PackageOperationService::class ) )->newInstanceWithoutConstructor(),
			$secrets
		);
	}
}
