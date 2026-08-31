<?php

declare(strict_types=1);

namespace Tests\Storage;

use RAN\AbstractPackage;
use RAN\Deployment\DeploymentPolicy;
use RAN\ManagedRepository;
use RAN\Package;
use RAN\PackageSource;
use RAN\Storage\AbstractPackageRepository;
use RAN\Storage\Database;
use RAN\Storage\PackageMutationResult;
use RAN\Storage\PackageMutationStatus;
use RAN\Storage\PackageStorageFailure;
use RuntimeException;
use Tests\RANBoosterTestCase;
use Throwable;

require_once __DIR__ . '/StorageTestEnvironment.php';

final class PackageProviderIdentityTest extends RANBoosterTestCase {

	protected function setUp(): void {
		global $wpdb;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused WordPress database test double.
		$wpdb = new StorageTestWpdb();
	}

	public function testProviderIdentityRoundTripsThroughInsertAndHydration(): void {
		global $wpdb;

		$storage       = $this->storage();
		$package       = $this->package( 'example/example.php' );
		$opaqueLocator = 'RocketsAreNostalgic/%2Fexample<tag>';
		$repository    = new ManagedRepository( 'gh', $opaqueLocator, '000123456789', 'release', false, 'credential-one' );
		$package->setRepository( $repository );
		$package->setDeploymentPolicy( DeploymentPolicy::AUTOMATIC );

		self::assertSame( PackageMutationStatus::CHANGED, $storage->storeForTest( $package )->getStatus() );
		self::assertSame( 'gh', $wpdb->inserts[0][1]['provider'] );
		self::assertSame( 'branch', $wpdb->inserts[0][1]['source'] );
		self::assertSame( 1, $wpdb->inserts[0][1]['source_revision'] );
		self::assertArrayNotHasKey( 'host', $wpdb->inserts[0][1] );
		self::assertSame( $opaqueLocator, $wpdb->inserts[0][1]['repository'] );
		self::assertSame( '000123456789', $wpdb->inserts[0][1]['provider_repository_id'], 'Stable IDs must remain opaque strings.' );

		$opaqueProviderId = '000%2F{opaque-repository}-value';
		$package->setRepository( new ManagedRepository( 'gh', $opaqueLocator, $opaqueProviderId, 'release', false, 'credential-one' ) );

		self::assertSame( PackageMutationStatus::CHANGED, $storage->storeForTest( $package )->getStatus() );
		self::assertSame( 'gh', $wpdb->updates[0][1]['provider'] );
		self::assertSame( 'branch', $wpdb->updates[0][1]['source'] );
		self::assertSame( 2, $wpdb->updates[0][1]['source_revision'] );
		self::assertSame( 'branch', $wpdb->updates[0][2]['source'] );
		self::assertSame( 1, $wpdb->updates[0][2]['source_revision'] );
		self::assertArrayNotHasKey( 'host', $wpdb->updates[0][1] );
		self::assertSame( $opaqueProviderId, $wpdb->updates[0][1]['provider_repository_id'] );

		$wpdb->row = (object) $wpdb->rows[0];
		$hydrated  = $storage->findForTest( 'example/example.php' );

		self::assertInstanceOf( ManagedRepository::class, $hydrated->getRepository() );
		self::assertSame( 'gh', $hydrated->getProviderCode() );
		self::assertSame( $opaqueProviderId, $hydrated->getProviderRepositoryId() );
		self::assertSame( $opaqueLocator, (string) $hydrated->getRepository() );
		self::assertSame( 'credential-one', $hydrated->getCredentialId() );
		self::assertSame( 'release', $hydrated->getBranch() );
		self::assertSame( PackageSource::BRANCH, $hydrated->getSource() );
		self::assertSame( 2, $hydrated->getSourceRevision() );
	}

