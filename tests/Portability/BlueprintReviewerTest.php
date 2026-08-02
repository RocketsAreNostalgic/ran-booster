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

	#[DataProvider( 'localStates' )]
	public function testItClassifiesOnePackageFromCurrentLocalState(
		bool $installed,
		bool $managed,
		?string $managedRepositoryId,
		?string $failure,
		TargetPackageAction $action,
		TargetPackageReason $reason
	): void {
		$plugins = $this->createMock( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$package = $this->blueprintPackage();

		$plugins->expects( self::once() )->method( 'isInstalled' )->with( $package->identifier )->willReturn( $installed );
		$plugins->expects( self::once() )->method( 'hasManagementRecord' )->with( $package->identifier )->willReturn( $managed );
		if ( $installed && $managed ) {
			if ( null !== $failure ) {
				$plugins->expects( self::once() )->method( 'boosterPluginFromFile' )->willThrowException(
					'duplicate' === $failure ? PackageStorageFailure::duplicatePackageRows() : PackageStorageFailure::invalidProviderIdentity()
				);
			} else {
				$plugins->expects( self::once() )->method( 'boosterPluginFromFile' )->willReturn( $this->managedPackage( $managedRepositoryId ?? '' ) );
			}
		}

		$item = ( new BlueprintReviewer( $plugins, $themes ) )->review( new PackageBlueprint( array( $package ) ) )[0];

		self::assertSame( $action, $item->action );
		self::assertSame( $reason, $item->reason );
	}

	/** @return iterable<string, array{bool, bool, ?string, ?string, TargetPackageAction, TargetPackageReason}> */
	public static function localStates(): iterable {
		yield 'not installed and unmanaged installs' => array( false, false, null, null, TargetPackageAction::INSTALL, TargetPackageReason::NONE );
		yield 'installed and unmanaged adopts' => array( true, false, null, null, TargetPackageAction::ADOPT, TargetPackageReason::NONE );
		yield 'missing package with management record is stale' => array( false, true, null, null, TargetPackageAction::PROTECTED, TargetPackageReason::STALE_MANAGEMENT );
		yield 'matching management is already managed' => array( true, true, 'repository-id', null, TargetPackageAction::MANAGED, TargetPackageReason::ALREADY_MANAGED );
		yield 'different management is protected' => array( true, true, 'different-repository-id', null, TargetPackageAction::PROTECTED, TargetPackageReason::MANAGEMENT_CONFLICT );
		yield 'duplicate management rows are protected' => array( true, true, null, 'duplicate', TargetPackageAction::PROTECTED, TargetPackageReason::MANAGEMENT_CONFLICT );
		yield 'malformed management is protected' => array( true, true, null, 'malformed', TargetPackageAction::PROTECTED, TargetPackageReason::MALFORMED_MANAGEMENT );
	}

	public function testUnsupportedDatabaseIsNotReclassifiedAsBlueprintCorruption(): void {
		$plugins = $this->createMock( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$package = $this->blueprintPackage();
		$plugins->method( 'isInstalled' )->willReturn( true );
		$plugins->method( 'hasManagementRecord' )->willReturn( true );
		$plugins->method( 'boosterPluginFromFile' )->willThrowException( PackageStorageFailure::unsupportedDatabase() );

		$this->expectException( PackageStorageFailure::class );
		$this->expectExceptionMessage( 'database requirements' );

		( new BlueprintReviewer( $plugins, $themes ) )->review( new PackageBlueprint( array( $package ) ) );
	}

	private function blueprintPackage(): BlueprintPackage {
		return new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/repository', 'main', null );
	}

	private function managedPackage( string $providerRepositoryId ): Package {
		$package = $this->createStub( Package::class );
		$package->method( 'getIdentifier' )->willReturn( 'example/example.php' );
		$package->method( 'getDisplayName' )->willReturn( 'Example' );
		$package->method( 'getProviderCode' )->willReturn( 'gh' );
		$package->method( 'getProviderRepositoryId' )->willReturn( $providerRepositoryId );
		$package->method( 'getRepository' )->willReturn( new ManagedRepository( 'gh', 'owner/repository', $providerRepositoryId, 'main' ) );
		$package->method( 'getBranch' )->willReturn( 'main' );
		$package->method( 'getSubdirectory' )->willReturn( null );

		return $package;
	}
}
