<?php

declare(strict_types=1);

namespace Tests\Portability;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\ManagedRepository;
use RAN\Package;
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
				$plugins->expects( self::once() )->method( 'booster_plugin_from_file' )->willReturn( $this->managed_package( $managed_repository_id ?? '' ) );
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

	private function blueprint_package(): BlueprintPackage {
		return new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/repository', 'main', null );
	}

	private function managed_package( string $provider_repository_id ): Package {
		$package = $this->createStub( Package::class );
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