	public function testReleaseSourceAndRevisionHydrateWithoutBranchFallback(): void {
		global $wpdb;

		$wpdb->row = (object) array(
			'id'                     => 1,
			'package'                => 'example/example.php',
			'repository'             => 'owner/example',
			'branch'                 => 'main',
			'type'                   => 1,
			'deployment_policy'      => DeploymentPolicy::MANUAL->value,
			'source'                 => PackageSource::RELEASE_ASSET->value,
			'source_revision'        => '7',
			'provider'               => 'gh',
			'provider_repository_id' => 'repository-id',
			'private'                => 0,
			'credential_id'          => null,
			'subdirectory'           => null,
		);

		$package = $this->storage()->findForTest( 'example/example.php' );

		self::assertSame( PackageSource::RELEASE_ASSET, $package->getSource() );
		self::assertSame( 7, $package->getSourceRevision() );
	}

	public function testMalformedStoredSourceStateFailsClosed(): void {
		global $wpdb;

		$row = array(
			'id'                     => 1,
			'package'                => 'example/example.php',
			'repository'             => 'owner/example',
			'branch'                 => 'main',
			'type'                   => 1,
			'deployment_policy'      => DeploymentPolicy::MANUAL->value,
			'source'                 => PackageSource::BRANCH->value,
			'source_revision'        => 1,
			'provider'               => 'gh',
			'provider_repository_id' => 'repository-id',
			'private'                => 0,
			'credential_id'          => null,
			'subdirectory'           => null,
		);

		foreach (
			array(
				array( 'source' => 'tag' ),
				array( 'source_revision' => 0 ),
				array( 'source_revision' => '01' ),
			) as $invalid
		) {
			$wpdb->row = (object) array_merge( $row, $invalid );
			try {
				$this->storage()->findForTest( 'example/example.php' );
				self::fail( 'Expected malformed package source state to fail closed.' );
			} catch ( \InvalidArgumentException $failure ) {
				self::assertStringNotContainsString( (string) reset( $invalid ), $failure->getMessage() );
			}
		}
	}

	public function testOrdinaryStoreCannotOverwriteAReleaseManagedRow(): void {
		global $wpdb;

		$wpdb->rows[] = array(
			'id'                     => 1,
			'package'                => 'example/example.php',
			'repository'             => 'owner/example',
			'branch'                 => 'main',
			'type'                   => 1,
			'deployment_policy'      => DeploymentPolicy::MANUAL->value,
			'source'                 => PackageSource::RELEASE_ASSET->value,
			'source_revision'        => 4,
			'provider'               => 'gh',
			'provider_repository_id' => 'repository-id',
			'private'                => 0,
			'credential_id'          => null,
			'subdirectory'           => null,
		);

		$package = $this->package( 'example/example.php' );
		$package->setRepository( new ManagedRepository( 'gh', 'owner/example', 'repository-id', 'main' ) );

		$result = $this->storage()->storeForTest( $package );

		self::assertSame( PackageMutationStatus::CONFLICT, $result->getStatus() );
		self::assertSame( 'ran_booster_storage_source_conflict', $result->getDiagnosticId() );
		self::assertSame( array(), $wpdb->updates );
		self::assertSame( PackageSource::RELEASE_ASSET->value, $wpdb->rows[0]['source'] );
	}

