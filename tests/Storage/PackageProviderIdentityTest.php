<?php

declare(strict_types=1);

namespace RAN\Tests\Storage;

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
use RAN\Tests\RANBoosterTestCase;
use Throwable;

require_once __DIR__ . '/StorageTestEnvironment.php';

final class PackageProviderIdentityTest extends RANBoosterTestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
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

		$opaque_provider_id = '000123456789';
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

	public function test_stored_privacy_uses_the_same_canonical_values_as_package_writes(): void {
		global $wpdb;

		$storage = $this->storage();
		$package = $this->package( 'example/example.php' );
		$package->set_repository( new ManagedRepository( 'gh', 'owner/example', 'repository-id', 'main' ) );
		$storage->store_for_test( $package );
		$row = $wpdb->rows[0];

		foreach ( array( false, 0, '0', ' 0 ', true, 1, '1', ' 1 ' ) as $value ) {
			$wpdb->row = (object) array_replace( $row, array( 'private' => $value ) );
			self::assertSame( in_array( $value, array( true, 1, '1', ' 1 ' ), true ), $storage->find_for_test( 'example/example.php' )->is_private() );
		}

		foreach ( array( array( 'private' => 2 ), array( 'private' => '255' ), array( 'private' => null ), array() ) as $privacy ) {
			$without_privacy = $row;
			unset( $without_privacy['private'] );
			$wpdb->row = (object) array_merge( $without_privacy, $privacy );
			try {
				$storage->find_for_test( 'example/example.php' );
				self::fail( 'Invalid stored privacy must not hydrate a managed package.' );
			} catch ( PackageStorageFailure $failure ) {
				self::assertSame( 'ran_booster_storage_invalid_provider_identity', $failure->get_diagnostic_id() );
			}
		}
	}

	public function test_release_source_and_revision_hydrate_without_branch_fallback(): void {
		global $wpdb;

		$wpdb->row = (object) array(
			'id'                     => 1,
			'package'                => 'example/example.php',
			'repository'             => 'owner/example',
			'branch'                 => 'main',
			'package_type'           => 1,
			'deployment_policy'      => DeploymentPolicy::MANUAL->value,
			'source'                 => PackageSource::RELEASE_ASSET->value,
			'source_revision'        => '7',
			'provider'               => 'gh',
			'provider_repository_id' => 'repository-id',
			'adoption_token'         => 'gh:repository-id:12345678-1234-4234-8234-123456789abc',
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
			'package_type'           => 1,
			'deployment_policy'      => DeploymentPolicy::MANUAL->value,
			'source'                 => PackageSource::BRANCH->value,
			'source_revision'        => 1,
			'provider'               => 'gh',
			'provider_repository_id' => 'repository-id',
			'adoption_token'         => 'gh:repository-id:12345678-1234-4234-8234-123456789abc',
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
			'package_type'           => 1,
			'deployment_policy'      => DeploymentPolicy::MANUAL->value,
			'source'                 => PackageSource::RELEASE_ASSET->value,
			'source_revision'        => 4,
			'provider'               => 'gh',
			'provider_repository_id' => 'repository-id',
			'adoption_token'         => 'gh:repository-id:12345678-1234-4234-8234-123456789abc',
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
			'package_type'           => 1,
			'repository'             => 'owner/example',
			'branch'                 => 'stable',
			'provider'               => 'gh',
			'provider_repository_id' => 'repository-id',
			'adoption_token'         => 'gh:repository-id:12345678-1234-4234-8234-123456789abc',
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
				'expected_adoption_token'  => $wpdb->rows[0]['adoption_token'] ?? null,
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
			'package_type'           => 1,
			'repository'             => 'owner/example',
			'branch'                 => 'stable',
			'provider'               => 'gh',
			'provider_repository_id' => 'repository-id',
			'adoption_token'         => 'gh:repository-id:12345678-1234-4234-8234-123456789abc',
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
				'expected_adoption_token'  => $wpdb->rows[0]['adoption_token'] ?? null,
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
			'package_type'           => 1,
			'repository'             => 'owner/example',
			'branch'                 => 'stable',
			'provider'               => 'gh',
			'provider_repository_id' => 'repository-id',
			'adoption_token'         => 'gh:repository-id:12345678-1234-4234-8234-123456789abc',
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
				'expected_adoption_token'  => $wpdb->rows[0]['adoption_token'] ?? null,
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
			'package_type'           => 1,
			'repository'             => 'owner/example',
			'branch'                 => 'stable',
			'provider'               => 'gh',
			'provider_repository_id' => 'repository-id',
			'adoption_token'         => 'gh:repository-id:12345678-1234-4234-8234-123456789abc',
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
				'expected_adoption_token'  => $wpdb->rows[0]['adoption_token'] ?? null,
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
			'package_type'           => 1,
			'deployment_policy'      => DeploymentPolicy::MANUAL->value,
			'source'                 => PackageSource::BRANCH->value,
			'source_revision'        => 1,
			'provider'               => 'external-offline',
			'provider_repository_id' => 'opaque-external-id',
			'adoption_token'         => 'external-offline:opaque-external-id:12345678-1234-4234-8234-123456789abc',
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
			'package_type'           => 1,
			'provider'               => 'gh',
			'provider_repository_id' => '{new-opaque-id}',
			'adoption_token'         => 'gh:%7Bnew-opaque-id%7D:12345678-1234-4234-8234-123456789abc',
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
				'expected_adoption_token'  => $wpdb->rows[0]['adoption_token'] ?? null,
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
				'expected_adoption_token'  => $wpdb->rows[0]['adoption_token'] ?? null,
			)
		);

		self::assertSame( PackageMutationStatus::FAILED, $result->get_status() );
		self::assertSame( 'ran_booster_storage_invalid_provider_identity', $result->get_diagnostic_id() );
		self::assertCount( 1, $wpdb->updates );
	}

	public function test_recovery_removes_only_confirmed_record_and_readoption_changes_lifetime(): void {
		global $wpdb;
		foreach ( array( 1, 2 ) as $type ) {
			foreach ( array( 'branch', 'release_asset' ) as $source ) {
				$storage = $this->storage( $type );
				$package = $this->package( 'example/example.php' );
				$package->set_repository( new ManagedRepository( 'gh', 'owner/example', '001:opaque', 'main' ) );
				$wpdb->rows = array();
				self::assertTrue( $storage->store_for_test( $package )->is_successful() );
				$token = \RAN\AdoptionIdentity::of( $package );
				self::assertIsString( $token );
				$wpdb->rows[0]['source']          = $source;
				$wpdb->rows[0]['private']         = 'corrupt';
				$wpdb->rows[0]['source_revision'] = 'corrupt';
				$wpdb->rows[]                     = array(
					'id'             => 2,
					'package_type'   => $type,
					'package'        => 'unrelated',
					'adoption_token' => 'historical-untouched',
				);
				$before                           = $storage->recovery_record( 'example/example.php' );
				$guarded                          = false;
				$result                           = $storage->recover_unlink(
					'example/example.php',
					$token,
					$before['digest'],
					static function () use ( &$guarded, $wpdb ): void {
						$guarded = true;
						self::assertSame( 'START TRANSACTION', $wpdb->queries[ array_key_last( $wpdb->queries ) ] );
					}
				);
				self::assertTrue( $guarded );
				self::assertTrue( $result->is_successful() );
				self::assertCount( 1, $wpdb->stored_rows() );
				self::assertSame( 'historical-untouched', $wpdb->stored_rows()[0]['adoption_token'] );
				$replacement = $this->package( 'example/example.php' );
				$replacement->set_repository( new ManagedRepository( 'gh', 'owner/example', '001:opaque', 'main' ) );
				self::assertTrue( $storage->store_for_test( $replacement )->is_successful() );
				self::assertNotSame( $token, \RAN\AdoptionIdentity::of( $replacement ) );
			}
		}
	}

	public function test_recovery_rejects_changed_confirmation_and_guard_failure_without_deletion(): void {
		global $wpdb;
		$storage = $this->storage();
		$package = $this->package( 'example/example.php' );
		$package->set_repository( new ManagedRepository( 'gh', 'owner/example', '123', 'main' ) );
		self::assertTrue( $storage->store_for_test( $package )->is_successful() );
		$snapshot                = $storage->recovery_record( 'example/example.php' );
		$wpdb->rows[0]['branch'] = 'changed';
		$result                  = $storage->recover_unlink(
			'example/example.php',
			$snapshot['adoption_token'],
			$snapshot['digest'],
			static function (): void {
				self::fail( 'A stale confirmation must not reach the guard.' );
			}
		);
		self::assertFalse( $result->is_successful() );
		self::assertCount( 1, $wpdb->stored_rows() );
		$snapshot = $storage->recovery_record( 'example/example.php' );
		try {
			$storage->recover_unlink(
				'example/example.php',
				$snapshot['adoption_token'],
				$snapshot['digest'],
				static function (): void {
					throw new RuntimeException( 'unresolved work' );
				}
			);
			self::fail( 'Unresolved work must abort deletion.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'unresolved work', $failure->getMessage() );
		}
		self::assertCount( 1, $wpdb->stored_rows() );
		self::assertSame( 'ROLLBACK', $wpdb->queries[ array_key_last( $wpdb->queries ) ] );
	}

	public function test_recovery_refuses_unknown_or_malformed_token_and_identity_disagreement(): void {
		global $wpdb;
		$storage = $this->storage();
		$package = $this->package( 'example/example.php' );
		$package->set_repository( new ManagedRepository( 'gh', 'owner/example', '123', 'main' ) );
		self::assertTrue( $storage->store_for_test( $package )->is_successful() );
		foreach ( array( null, '', 'gh:123:bad', \RAN\AdoptionIdentity::create( 'gh', '456' ) ) as $token ) {
			$wpdb->rows[0]['adoption_token'] = $token;
			try {
				$storage->recovery_record( 'example/example.php' );
				self::fail( 'Invalid lifetime must be explicitly refused.' );
			} catch ( PackageStorageFailure $failure ) {
				self::assertSame( 'ran_booster_storage_invalid_provider_identity', $failure->get_diagnostic_id() );
			}
			self::assertSame( $token, $wpdb->stored_rows()[0]['adoption_token'] );
		}
	}

	public function test_repository_replacement_cannot_edit_or_store_with_old_lifetime(): void {
		global $wpdb;
		$storage = $this->storage();
		$package = $this->package( 'example/example.php' );
		$package->set_repository( new ManagedRepository( 'gh', 'owner/example', '123', 'main' ) );
		self::assertTrue( $storage->store_for_test( $package )->is_successful() );
		$token = \RAN\AdoptionIdentity::of( $package );
		$package->set_repository( new ManagedRepository( 'gh', 'owner/replacement', '456', 'main' ) );
		self::assertFalse( $storage->store_for_test( $package )->is_successful() );
		$result = $storage->edit_for_test(
			'example/example.php',
			array(
				'repository'               => $package->get_repository(),
				'subdirectory'             => null,
				'expected_source'          => 'branch',
				'expected_source_revision' => 1,
				'expected_adoption_token'  => $token,
			)
		);
		self::assertFalse( $result->is_successful() );
		self::assertSame( '123', $wpdb->stored_rows()[0]['provider_repository_id'] );
		self::assertSame( $token, $wpdb->stored_rows()[0]['adoption_token'] );
	}

	public function test_recovery_delete_and_readback_failures_rollback_without_success(): void {
		global $wpdb;
		foreach ( array( 'delete', 'readback', 'commit' ) as $failure ) {
			$storage = $this->storage();
			$package = $this->package( 'example/example.php' );
			$package->set_repository( new ManagedRepository( 'gh', 'owner/example', '123', 'main' ) );
			$wpdb->rows                            = array();
			$wpdb->delete_result                   = null;
			$wpdb->query_failure_contains          = null;
			$wpdb->successful_reads_before_failure = null;
			self::assertTrue( $storage->store_for_test( $package )->is_successful() );
			$snapshot = $storage->recovery_record( 'example/example.php' );
			if ( 'delete' === $failure ) {
				$wpdb->delete_result = false;
			} elseif ( 'readback' === $failure ) {
				$wpdb->successful_reads_before_failure = 1;
			} else {
				$wpdb->query_failure_contains = 'COMMIT';
			}
			$result = $storage->recover_unlink( 'example/example.php', $snapshot['adoption_token'], $snapshot['digest'], static function (): void {} );
			self::assertFalse( $result->is_successful() );
			self::assertCount( 1, $wpdb->stored_rows() );
			self::assertSame( $snapshot['adoption_token'], $wpdb->stored_rows()[0]['adoption_token'] );
			self::assertSame( 'ROLLBACK', $wpdb->queries[ array_key_last( $wpdb->queries ) ] );
			$wpdb->last_error = '';
		}
	}

	public function test_recovery_rejects_collation_alias_and_duplicate_rows_without_interpreting_corruption(): void {
		global $wpdb;
		$storage = $this->storage();
		$package = $this->package( 'example/example.php' );
		$package->set_repository( new ManagedRepository( 'gh', 'owner/example', '123', 'main' ) );
		self::assertTrue( $storage->store_for_test( $package )->is_successful() );
		foreach ( array( 'case', 'duplicate' ) as $failure ) {
			$row = (object) $wpdb->rows[0];
			if ( 'case' === $failure ) {
				$row->package         = 'Example/example.php';
				$wpdb->forced_results = array( $row );
			} else {
				$wpdb->forced_results = array( $row, clone $row );
			}
			try {
				$storage->recovery_record( 'example/example.php' );
				self::fail( 'An inexact target must not receive recovery confirmation.' );
			} catch ( PackageStorageFailure $error ) {
				self::assertSame( 'ran_booster_storage_duplicate_package', $error->get_diagnostic_id() );
			}
		}
		self::assertCount( 1, $wpdb->stored_rows() );
	}

	public function test_unhealthy_schema_refuses_recovery_before_guard_or_deletion(): void {
		global $wpdb;
		( new Database() )->install();
		$storage = new ProviderIdentityTestRepository( new Database() );
		$package = $this->package( 'example/example.php' );
		$package->set_repository( new ManagedRepository( 'gh', 'owner/example', '123', 'main' ) );
		self::assertTrue( $storage->store_for_test( $package )->is_successful() );
		$snapshot = $storage->recovery_record( 'example/example.php' );
		unset( $wpdb->schema_tables[ ran_booster_table_name() ]['indexes']['package_type'] );
		foreach ( array( false, true ) as $delete ) {
			try {
				if ( $delete ) {
					$storage->recover_unlink(
						'example/example.php',
						$snapshot['adoption_token'],
						$snapshot['digest'],
						static function (): void {
							self::fail( 'Unhealthy storage must never reach the recovery guard.' );
						}
					);
				} else {
					$storage->recovery_record( 'example/example.php' );
				}
				self::fail( 'The unhealthy uniqueness contract must fail closed.' );
			} catch ( PackageStorageFailure $failure ) {
				self::assertTrue( $failure->is_database_unsupported() );
			}
		}
		self::assertCount( 1, $wpdb->stored_rows() );
		self::assertArrayNotHasKey( 'package_type', $wpdb->schema_tables[ ran_booster_table_name() ]['indexes'] );
	}

	private function storage( int $type = 1 ): ProviderIdentityTestRepository {
		$lifecycle = new class() extends Database {
			public function require_ready(): void {
			}

			public function require_recovery_ready(): void {
			}
		};

		return new ProviderIdentityTestRepository( $lifecycle, $type );
	}

	private function package( string $identifier ): Package {
		return new class( $identifier ) extends AbstractPackage {

			public function __construct( private readonly string $identifier = '' ) {
			}

			public function get_identifier(): mixed {
				return $this->identifier;
			}
		};
	}
}

/** @extends AbstractPackageRepository<Package> */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the only tests that exercise it.
final class ProviderIdentityTestRepository extends AbstractPackageRepository {

	public function __construct( Database $database, private int $type = 1 ) {
		parent::__construct( $database );
	}

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
		return $this->type;
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of package_exists retains the production method contract; these inputs do not affect this controlled result.
	protected function package_exists( string $identifier ): bool {
		return true;
	}

	protected function package_from_installation( string $identifier ): Package {
		return new class( $identifier ) extends AbstractPackage {

			public function __construct( private readonly string $identifier = '' ) {
			}

			public function get_identifier(): mixed {
				return $this->identifier;
			}
		};
	}

	protected function not_found_exception(): Throwable {
		return new RuntimeException( 'Package not found.' );
	}
}
