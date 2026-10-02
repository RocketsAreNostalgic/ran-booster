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
		global $ran_booster_storage_test_options, $wpdb;

		$ran_booster_storage_test_options = array();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused WordPress database test double.
		$wpdb = new StorageTestWpdb();
	}

	public function test_provider_identity_round_trips_through_insert_and_hydration(): void {
		global $wpdb;

		$storage        = $this->storage();
		$package        = $this->package( 'example/example.php' );
		$opaque_locator = 'RocketsAreNostalgic/%2Fexample<tag>';
		$repository     = new ManagedRepository( 'gh', $opaque_locator, '000123456789', 'release', false, 'credential-one' );
		$package->set_repository( $repository );
		$package->set_deployment_policy( DeploymentPolicy::AUTOMATIC );

		self::assertSame( PackageMutationStatus::CHANGED, $storage->store_for_test( $package )->get_status() );
		self::assertSame( 'gh', $wpdb->inserts[0][1]['provider'] );
		self::assertSame( 'branch', $wpdb->inserts[0][1]['source'] );
		self::assertSame( 1, $wpdb->inserts[0][1]['source_revision'] );
		self::assertArrayNotHasKey( 'host', $wpdb->inserts[0][1] );
		self::assertSame( $opaque_locator, $wpdb->inserts[0][1]['repository'] );
		self::assertSame( '000123456789', $wpdb->inserts[0][1]['provider_repository_id'], 'Stable IDs must remain opaque strings.' );

		$opaque_provider_id = '000%2F{opaque-repository}-value';
		$package->set_repository( new ManagedRepository( 'gh', $opaque_locator, $opaque_provider_id, 'release', false, 'credential-one' ) );

		self::assertSame( PackageMutationStatus::CHANGED, $storage->store_for_test( $package )->get_status() );
		self::assertSame( 'gh', $wpdb->updates[0][1]['provider'] );
		self::assertSame( 'branch', $wpdb->updates[0][1]['source'] );
		self::assertSame( 2, $wpdb->updates[0][1]['source_revision'] );
		self::assertSame( 'branch', $wpdb->updates[0][2]['source'] );
		self::assertSame( 1, $wpdb->updates[0][2]['source_revision'] );
		self::assertArrayNotHasKey( 'host', $wpdb->updates[0][1] );
		self::assertSame( $opaque_provider_id, $wpdb->updates[0][1]['provider_repository_id'] );

		$wpdb->row = (object) $wpdb->rows[0];
		$hydrated  = $storage->find_for_test( 'example/example.php' );

		self::assertInstanceOf( ManagedRepository::class, $hydrated->get_repository() );
		self::assertSame( 'gh', $hydrated->get_provider_code() );
		self::assertSame( $opaque_provider_id, $hydrated->get_provider_repository_id() );
		self::assertSame( $opaque_locator, (string) $hydrated->get_repository() );
		self::assertSame( 'credential-one', $hydrated->get_credential_id() );
		self::assertSame( 'release', $hydrated->get_branch() );
		self::assertSame( PackageSource::BRANCH, $hydrated->get_source() );
		self::assertSame( 2, $hydrated->get_source_revision() );
	}

	public function test_release_source_and_revision_hydrate_without_branch_fallback(): void {
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

		$package = $this->storage()->find_for_test( 'example/example.php' );

		self::assertSame( PackageSource::RELEASE_ASSET, $package->get_source() );
		self::assertSame( 7, $package->get_source_revision() );
	}

	public function test_malformed_stored_source_state_fails_closed(): void {
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
				$this->storage()->find_for_test( 'example/example.php' );
				self::fail( 'Expected malformed package source state to fail closed.' );
			} catch ( \InvalidArgumentException $failure ) {
				self::assertStringNotContainsString( (string) reset( $invalid ), $failure->getMessage() );
			}
		}
	}

	public function test_ordinary_store_cannot_overwrite_arelease_managed_row(): void {
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
		$package->set_repository( new ManagedRepository( 'gh', 'owner/example', 'repository-id', 'main' ) );

		$result = $this->storage()->store_for_test( $package );

		self::assertSame( PackageMutationStatus::CONFLICT, $result->get_status() );
		self::assertSame( 'ran_booster_storage_source_conflict', $result->get_diagnostic_id() );
		self::assertSame( array(), $wpdb->updates );
		self::assertSame( PackageSource::RELEASE_ASSET->value, $wpdb->rows[0]['source'] );
	}

	public function test_release_managed_edit_preserves_its_source_while_changing_access_and_policy(): void {
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

		$result = $this->storage()->edit_for_test(
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

		self::assertSame( PackageMutationStatus::CHANGED, $result->get_status() );
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

	public function test_release_managed_edit_accepts_legacy_empty_root_subdirectory(): void {
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
			'subdirectory'           => '',
			'deployment_policy'      => DeploymentPolicy::MANUAL->value,
			'source'                 => PackageSource::RELEASE_ASSET->value,
			'source_revision'        => 4,
		);

		$result = $this->storage()->edit_for_test(
			'example/example.php',
			array(
				'repository'               => new ManagedRepository( 'gh', 'owner/example', 'repository-id', 'stable', true, 'new-access' ),
				'deployment_policy'        => DeploymentPolicy::AUTOMATIC->value,
				'subdirectory'             => null,
				'expected_source'          => PackageSource::RELEASE_ASSET->value,
				'expected_source_revision' => 4,
			)
		);

		self::assertSame( PackageMutationStatus::CHANGED, $result->get_status() );
		self::assertSame( '', $wpdb->updates[0][2]['subdirectory'] );
		self::assertSame( PackageSource::RELEASE_ASSET->value, $wpdb->updates[0][2]['source'] );
		self::assertSame( 4, $wpdb->updates[0][2]['source_revision'] );
		self::assertNull( $wpdb->rows[0]['subdirectory'] );
	}

	public function test_legacy_release_managed_edit_with_subdirectory_does_not_write(): void {
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

		$result = $this->storage()->edit_for_test(
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

		self::assertSame( PackageMutationStatus::CONFLICT, $result->get_status() );
		self::assertSame( 'ran_booster_storage_source_conflict', $result->get_diagnostic_id() );
		self::assertSame( array(), $wpdb->updates );
		self::assertSame( 'packages/example', $wpdb->rows[0]['subdirectory'] );
	}

	public function test_legacy_release_managed_edit_cannot_silently_clear_its_subdirectory(): void {
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

		$result = $this->storage()->edit_for_test(
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

		self::assertSame( PackageMutationStatus::CONFLICT, $result->get_status() );
		self::assertSame( array(), $wpdb->updates );
		self::assertSame( 'packages/example', $wpdb->rows[0]['subdirectory'] );
	}

	public function test_hydration_retains_an_unavailable_provider_without_falling_back_to_git_hub(): void {
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

		$package = $storage->find_for_test( 'example/example.php' );

		self::assertSame( 'external-offline', $package->get_provider_code() );
		self::assertSame( 'group/subgroup/package', (string) $package->get_repository() );
		self::assertSame( 'opaque-external-id', $package->get_provider_repository_id() );
		self::assertSame( 'external-credential', $package->get_credential_id() );
		self::assertTrue( $package->is_private() );
	}

	public function test_invalid_stored_provider_identity_fails_with_asafe_storage_error(): void {
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
			$this->storage()->find_for_test( 'example/example.php' );
			self::fail( 'Expected invalid stored provider identity to fail closed.' );
		} catch ( PackageStorageFailure $failure ) {
			self::assertSame( 'ran_booster_storage_invalid_provider_identity', $failure->get_diagnostic_id() );
			self::assertStringNotContainsString( 'INVALID', $failure->getMessage() );
		}
	}

	public function test_legacy_release_row_without_stable_repository_id_fails_closed(): void {
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
			$this->storage()->find_for_test( 'example/example.php' );
			self::fail( 'Expected a legacy row without stable repository identity to fail closed.' );
		} catch ( PackageStorageFailure $failure ) {
			self::assertSame( 'ran_booster_storage_invalid_provider_identity', $failure->get_diagnostic_id() );
			self::assertStringNotContainsString( 'owner/example', $failure->getMessage() );
		}
	}

	public function test_managed_repository_normalizes_empty_local_settings(): void {
		$repository = new ManagedRepository( 'external-offline', 'group/package', 'stable-id', '', false, '   ' );

		self::assertSame( 'main', $repository->branch );
		self::assertNull( $repository->reference->credential_id );
	}

	public function test_edit_persists_explicit_provider_identity_and_rejects_missing_identity(): void {
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

		$storage->edit_for_test(
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

		$result = $storage->edit_for_test(
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

		self::assertSame( PackageMutationStatus::FAILED, $result->get_status() );
		self::assertSame( 'ran_booster_storage_invalid_provider_identity', $result->get_diagnostic_id() );
		self::assertCount( 1, $wpdb->updates );
	}

	private function storage(): AbstractPackageRepository {
		$lifecycle = new class() extends Database {
			public function require_ready(): void {
			}
		};

		return new class( $lifecycle ) extends AbstractPackageRepository {

			public function store_for_test( Package $package ): PackageMutationResult {
				return $this->store_package( $package );
			}

			/** @param array<string, mixed> $input */
			public function edit_for_test( string $identifier, array $input ): PackageMutationResult {
				return $this->edit_package( $identifier, $input );
			}

			public function find_for_test( string $identifier ): Package {
				return $this->managed_package( $identifier );
			}

			protected function package_type(): int {
				return 1;
			}

			protected function package_exists( string $identifier ): bool {
				return true;
			}

			protected function package_from_installation( string $identifier ): Package {
				return new class( $identifier ) extends AbstractPackage {

					public function __construct( private readonly string $identifier ) {
					}

					public function get_identifier(): mixed {
						return $this->identifier;
					}
				};
			}

			protected function not_found_exception(): Throwable {
				return new RuntimeException( 'Package not found.' );
			}
		};
	}

	private function package( string $identifier ): Package {
		return new class( $identifier ) extends AbstractPackage {

			public function __construct( private readonly string $identifier ) {
			}

			public function get_identifier(): mixed {
				return $this->identifier;
			}
		};
	}
}