	public function testReleaseManagedEditPreservesItsSourceWhileChangingAccessAndPolicy(): void {
		global $wpdb;

		$wpdb->rows[] = array(
			'id'                     => 1,
			'package'                => 'example/example.php',
			'type'                   => 1,
			'repository'             => 'owner/example',
			'branch'                 => 'stable',
			'provider'               => 'gh',
			'provider_repository_id' => 'repository-id',
			'private'                => 1,
			'credential_id'          => 'old-access',
			'subdirectory'           => null,
			'deployment_policy'      => DeploymentPolicy::MANUAL->value,
			'source'                 => PackageSource::RELEASE_ASSET->value,
			'source_revision'        => 4,
		);

		$result = $this->storage()->editForTest(
			'example/example.php',
			array(
				'repository'               => new ManagedRepository( 'gh', 'owner/example', 'repository-id', 'stable', true, 'new-access' ),
				'branch'                   => 'stable',
				'deployment_policy'        => DeploymentPolicy::AUTOMATIC->value,
				'subdirectory'             => null,
				'private'                  => true,
				'credential_id'            => 'new-access',
				'expected_source'          => PackageSource::RELEASE_ASSET->value,
				'expected_source_revision' => 4,
			)
		);

		self::assertSame( PackageMutationStatus::CHANGED, $result->getStatus() );
		self::assertSame( PackageSource::RELEASE_ASSET->value, $wpdb->updates[0][1]['source'] );
		self::assertSame( 5, $wpdb->updates[0][1]['source_revision'] );
		self::assertSame( PackageSource::RELEASE_ASSET->value, $wpdb->updates[0][2]['source'] );
		self::assertSame( 4, $wpdb->updates[0][2]['source_revision'] );
		self::assertSame( 'new-access', $wpdb->updates[0][1]['credential_id'] );
		self::assertSame( DeploymentPolicy::AUTOMATIC->value, $wpdb->updates[0][1]['deployment_policy'] );
		self::assertSame( 'owner/example', $wpdb->rows[0]['repository'] );
		self::assertSame( 'repository-id', $wpdb->rows[0]['provider_repository_id'] );
		self::assertSame( 'stable', $wpdb->rows[0]['branch'] );
		self::assertNull( $wpdb->rows[0]['subdirectory'] );
	}

	public function testLegacyReleaseManagedEditWithSubdirectoryDoesNotWrite(): void {
		global $wpdb;

		$wpdb->rows[] = array(
			'id'                     => 1,
			'package'                => 'example/example.php',
			'type'                   => 1,
			'repository'             => 'owner/example',
			'branch'                 => 'stable',
			'provider'               => 'gh',
			'provider_repository_id' => 'repository-id',
			'private'                => 1,
			'credential_id'          => 'old-access',
			'subdirectory'           => 'packages/example',
			'deployment_policy'      => DeploymentPolicy::MANUAL->value,
			'source'                 => PackageSource::RELEASE_ASSET->value,
			'source_revision'        => 4,
		);

		$result = $this->storage()->editForTest(
			'example/example.php',
			array(
				'repository'               => new ManagedRepository( 'gh', 'owner/example', 'repository-id', 'stable', true, 'new-access' ),
				'branch'                   => 'stable',
				'deployment_policy'        => DeploymentPolicy::AUTOMATIC->value,
				'subdirectory'             => 'packages/example',
				'private'                  => true,
				'credential_id'            => 'new-access',
				'expected_source'          => PackageSource::RELEASE_ASSET->value,
				'expected_source_revision' => 4,
			)
		);

		self::assertSame( PackageMutationStatus::CONFLICT, $result->getStatus() );
		self::assertSame( 'ran_booster_storage_source_conflict', $result->getDiagnosticId() );
		self::assertSame( array(), $wpdb->updates );
		self::assertSame( 'packages/example', $wpdb->rows[0]['subdirectory'] );
	}

