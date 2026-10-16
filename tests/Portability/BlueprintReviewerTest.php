<?php

declare(strict_types=1);

namespace RAN\Tests\Portability;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\ManagedRepository;
use RAN\Package;
use RAN\PackageSource;
use RAN\WordPress\ManagedReleaseConfiguration;
use RAN\WordPress\ManagedReleaseStore;
use RAN\Portability\BlueprintPackage;
use RAN\Portability\BlueprintReviewer;
use RAN\Portability\PackageBlueprint;
use RAN\Portability\TargetPackageAction;
use RAN\Portability\TargetPackageReason;
use RAN\Storage\PackageStorageFailure;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;

require_once dirname( __DIR__ ) . '/Storage/StorageTestEnvironment.php';

#[CoversClass( BlueprintReviewer::class )]
final class BlueprintReviewerTest extends TestCase {

	#[DataProvider( 'local_states' )]
	public function test_it_classifies_one_package_from_current_local_state(
		bool $installed,
		bool $managed,
		?string $managed_repository_id,
		?string $failure,
		TargetPackageAction $action,
		TargetPackageReason $reason
	): void {
		$plugins = $this->createMock( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$package = $this->blueprint_package();

		$plugins->expects( self::once() )->method( 'is_installed' )->with( $package->identifier )->willReturn( $installed );
		$plugins->expects( self::once() )->method( 'has_management_record' )->with( $package->identifier )->willReturn( $managed );
		if ( $installed && $managed ) {
			if ( null !== $failure ) {
				$plugins->expects( self::once() )->method( 'booster_plugin_from_file' )->willThrowException(
					'duplicate' === $failure ? PackageStorageFailure::duplicate_package_rows() : PackageStorageFailure::invalid_provider_identity()
				);
			} else {
				$plugins->expects( self::once() )->method( 'booster_plugin_from_file' )->willReturn( $this->managed_package( $managed_repository_id ?? 'repository-id' ) );
			}
		}

		$item = ( new BlueprintReviewer( $plugins, $themes ) )->review( new PackageBlueprint( array( $package ) ) )[0];

		self::assertSame( $action, $item->action );
		self::assertSame( $reason, $item->reason );
	}

	/** @return iterable<string, array{bool, bool, ?string, ?string, TargetPackageAction, TargetPackageReason}> */
	public static function local_states(): iterable {
		yield 'not installed and unmanaged installs' => array( false, false, null, null, TargetPackageAction::INSTALL, TargetPackageReason::NONE );
		yield 'installed and unmanaged adopts' => array( true, false, null, null, TargetPackageAction::ADOPT, TargetPackageReason::NONE );
		yield 'missing package with management record is stale' => array( false, true, null, null, TargetPackageAction::PROTECTED, TargetPackageReason::STALE_MANAGEMENT );
		yield 'matching management is already managed' => array( true, true, 'repository-id', null, TargetPackageAction::MANAGED, TargetPackageReason::ALREADY_MANAGED );
		yield 'different management is protected' => array( true, true, 'different-repository-id', null, TargetPackageAction::PROTECTED, TargetPackageReason::MANAGEMENT_CONFLICT );
		yield 'duplicate management rows are protected' => array( true, true, null, 'duplicate', TargetPackageAction::PROTECTED, TargetPackageReason::MANAGEMENT_CONFLICT );
		yield 'malformed management is protected' => array( true, true, null, 'malformed', TargetPackageAction::PROTECTED, TargetPackageReason::MALFORMED_MANAGEMENT );
	}

	public function test_unsupported_database_is_not_reclassified_as_blueprint_corruption(): void {
		$plugins = $this->createMock( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$package = $this->blueprint_package();
		$plugins->method( 'is_installed' )->willReturn( true );
		$plugins->method( 'has_management_record' )->willReturn( true );
		$plugins->method( 'booster_plugin_from_file' )->willThrowException( PackageStorageFailure::unsupported_database() );

		$this->expectException( PackageStorageFailure::class );
		$this->expectExceptionMessage( 'database requirements' );

		( new BlueprintReviewer( $plugins, $themes ) )->review( new PackageBlueprint( array( $package ) ) );
	}

	#[DataProvider( 'local_states' )]
	public function test_local_comparison_preserves_protected_and_unknown_states(
		bool $installed,
		bool $managed,
		?string $managed_repository_id,
		?string $failure,
		TargetPackageAction $action,
		TargetPackageReason $reason
	): void {
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->method( 'is_installed' )->willReturn( $installed );
		$plugins->method( 'has_management_record' )->willReturn( $managed );
		if ( null !== $failure ) {
			$plugins->method( 'booster_plugin_from_file' )->willThrowException( 'duplicate' === $failure ? PackageStorageFailure::duplicate_package_rows() : PackageStorageFailure::invalid_provider_identity() );
		} else {
			$plugins->method( 'booster_plugin_from_file' )->willReturn( $this->managed_package( $managed_repository_id ?? 'repository-id' ) );
		}
		$report = ( new BlueprintReviewer( $plugins, $this->createStub( ThemeRepository::class ) ) )->compare_local( $this->blueprint_package() );
		$state  = match ( $action ) {
			TargetPackageAction::INSTALL => 'absent',
			TargetPackageAction::ADOPT => 'unmanaged',
			TargetPackageAction::MANAGED => 'matching',
			default => null === $failure && TargetPackageReason::MANAGEMENT_CONFLICT === $reason ? 'different' : 'unavailable',
		};
		self::assertSame( $state, $report['state'] );
		self::assertSame( 'unknown', $report['installed_equality'] );
		self::assertSame( 'not_checked', $report['source_access'] );
		self::assertSame( 'not_checked', $report['package_inspection'] );
		self::assertArrayNotHasKey( 'display_name', $report['requested'] );
		self::assertArrayNotHasKey( 'provider_expectation', $report );
		if ( 'different' === $state ) {
			self::assertSame( array( 'provider_repository_id' ), $report['differences'] );
		}
	}

	public function test_release_comparison_is_local_and_does_not_enable_execution(): void {
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->expects( self::once() )->method( 'is_installed' )->willReturn( true );
		$plugins->expects( self::once() )->method( 'has_management_record' )->willReturn( true );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $this->managed_package( 'repository-id', PackageSource::RELEASE_ASSET ) );
		$release = new ManagedReleaseConfiguration( 'example', 'example.php' );
		$store   = $this->createMock( ManagedReleaseStore::class );
		$store->expects( self::once() )->method( 'configuration' )->with( 'plugin', 'example/example.php' )->willReturn( $release );
		$package  = new BlueprintPackage( 'plugin', 'example/example.php', 'Different display name', 'gh', 'repository-id', 'owner/repository', '', null, $release );
		$reviewer = new BlueprintReviewer( $plugins, $this->createStub( ThemeRepository::class ), $store );
		self::assertSame( TargetPackageReason::UNSUPPORTED_BLUEPRINT_OPERATION, $reviewer->review_package( $package )->reason );
		$report = $reviewer->compare_local( $package );
		self::assertSame( 'matching', $report['state'] );
		self::assertSame( array(), $report['differences'] );
		self::assertSame( $report['requested'], $report['current'] );
		self::assertSame( 'unknown', $report['installed_equality'] );
	}

	#[DataProvider( 'release_configuration_states' )]
	public function test_release_configuration_is_not_guessed( string $mode, string $state ): void {
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'is_installed' )->willReturn( true );
		$plugins->method( 'has_management_record' )->willReturn( true );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $this->managed_package( 'repository-id', PackageSource::RELEASE_ASSET ) );
		$store = $this->createStub( ManagedReleaseStore::class );
		if ( 'malformed' === $mode ) {
			$store->method( 'configuration' )->willThrowException( new \RuntimeException( 'malformed' ) );
		} else {
			$store->method( 'configuration' )->willReturn( 'missing' === $mode ? null : new ManagedReleaseConfiguration( 'example', 'example.php' ) );
		}
		$report = ( new BlueprintReviewer( $plugins, $this->createStub( ThemeRepository::class ), $store ) )->compare_local( $this->blueprint_package() );
		self::assertSame( $state, $report['state'] );
		self::assertSame( 'different' === $state ? array( 'source' ) : array(), $report['differences'] );
		if ( 'unavailable' === $state ) {
			self::assertNull( $report['current'] );
		}
	}

	/** @return iterable<string,array{string,string}> */
	public static function release_configuration_states(): iterable {
		yield 'release versus branch' => array( 'valid', 'different' );
		yield 'missing configuration' => array( 'missing', 'unavailable' );
		yield 'malformed configuration' => array( 'malformed', 'unavailable' );
	}

	public function test_selection_and_observed_version_do_not_claim_installed_equality(): void {
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'is_installed' )->willReturn( true );
		$plugins->method( 'has_management_record' )->willReturn( true );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $this->managed_package( 'repository-id' ) );
		$record                 = $this->blueprint_package()->to_array();
		$record['selection']    = array( 'commit' => str_repeat( 'a', 40 ) );
		$record['requirements'] = array(
			'basis'             => 'installed_headers',
			'observed_identity' => array( 'version' => '1.0.0' ),
			'declarations'      => array(),
		);
		$report                 = ( new BlueprintReviewer( $plugins, $this->createStub( ThemeRepository::class ) ) )->compare_local( BlueprintPackage::from_array( $record ) );
		self::assertSame( 'matching', $report['state'] );
		self::assertSame( 'unknown', $report['installed_equality'] );
		self::assertSame( $record['selection'], $report['requested_selection'] );
		self::assertArrayNotHasKey( 'selection', $report['requested'] );
		self::assertArrayNotHasKey( 'requirements', $report['requested'] );
	}

	public function test_theme_comparison_reads_fresh_local_management(): void {
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->expects( self::never() )->method( 'is_installed' );
		$themes = $this->createMock( ThemeRepository::class );
		$themes->expects( self::exactly( 2 ) )->method( 'is_installed' )->with( 'example' )->willReturn( false, true );
		$themes->expects( self::exactly( 2 ) )->method( 'has_management_record' )->with( 'example' )->willReturn( false );
		$package  = new BlueprintPackage( 'theme', 'example', 'Example', 'gh', 'repository-id', 'owner/repository', 'main', null );
		$reviewer = new BlueprintReviewer( $plugins, $themes );
		$absent   = $reviewer->compare_local( $package );
		self::assertSame( 'absent', $absent['state'] );
		self::assertNull( $absent['requested_selection'] );
		self::assertSame( 'unmanaged', $reviewer->compare_local( $package )['state'] );
	}

	public function test_installed_header_observations_do_not_block_ordinary_branch_review(): void {
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->expects( self::once() )->method( 'is_installed' )->willReturn( false );
		$plugins->expects( self::once() )->method( 'has_management_record' )->willReturn( false );
		$record                 = $this->blueprint_package()->to_array();
		$record['requirements'] = array(
			'basis'             => 'installed_headers',
			'observed_identity' => array( 'version' => '1.0.0' ),
			'declarations'      => array( 'requires_php' => '99.0' ),
		);
		$reviewer               = new BlueprintReviewer( $plugins, $this->createStub( ThemeRepository::class ) );
		$item                   = $reviewer->review_package( BlueprintPackage::from_array( $record ) );
		self::assertSame( TargetPackageAction::INSTALL, $item->action );
		self::assertSame( TargetPackageReason::NONE, $item->reason );
		$record['selection'] = array( 'commit' => str_repeat( 'a', 40 ) );
		self::assertSame( TargetPackageReason::UNSUPPORTED_BLUEPRINT_OPERATION, $reviewer->review_package( BlueprintPackage::from_array( $record ) )->reason );
	}

	#[DataProvider( 'selected_repository_groups' )]
	public function test_selected_repository_conflicts_use_existing_source_exclusivity(
		bool $first_release,
		bool $second_release,
		string $second_provider,
		string $second_id,
		string $second_locator,
		bool $conflict
	): void {
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->expects( self::never() )->method( 'is_installed' );
		$plugins->expects( self::never() )->method( 'has_management_record' );
		$themes = $this->createMock( ThemeRepository::class );
		$themes->expects( self::never() )->method( 'is_installed' );
		$themes->expects( self::never() )->method( 'has_management_record' );
		$packages = array(
			new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/repository', $first_release ? '' : 'main', $first_release ? null : 'plugins/example', $first_release ? new ManagedReleaseConfiguration( 'example', 'example.php' ) : null ),
			new BlueprintPackage( 'theme', 'theme', 'Theme', $second_provider, $second_id, $second_locator, $second_release ? '' : 'main', $second_release ? null : 'themes/theme', $second_release ? new ManagedReleaseConfiguration( 'theme', 'style.css' ) : null ),
		);
		$reviewer = new BlueprintReviewer( $plugins, $themes );
		self::assertSame(
			$conflict ? array(
				0 => true,
				1 => true,
			) : array(),
			$reviewer->repository_conflicts( new PackageBlueprint( $packages ) )
		);
		self::assertSame( array(), $reviewer->repository_conflicts( new PackageBlueprint( array( $packages[0] ) ) ) );
		self::assertSame( array(), $reviewer->repository_conflicts( new PackageBlueprint( array( $packages[1] ) ) ) );
	}

	/** @return iterable<string,array{bool,bool,string,string,string,bool}> */
	public static function selected_repository_groups(): iterable {
		yield 'multiple branch subdirectories remain legal' => array( false, false, 'gh', 'repository-id', 'owner/repository', false );
		yield 'branch then release conflicts' => array( false, true, 'gh', 'repository-id', 'owner/repository', true );
		yield 'release then branch conflicts' => array( true, false, 'gh', 'repository-id', 'owner/repository', true );
		yield 'two releases conflict' => array( true, true, 'gh', 'repository-id', 'owner/repository', true );
		yield 'provider identity is scoped' => array( true, true, 'bb', 'repository-id', 'owner/repository', false );
		yield 'stable identity survives locator rename' => array( false, true, 'gh', 'repository-id', 'renamed/repository', true );
		yield 'different stable IDs are not silently unified by locator' => array( false, true, 'gh', 'different-id', 'owner/repository', false );
		yield 'stable IDs are case sensitive' => array( false, true, 'gh', 'Repository-id', 'owner/repository', false );
	}

	public function test_maximum_selected_set_reports_only_conflicting_groups_and_recomputes_after_subset_change(): void {
		$packages = array();
		for ( $index = 0; $index < PackageBlueprint::MAX_PACKAGES; ++$index ) {
			$slug       = 'package-' . $index;
			$release    = 127 === $index;
			$packages[] = new BlueprintPackage( 'plugin', $slug . '/plugin.php', $slug, 'gh', 126 <= $index ? 'shared-id' : 'repository-' . $index, 'owner/' . $slug, $release ? '' : 'main', null, $release ? new ManagedReleaseConfiguration( $slug, 'plugin.php' ) : null );
		}
		$reviewer = new BlueprintReviewer( $this->createStub( PluginRepository::class ), $this->createStub( ThemeRepository::class ) );
		self::assertSame(
			array(
				126 => true,
				127 => true,
			),
			$reviewer->repository_conflicts( new PackageBlueprint( $packages ) )
		);
		array_pop( $packages );
		self::assertSame( array(), $reviewer->repository_conflicts( new PackageBlueprint( $packages ) ) );
		self::assertSame( array(), $reviewer->repository_conflicts( new PackageBlueprint( array() ) ) );
	}

	private function blueprint_package(): BlueprintPackage {
		return new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/repository', 'main', null );
	}

	private function managed_package( string $provider_repository_id, PackageSource $source = PackageSource::BRANCH ): Package {
		$package = $this->createStub( Package::class );
		$package->method( 'get_source' )->willReturn( $source );
		$package->method( 'get_identifier' )->willReturn( 'example/example.php' );
		$package->method( 'get_display_name' )->willReturn( 'Example' );
		$package->method( 'get_provider_code' )->willReturn( 'gh' );
		$package->method( 'get_provider_repository_id' )->willReturn( $provider_repository_id );
		$package->method( 'get_repository' )->willReturn( new ManagedRepository( 'gh', 'owner/repository', $provider_repository_id, 'main' ) );
		$package->method( 'get_branch' )->willReturn( 'main' );
		$package->method( 'get_subdirectory' )->willReturn( null );

		return $package;
	}
}