	public function testLegacyReleaseManagedEditCannotSilentlyClearItsSubdirectory(): void {
		global $wpdb;

		$wpdb->rows[] = array(
			'id'                     => 1,
			'package'                => 'example/example.php',
			'type'                   => 1,
			'repository'             => 'owner/example',
			'branch'                 => 'stable',
			'provider'               => 'gh',
			'provider_repository_id' => 'repository-id',
			'private'                => 1,
			'credential_id'          => 'old-access',
			'subdirectory'           => 'packages/example',
			'deployment_policy'      => DeploymentPolicy::MANUAL->value,
			'source'                 => PackageSource::RELEASE_ASSET->value,
			'source_revision'        => 4,
		);

		$result = $this->storage()->editForTest(
			'example/example.php',
			array(
				'repository'               => new ManagedRepository( 'gh', 'owner/example', 'repository-id', 'stable', true, 'new-access' ),
				'branch'                   => 'stable',
				'deployment_policy'        => DeploymentPolicy::AUTOMATIC->value,
				'subdirectory'             => null,
				'private'                  => true,
				'credential_id'            => 'new-access',
				'expected_source'          => PackageSource::RELEASE_ASSET->value,
				'expected_source_revision' => 4,
			)
		);

		self::assertSame( PackageMutationStatus::CONFLICT, $result->getStatus() );
		self::assertSame( null, $wpdb->updates[0][2]['subdirectory'] );
		self::assertSame( 'packages/example', $wpdb->rows[0]['subdirectory'] );
	}

	public function testHydrationRetainsAnUnavailableProviderWithoutFallingBackToGitHub(): void {
		global $wpdb;

		$storage   = $this->storage();
		$wpdb->row = (object) array(
			'id'                     => 1,
			'package'                => 'example/example.php',
			'repository'             => 'group/subgroup/package',
			'branch'                 => 'main',
			'type'                   => 1,
			'deployment_policy'      => DeploymentPolicy::MANUAL->value,
			'source'                 => PackageSource::BRANCH->value,
			'source_revision'        => 1,
			'provider'               => 'external-offline',
			'provider_repository_id' => 'opaque-external-id',
			'private'                => 1,
			'credential_id'          => 'external-credential',
			'subdirectory'           => null,
		);

		$package = $storage->findForTest( 'example/example.php' );

		self::assertSame( 'external-offline', $package->getProviderCode() );
		self::assertSame( 'group/subgroup/package', (string) $package->getRepository() );
		self::assertSame( 'opaque-external-id', $package->getProviderRepositoryId() );
		self::assertSame( 'external-credential', $package->getCredentialId() );
		self::assertTrue( $package->isPrivate() );
	}

	public function testInvalidStoredProviderIdentityFailsWithASafeStorageError(): void {
		global $wpdb;

		$wpdb->row = (object) array(
			'package'                => 'example/example.php',
			'repository'             => 'owner/example',
			'branch'                 => 'main',
			'provider'               => 'INVALID',
			'provider_repository_id' => '',
			'private'                => 0,
			'credential_id'          => '',
			'deployment_policy'      => DeploymentPolicy::MANUAL->value,
			'source'                 => PackageSource::BRANCH->value,
			'source_revision'        => 1,
			'subdirectory'           => null,
		);

		try {
			$this->storage()->findForTest( 'example/example.php' );
			self::fail( 'Expected invalid stored provider identity to fail closed.' );
		} catch ( PackageStorageFailure $failure ) {
			self::assertSame( 'ran_booster_storage_invalid_provider_identity', $failure->getDiagnosticId() );
			self::assertStringNotContainsString( 'INVALID', $failure->getMessage() );
		}
	}

	public function testLegacyReleaseRowWithoutStableRepositoryIdFailsClosed(): void {
		global $wpdb;

		$wpdb->row = (object) array(
			'package'                => 'example/example.php',
			'repository'             => 'owner/example',
			'branch'                 => 'main',
			'provider'               => 'gh',
			'provider_repository_id' => '',
			'private'                => 0,
			'credential_id'          => '',
			'deployment_policy'      => DeploymentPolicy::MANUAL->value,
			'source'                 => PackageSource::RELEASE_ASSET->value,
			'source_revision'        => 1,
			'subdirectory'           => null,
		);

		try {
			$this->storage()->findForTest( 'example/example.php' );
			self::fail( 'Expected a legacy row without stable repository identity to fail closed.' );
		} catch ( PackageStorageFailure $failure ) {
			self::assertSame( 'ran_booster_storage_invalid_provider_identity', $failure->getDiagnosticId() );
			self::assertStringNotContainsString( 'owner/example', $failure->getMessage() );
		}
	}

	public function testManagedRepositoryNormalizesEmptyLocalSettings(): void {
		$repository = new ManagedRepository( 'external-offline', 'group/package', 'stable-id', '', false, '   ' );

		self::assertSame( 'main', $repository->branch );
		self::assertNull( $repository->reference->credentialId );
	}

	public function testEditPersistsExplicitProviderIdentityAndRejectsMissingIdentity(): void {
		global $wpdb;

		$storage      = $this->storage();
		$wpdb->rows[] = array(
			'package'                => 'example/example.php',
			'type'                   => 1,
			'provider'               => 'gh',
			'provider_repository_id' => 'old-id',
			'source'                 => 'branch',
			'source_revision'        => 1,
		);
		$repository   = new ManagedRepository( 'gh', 'RocketsAreNostalgic/replacement', '{new-opaque-id}', 'main' );

		$storage->editForTest(
			'example/example.php',
			array(
				'repository'               => $repository,
				'type'                     => 'bb',
				'branch'                   => 'main',
				'deployment_policy'        => DeploymentPolicy::MANUAL->value,
				'subdirectory'             => '',
				'private'                  => '0',
				'credential_id'            => '',
				'expected_source'          => 'branch',
				'expected_source_revision' => 1,
			)
		);

		self::assertSame( 'gh', $wpdb->updates[0][1]['provider'], 'The repository owns provider identity; the old type alias is ignored.' );
		self::assertArrayNotHasKey( 'host', $wpdb->updates[0][1] );
		self::assertSame( '{new-opaque-id}', $wpdb->updates[0][1]['provider_repository_id'] );
		self::assertSame( 2, $wpdb->updates[0][1]['source_revision'] );
		self::assertSame( 1, $wpdb->updates[0][2]['source_revision'] );

		$result = $storage->editForTest(
			'example/example.php',
			array(
				'repository'               => 'owner/manual',
				'type'                     => 'gh',
				'provider_repository_id'   => 'obsolete-alias-id',
				'branch'                   => 'main',
				'deployment_policy'        => DeploymentPolicy::MANUAL->value,
				'subdirectory'             => '',
				'private'                  => '0',
				'credential_id'            => '',
				'expected_source'          => 'branch',
				'expected_source_revision' => 2,
			)
		);

		self::assertSame( PackageMutationStatus::FAILED, $result->getStatus() );
		self::assertSame( 'ran_booster_storage_invalid_provider_identity', $result->getDiagnosticId() );
		self::assertCount( 1, $wpdb->updates );
	}

	private function storage(): AbstractPackageRepository {
		$lifecycle = new class() extends Database {
			public function requireReady(): void {
			}
		};

		return new class( $lifecycle ) extends AbstractPackageRepository {

			public function storeForTest( Package $package ): PackageMutationResult {
				return $this->storePackage( $package );
			}

			/** @param array<string, mixed> $input */
			public function editForTest( string $identifier, array $input ): PackageMutationResult {
				return $this->editPackage( $identifier, $input );
			}

			public function findForTest( string $identifier ): Package {
				return $this->managedPackage( $identifier );
			}

			protected function packageType(): int {
				return 1;
			}

			protected function packageExists( string $identifier ): bool {
				return true;
			}

			protected function packageFromInstallation( string $identifier ): Package {
				return new class( $identifier ) extends AbstractPackage {

					public function __construct( private readonly string $identifier ) {
					}

					public function getIdentifier(): mixed {
						return $this->identifier;
					}
				};
			}

			protected function notFoundException(): Throwable {
				return new RuntimeException( 'Package not found.' );
			}
		};
	}

	private function package( string $identifier ): Package {
		return new class( $identifier ) extends AbstractPackage {

			public function __construct( private readonly string $identifier ) {
			}

			public function getIdentifier(): mixed {
				return $this->identifier;
			}
		};
	}
}
