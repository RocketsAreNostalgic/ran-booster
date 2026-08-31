<?php

declare(strict_types=1);

namespace RAN\Storage;

use InvalidArgumentException;
use RAN\Deployment\DeploymentPolicy;
use RAN\ManagedRepository;
use RAN\Package;
use RAN\PackageSubdirectory;
use RAN\PackageSource;
use RAN\Runtime\RuntimeSupport;
use RAN\WordPress\ManagedReleaseConfiguration;
use Throwable;

abstract class AbstractPackageRepository {

	private ?Database $databaseLifecycle = null;

	public function __construct( ?Database $databaseLifecycle = null ) {
		$this->databaseLifecycle = $databaseLifecycle;
	}

	/**
	 * Return all installed packages managed by Booster, keyed by their identifier.
	 *
	 * @return array<string, Package>
	 */
	protected function allPackages( ?PackageSource $source = null ): array {
		$rows     = $this->packageRows( null, $source );
		$packages = array();

		foreach ( $rows as $row ) {
			$identifier = $this->stringFromRow( $row, 'package' );

			if ( ! $this->packageExists( $identifier ) ) {
				continue;
			}

			$packages[ $identifier ] = $this->hydratePackage( $row );
		}

		return $packages;
	}

	public function unlink( mixed $identifier ): PackageMutationResult {
		RuntimeSupport::assertManagedOperationsAllowed();

		global $wpdb;

		$this->requireStorageSupport( PackageStorageOperation::DELETE );
		$model  = new PackageModel( array( 'package' => $identifier ) );
		$result = $wpdb->delete(
			ran_booster_table_name(),
			array(
				'package' => $model->package,
				'type'    => $this->packageType(),
			)
		);

		return $this->verifyPackageDeletion( $model->package, $result );
	}

	/**
	 * Whether Booster has any management row for this package identity.
	 *
	 * This intentionally does not hydrate or clean the row. Callers that need
	 * the managed package must still use their type-specific reader so malformed
	 * and duplicate records remain distinguishable.
	 */
	public function hasManagementRecord( mixed $identifier ): bool {
		$model = new PackageModel( array( 'package' => $identifier ) );

		return array() !== $this->packageRows( $model->package );
	}

	/**
	 * Atomically fence every stale package command before destructive removal.
	 */
	protected function disablePackageForRemoval( Package $package ): PackageMutationResult {
		RuntimeSupport::assertManagedOperationsAllowed();

		global $wpdb;

		$this->requireStorageSupport( PackageStorageOperation::UPDATE );
		$revision = $package->getSourceRevision();
		if ( PHP_INT_MAX === $revision ) {
			return $this->sourceConflictResult();
		}

		$model  = new PackageModel( array( 'package' => (string) $package->getIdentifier() ) );
		$data   = array(
			'deployment_policy' => DeploymentPolicy::DISABLED->value,
			'source_revision'   => $revision + 1,
		);
		$result = $wpdb->update(
			ran_booster_table_name(),
			$data,
			array(
				'package'           => $model->package,
				'type'              => $this->packageType(),
				'source'            => $package->getSource()->value,
				'source_revision'   => $revision,
				'deployment_policy' => $package->getDeploymentPolicy()->value,
			)
		);

		return $this->verifyPackageMutation(
			(string) $model->package,
			$data,
			$result,
			PackageStorageOperation::UPDATE
		);
	}

	/**
	 * Update the editable repository fields for one package.
	 *
	 * @param array<string, mixed> $input Sanitized command input.
	 */
	protected function editPackage( mixed $identifier, array $input ): PackageMutationResult {
		RuntimeSupport::assertManagedOperationsAllowed();

		global $wpdb;

		$this->requireStorageSupport( PackageStorageOperation::UPDATE );
		try {
			$expectedSource = new PackageModel(
				array(
					'source'          => $input['expected_source'] ?? null,
					'source_revision' => $input['expected_source_revision'] ?? null,
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->sourceConflictResult();
		}
		if ( ! in_array( $expectedSource->source, array( PackageSource::BRANCH->value, PackageSource::RELEASE_ASSET->value ), true )
			|| PHP_INT_MAX === $expectedSource->source_revision ) {
			return $this->sourceConflictResult();
		}

		$repository = $input['repository'] instanceof ManagedRepository ? $input['repository'] : null;
		if ( null === $repository ) {
			return $this->invalidProviderIdentityResult( PackageStorageOperation::UPDATE );
		}

		try {
			$model = new PackageModel(
				array(
					'package'                => $identifier,
					'repository'             => (string) $input['repository'],
					'branch'                 => $repository->branch,
					'deployment_policy'      => $input['deployment_policy'] ?? DeploymentPolicy::MANUAL->value,
					'subdirectory'           => $input['subdirectory'],
					'private'                => $repository->reference->private,
					'credential_id'          => $repository->reference->credentialId ?? '',
					'provider'               => $repository->provider->value,
					'provider_repository_id' => $repository->reference->providerRepositoryId,
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->invalidPackageIdentityResult( PackageStorageOperation::UPDATE );
		}
		if ( ! $this->packageExists( (string) $model->package ) ) {
			return $this->invalidPackageIdentityResult( PackageStorageOperation::UPDATE );
		}
		if ( PackageSource::RELEASE_ASSET->value === $expectedSource->source && null !== $model->subdirectory ) {
			return $this->sourceConflictResult();
		}
		$data = array(
			'repository'        => $model->repository,
			'branch'            => $model->branch,
			'deployment_policy' => $model->deployment_policy,
			'subdirectory'      => $model->subdirectory,
			'private'           => $model->private,
			'credential_id'     => $model->credential_id,
			'source'            => $expectedSource->source,
			'source_revision'   => $expectedSource->source_revision + 1,
		);

		$data['provider']               = $model->provider;
		$data['provider_repository_id'] = $model->provider_repository_id;

		$where = array(
			'package'         => $model->package,
			'type'            => $this->packageType(),
			'source'          => $expectedSource->source,
			'source_revision' => $expectedSource->source_revision,
		);
		if ( PackageSource::RELEASE_ASSET->value === $expectedSource->source ) {
			try {
				$rows = $this->packageRows( $model->package );
				if ( 1 !== count( $rows )
					|| null !== PackageSubdirectory::normalize( $this->valueFromRow( $rows[0], 'subdirectory' ) ) ) {
					return $this->sourceConflictResult();
				}
			} catch ( InvalidArgumentException ) {
				return $this->sourceConflictResult();
			} catch ( PackageStorageFailure $failure ) {
				return $this->failureResult( $failure );
			}

			// Preserve the stored root representation in the CAS predicate. Legacy
			// records may use either NULL or an empty string for the repository root.
			$where['subdirectory'] = $this->valueFromRow( $rows[0], 'subdirectory' );
		}
		if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' )
			|| false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $this->failureResult( PackageStorageFailure::transactionUnavailable() );
		}
		try {
			$assessment = ( new RepositorySourceGuard( $wpdb, $this->databaseLifecycle ) )->assess(
				$model->provider,
				$model->provider_repository_id,
				$this->packageType(),
				$model->package,
				PackageSource::from( $expectedSource->source ),
				true
			);
			if ( ! $assessment['allowed'] ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->repositorySourceResult( $assessment, PackageStorageOperation::UPDATE );
			}
			$result   = $wpdb->update( ran_booster_table_name(), $data, $where );
			$verified = $this->verifyPackageMutation( $model->package, $data, $result, PackageStorageOperation::UPDATE );
			if ( ! $verified->isSuccessful() ) {
				$wpdb->query( 'ROLLBACK' );
				return $verified;
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->postWriteVerificationFailure( PackageStorageOperation::UPDATE );
			}

			return $verified;
		} catch ( Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' );
			return $this->failureResult( PackageStorageFailure::queryFailed() );
		}
	}

	/**
	 * Atomically change only the deployment policy for a validated package set.
	 *
	 * @param list<array<string, mixed>> $snapshots Expected stored package rows.
	 * @return array{selected: int, changed: int, unchanged: int}
	 */
	protected function setDeploymentPolicies( array $snapshots, DeploymentPolicy $policy ): array {
		RuntimeSupport::assertManagedOperationsAllowed();

		global $wpdb;

		$this->requireStorageSupport( PackageStorageOperation::UPDATE );
		if ( array() === $snapshots || count( $snapshots ) > 20 ) {
			throw new InvalidArgumentException( 'The bulk package selection is invalid.' );
		}

		$normalized = array();
		foreach ( $snapshots as $snapshot ) {
			$identifier = is_string( $snapshot['package'] ?? null ) ? $snapshot['package'] : '';
			$model      = new PackageModel( array( 'package' => $identifier ) );
			if ( '' === (string) $model->package || isset( $normalized[ (string) $model->package ] ) ) {
				throw new InvalidArgumentException( 'The bulk package selection is invalid.' );
			}
			$snapshot['package']                    = (string) $model->package;
			$snapshot['deployment_policy']          = DeploymentPolicy::fromDatabase(
				is_string( $snapshot['deployment_policy'] ?? null )
					? $snapshot['deployment_policy']
					: ''
			)->value;
			$source                                 = new PackageModel(
				array(
					'source'          => $snapshot['source'] ?? null,
					'source_revision' => $snapshot['source_revision'] ?? null,
				)
			);
			$snapshot['source']                     = $source->source;
			$snapshot['source_revision']            = $source->source_revision;
			$normalized[ (string) $model->package ] = $snapshot;
		}
		ksort( $normalized, SORT_STRING );

		if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' )
			|| false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw PackageStorageFailure::transactionUnavailable();
		}

		try {
			$changed = 0;
			foreach ( $normalized as $identifier => $snapshot ) {
				$rows = $this->lockedPackageRows( $identifier );
				if ( 1 !== count( $rows ) || ! $this->rowMatches( $rows[0], $snapshot ) ) {
					throw PackageStorageFailure::duplicatePackageRows();
				}
				try {
					$subdirectory = PackageSubdirectory::normalize( $rows[0]->subdirectory ?? null );
				} catch ( InvalidArgumentException ) {
					throw PackageStorageFailure::writeFailed();
				}
				if ( PackageSource::RELEASE_ASSET->value === ( $rows[0]->source ?? null )
					&& null !== $subdirectory ) {
					throw PackageStorageFailure::writeFailed();
				}

				if ( $policy->value === (string) ( $rows[0]->deployment_policy ?? '' ) ) {
					continue;
				}

				$result = $wpdb->update(
					ran_booster_table_name(),
					array( 'deployment_policy' => $policy->value ),
					array(
						'package' => $identifier,
						'type'    => $this->packageType(),
					)
				);
				if ( 1 !== $result ) {
					throw PackageStorageFailure::writeFailed();
				}
				++$changed;
			}

			foreach ( array_keys( $normalized ) as $identifier ) {
				$rows = $this->lockedPackageRows( $identifier );
				if ( 1 !== count( $rows ) || $policy->value !== (string) ( $rows[0]->deployment_policy ?? '' ) ) {
					throw PackageStorageFailure::afterWriteCouldNotBeVerified( PackageStorageOperation::UPDATE );
				}
			}

			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw PackageStorageFailure::afterWriteCouldNotBeVerified( PackageStorageOperation::UPDATE );
			}

			return array(
				'selected'  => count( $normalized ),
				'changed'   => $changed,
				'unchanged' => count( $normalized ) - $changed,
			);
		} catch ( Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' );
			throw $exception;
		}
	}

	/**
	 * Load one managed package or throw the adapter's package-specific exception.
	 *
	 * @throws Throwable When the managed package cannot be found.
	 */
	protected function managedPackage( mixed $identifier ): Package {
		$model = new PackageModel( array( 'package' => $identifier ) );
		$rows  = $this->packageRows( $model->package );

		if ( count( $rows ) > 1 ) {
			throw PackageStorageFailure::duplicatePackageRows();
		}

		$row = $rows[0] ?? null;

		if ( ! is_object( $row ) || ! $this->packageExists( $this->stringFromRow( $row, 'package' ) ) ) {
			throw $this->notFoundException();
		}

		return $this->hydratePackage( $row );
	}

	protected function storePackage( Package $package ): PackageMutationResult {
		RuntimeSupport::assertManagedOperationsAllowed();

		global $wpdb;

		try {
			[$model, $data] = $this->packageRecord( $package );
		} catch ( InvalidArgumentException ) {
			return $this->invalidPackageIdentityResult( PackageStorageOperation::INSERT );
		}
		$tableName = ran_booster_table_name();
		$where     = array(
			'package' => $model->package,
			'type'    => $this->packageType(),
		);
		if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' )
			|| false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $this->failureResult( PackageStorageFailure::transactionUnavailable() );
		}
		try {
			$assessment = ( new RepositorySourceGuard( $wpdb, $this->databaseLifecycle ) )->assess(
				$model->provider,
				$model->provider_repository_id,
				$this->packageType(),
				$model->package,
				$package->getSource(),
				true
			);
			if ( ! $assessment['allowed'] ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->repositorySourceResult( $assessment, PackageStorageOperation::INSERT );
			}
			$existingRows = $this->packageRows( $model->package );
			if ( count( $existingRows ) > 1 ) {
				$wpdb->query( 'ROLLBACK' );
				return PackageMutationResult::conflict( PackageStorageOperation::QUERY, 'ran_booster_storage_duplicate_package', __( 'Booster found conflicting package management records. No package changes were made.', 'ran-booster' ) );
			}
			if ( array() !== $existingRows ) {
				$storedSource = $this->sourceModelFromRow( $existingRows[0] );
				if ( PackageSource::BRANCH->value !== $storedSource->source || PackageSource::BRANCH !== $package->getSource() || $storedSource->source_revision !== $package->getSourceRevision() || PHP_INT_MAX === $storedSource->source_revision ) {
					$wpdb->query( 'ROLLBACK' );
					return $this->sourceConflictResult();
				}
				$data['source']           = PackageSource::BRANCH->value;
				$data['source_revision']  = $storedSource->source_revision + 1;
				$where['source']          = PackageSource::BRANCH->value;
				$where['source_revision'] = $storedSource->source_revision;
				$result                   = $wpdb->update( $tableName, $data, $where );
				$verified                 = $this->verifyPackageMutation( $model->package, $data, $result, PackageStorageOperation::UPDATE );
			} else {
				$insertData = array_merge(
					array(
						'package' => $model->package,
						'type'    => $this->packageType(),
					),
					$data
				);
				$result     = $wpdb->insert( $tableName, $insertData );
				$verified   = $this->verifyPackageMutation( $model->package, $insertData, $result, PackageStorageOperation::INSERT );
			}
			if ( ! $verified->isSuccessful() ) {
				$wpdb->query( 'ROLLBACK' );
				return $verified;
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->postWriteVerificationFailure( $verified->getOperation() );
			}
			return $verified;
		} catch ( Throwable ) {
			$wpdb->query( 'ROLLBACK' );
			return $this->failureResult( PackageStorageFailure::queryFailed() );
		}
	}

	/** Store an installed package only when no management row exists yet. */
	protected function adoptPackage( Package $package ): PackageMutationResult {
		RuntimeSupport::assertManagedOperationsAllowed();

		global $wpdb;

		if ( PackageSource::BRANCH !== $package->getSource()
			|| 1 !== $package->getSourceRevision() ) {
			return $this->sourceConflictResult();
		}

		try {
			[$model, $data] = $this->packageRecord( $package );
		} catch ( InvalidArgumentException ) {
			return $this->invalidPackageIdentityResult( PackageStorageOperation::INSERT );
		}
		if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' )
			|| false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $this->failureResult( PackageStorageFailure::transactionUnavailable() );
		}
		try {
			$assessment = ( new RepositorySourceGuard( $wpdb, $this->databaseLifecycle ) )->assess(
				$model->provider,
				$model->provider_repository_id,
				$this->packageType(),
				$model->package,
				PackageSource::BRANCH,
				true
			);
			if ( ! $assessment['allowed'] ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->repositorySourceResult( $assessment, PackageStorageOperation::INSERT );
			}
			try {
				if ( array() !== $this->packageRows( $model->package ) ) {
					$wpdb->query( 'ROLLBACK' );
					return $this->adoptionConflict();
				}
			} catch ( PackageStorageFailure $failure ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->failureResult( $failure );
			}

			$insertData = array_merge(
				array(
					'package' => $model->package,
					'type'    => $this->packageType(),
				),
				$data
			);
			$result     = $wpdb->insert( ran_booster_table_name(), $insertData );
			if ( false === $result || 0 === $result ) {
				try {
					if ( array() !== $this->packageRows( $model->package ) ) {
						$wpdb->query( 'ROLLBACK' );
						return $this->adoptionConflict();
					}
				} catch ( PackageStorageFailure ) {
					$wpdb->query( 'ROLLBACK' );
					return $this->postWriteVerificationFailure( PackageStorageOperation::INSERT );
				}
				$wpdb->query( 'ROLLBACK' );
				return PackageMutationResult::failed(
					PackageStorageOperation::INSERT,
					'ran_booster_storage_write_failed',
					__( 'Booster could not save the package management record. No success was reported.', 'ran-booster' )
				);
			}
			$verified = $this->verifyPackageMutation( $model->package, $insertData, $result, PackageStorageOperation::INSERT );
			if ( ! $verified->isSuccessful() ) {
				$wpdb->query( 'ROLLBACK' );
				return $verified;
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->postWriteVerificationFailure( PackageStorageOperation::INSERT );
			}
			return $verified;
		} catch ( Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' );
			throw $exception;
		}
	}

	/**
	 * Atomically create the initial management row for a release-installed package.
	 */
	protected function adoptReleasePackage(
		Package $package,
		ManagedReleaseConfiguration $configuration,
		int $userId
	): PackageMutationResult {
		RuntimeSupport::assertManagedOperationsAllowed();

		global $wpdb;

		$identifier = (string) $package->getIdentifier();
		$expected   = 1 === $this->packageType()
			? $configuration->packageRoot() . '/' . $configuration->metadataFile()
			: $configuration->packageRoot();
		if ( PackageSource::RELEASE_ASSET !== $package->getSource()
			|| 1 !== $package->getSourceRevision()
			|| null !== $package->getSubdirectory()
			|| ! hash_equals( $expected, $identifier )
			|| $userId < 0 ) {
			return $this->sourceConflictResult();
		}

		try {
			[$model, $data] = $this->packageRecord( $package );
		} catch ( InvalidArgumentException ) {
			return $this->invalidPackageIdentityResult( PackageStorageOperation::INSERT );
		}
		if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' )
			|| false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $this->failureResult( PackageStorageFailure::transactionUnavailable() );
		}
		try {
			$assessment = ( new RepositorySourceGuard( $wpdb, $this->databaseLifecycle ) )->assess(
				$model->provider,
				$model->provider_repository_id,
				$this->packageType(),
				$model->package,
				PackageSource::RELEASE_ASSET,
				true
			);
			if ( ! $assessment['allowed'] ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->repositorySourceResult( $assessment, PackageStorageOperation::INSERT );
			}
			try {
				if ( array() !== $this->packageRows( $model->package ) ) {
					$wpdb->query( 'ROLLBACK' );
					return $this->adoptionConflict();
				}
			} catch ( PackageStorageFailure $failure ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->failureResult( $failure );
			}

			$insertData = array_merge(
				array(
					'package'               => $model->package,
					'type'                  => $this->packageType(),
					'source_previous'       => null,
					'source_changed_at'     => current_time( 'mysql', true ),
					'source_changed_by'     => $userId > 0 ? $userId : null,
					'release_configuration' => $configuration->toJson(),
				),
				$data
			);
			$result     = $wpdb->insert( ran_booster_table_name(), $insertData );
			if ( false === $result || 0 === $result ) {
				$wpdb->query( 'ROLLBACK' );
				try {
					if ( array() !== $this->packageRows( $model->package ) ) {
						return $this->adoptionConflict();
					}
				} catch ( PackageStorageFailure ) {
					return $this->postWriteVerificationFailure( PackageStorageOperation::INSERT );
				}

				return PackageMutationResult::failed(
					PackageStorageOperation::INSERT,
					'ran_booster_storage_write_failed',
					__( 'Booster could not save the release management record. The installed package state is uncertain.', 'ran-booster' )
				);
			}

			$verified = $this->verifyPackageMutation( $model->package, $insertData, $result, PackageStorageOperation::INSERT );
			if ( ! $verified->isSuccessful() ) {
				$wpdb->query( 'ROLLBACK' );
				return $verified;
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->postWriteVerificationFailure( PackageStorageOperation::INSERT );
			}
			return $verified;
		} catch ( Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' );
			throw $exception;
		}
	}

	/** @return array{0: PackageModel, 1: array<string, mixed>} */
	private function packageRecord( Package $package ): array {
		$repository = $package->getRepository();
		$model      = new PackageModel(
			array(
				'package'                => $package->getIdentifier(),
				'repository'             => (string) $repository,
				'branch'                 => $repository->branch,
				'provider'               => $repository->provider->value,
				'provider_repository_id' => $repository->reference->providerRepositoryId,
				'private'                => $repository->reference->private,
				'deployment_policy'      => $package->getDeploymentPolicy()->value,
				'source'                 => $package->getSource()->value,
				'source_revision'        => $package->getSourceRevision(),
				'subdirectory'           => $package->getSubdirectory(),
				'credential_id'          => $repository->reference->credentialId ?? '',
			)
		);
		if ( ! $this->packageExists( (string) $model->package ) ) {
			throw new InvalidArgumentException( 'The managed package identity is invalid.' );
		}
		$data = array(
			'repository'             => $model->repository,
			'branch'                 => $model->branch,
			'provider'               => $model->provider,
			'provider_repository_id' => $model->provider_repository_id,
			'private'                => $model->private,
			'deployment_policy'      => $model->deployment_policy,
			'source'                 => $model->source,
			'source_revision'        => $model->source_revision,
			'subdirectory'           => $model->subdirectory,
			'credential_id'          => $model->credential_id,
		);

		return array( $model, $data );
	}

	private function adoptionConflict(): PackageMutationResult {
		return PackageMutationResult::conflict(
			PackageStorageOperation::INSERT,
			'ran_booster_storage_adoption_conflict',
			__( 'Booster found existing package management data. No package changes were made.', 'ran-booster' )
		);
	}

	/**
	 * @param array{code: string} $assessment
	 */
	private function repositorySourceResult( array $assessment, PackageStorageOperation $operation ): PackageMutationResult {
		if ( 'repository_source_unavailable' === $assessment['code'] ) {
			return PackageMutationResult::failed(
				$operation,
				$assessment['code'],
				__( 'Booster could not determine the managed repository source state. No package changes were made.', 'ran-booster' )
			);
		}

		return PackageMutationResult::conflict(
			$operation,
			$assessment['code'],
			__( 'This repository already has incompatible managed package source records. No package changes were made.', 'ran-booster' )
		);
	}

	/**
	 * @return list<object>
	 */
	private function packageRows( ?string $identifier = null, ?PackageSource $source = null ): array {
		global $wpdb;

		$this->requireStorageSupport( PackageStorageOperation::QUERY );
		if ( null !== $identifier ) {
			$query = $wpdb->prepare(
				'SELECT * FROM %i WHERE type = %d AND package = %s',
				ran_booster_table_name(),
				$this->packageType(),
				$identifier
			);
		} elseif ( null !== $source ) {
			$query = $wpdb->prepare(
				'SELECT * FROM %i WHERE type = %d AND source = %s',
				ran_booster_table_name(),
				$this->packageType(),
				$source->value
			);
		} else {
			$query = $wpdb->prepare(
				'SELECT * FROM %i WHERE type = %d',
				ran_booster_table_name(),
				$this->packageType()
			);
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Every query variant is prepared immediately above.
		$rows  = $wpdb->get_results( $query );
		$error = property_exists( $wpdb, 'last_error' ) ? trim( (string) $wpdb->last_error ) : '';

		if ( '' !== $error || ! is_array( $rows ) || count( $rows ) !== count( array_filter( $rows, 'is_object' ) ) ) {
			throw PackageStorageFailure::queryFailed();
		}

		return array_values( $rows );
	}

	/**
	 * @return list<object>
	 */
	private function lockedPackageRows( string $identifier ): array {
		global $wpdb;

		$this->requireStorageSupport( PackageStorageOperation::QUERY );
		$query = $wpdb->prepare(
			'SELECT * FROM %i WHERE type = %d AND package = %s FOR UPDATE',
			ran_booster_table_name(),
			$this->packageType(),
			$identifier
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query is prepared immediately above.
		$rows  = $wpdb->get_results( $query );
		$error = property_exists( $wpdb, 'last_error' ) ? trim( (string) $wpdb->last_error ) : '';

		if ( '' !== $error || ! is_array( $rows ) || count( $rows ) !== count( array_filter( $rows, 'is_object' ) ) ) {
			throw PackageStorageFailure::queryFailed();
		}

		return array_values( $rows );
	}

	/**
	 * @param array<string, mixed> $expected Expected stored fields.
	 */
	private function verifyPackageMutation(
		string $identifier,
		array $expected,
		int|false $writeResult,
		PackageStorageOperation $operation
	): PackageMutationResult {
		if ( false === $writeResult ) {
			return PackageMutationResult::failed(
				$operation,
				'ran_booster_storage_write_failed',
				__( 'Booster could not save the package management record. No success was reported.', 'ran-booster' )
			);
		}

		try {
			$rows = $this->packageRows( $identifier );
		} catch ( PackageStorageFailure ) {
			return $this->postWriteVerificationFailure( $operation );
		}

		if ( 1 !== count( $rows ) || ! $this->rowMatches( $rows[0], $expected ) ) {
			return PackageMutationResult::conflict(
				$operation,
				'ran_booster_storage_verification_conflict',
				__( 'Booster could not verify the saved package management record. No success was reported.', 'ran-booster' )
			);
		}

		return 0 === $writeResult
			? PackageMutationResult::unchanged( $operation )
			: PackageMutationResult::changed( $operation );
	}

	private function verifyPackageDeletion( string $identifier, int|false $writeResult ): PackageMutationResult {
		if ( false === $writeResult ) {
			return $this->deleteFailureResult();
		}

		try {
			$rows = $this->packageRows( $identifier );
		} catch ( PackageStorageFailure ) {
			return $this->postWriteVerificationFailure( PackageStorageOperation::DELETE );
		}

		if ( array() !== $rows ) {
			return $this->deleteConflictResult();
		}

		return 0 === $writeResult
			? PackageMutationResult::unchanged( PackageStorageOperation::DELETE )
			: PackageMutationResult::changed( PackageStorageOperation::DELETE );
	}

	private function deleteFailureResult(): PackageMutationResult {
		return PackageMutationResult::failed(
			PackageStorageOperation::DELETE,
			'ran_booster_storage_delete_failed',
			__( 'Booster could not remove the package management record. No success was reported.', 'ran-booster' )
		);
	}

	private function deleteConflictResult(): PackageMutationResult {
		return PackageMutationResult::conflict(
			PackageStorageOperation::DELETE,
			'ran_booster_storage_delete_conflict',
			__( 'Booster could not verify removal of the package management record. No success was reported.', 'ran-booster' )
		);
	}

	private function failureResult( PackageStorageFailure $failure ): PackageMutationResult {
		return PackageMutationResult::failed(
			$failure->getOperation(),
			$failure->getDiagnosticId(),
			$failure->getMessage()
		);
	}

	private function postWriteVerificationFailure( PackageStorageOperation $operation ): PackageMutationResult {
		$failure = PackageStorageFailure::afterWriteCouldNotBeVerified( $operation );

		return PackageMutationResult::failed(
			$failure->getOperation(),
			$failure->getDiagnosticId(),
			$failure->getMessage(),
			true
		);
	}

	private function sourceConflictResult(): PackageMutationResult {
		return PackageMutationResult::conflict(
			PackageStorageOperation::UPDATE,
			'ran_booster_storage_source_conflict',
			__( 'The managed package source changed before this operation. No package changes were made.', 'ran-booster' )
		);
	}

	private function sourceModelFromRow( object $row ): PackageModel {
		return new PackageModel(
			array(
				'source'          => $this->valueFromRow( $row, 'source' ),
				'source_revision' => $this->valueFromRow( $row, 'source_revision' ),
			)
		);
	}

	private function invalidProviderIdentityResult( PackageStorageOperation $operation ): PackageMutationResult {
		return PackageMutationResult::failed(
			$operation,
			'ran_booster_storage_invalid_provider_identity',
			__( 'Booster could not save this package because its repository provider identity is incomplete.', 'ran-booster' )
		);
	}

	private function invalidPackageIdentityResult( PackageStorageOperation $operation ): PackageMutationResult {
		return PackageMutationResult::failed(
			$operation,
			'ran_booster_storage_invalid_package_identity',
			__( 'Booster could not save this package because its installed package identity is invalid.', 'ran-booster' )
		);
	}

	/**
	 * @param array<string, mixed> $expected Expected stored fields.
	 */
	private function rowMatches( object $row, array $expected ): bool {
		foreach ( $expected as $field => $value ) {
			$actual = $this->valueFromRow( $row, $field );

			if ( null === $value ) {
				if ( null !== $actual && '' !== $actual ) {
					return false;
				}
				continue;
			}

			if ( ! is_scalar( $actual ) || (string) $actual !== (string) $value ) {
				return false;
			}
		}

		return true;
	}

	private function hydratePackage( object $row ): Package {
		$identifier = $this->stringFromRow( $row, 'package' );
		$package    = $this->packageFromInstallation( $identifier );
		$provider   = $this->stringFromRow( $row, 'provider' );
		$handle     = $this->stringFromRow( $row, 'repository' );
		$credential = $this->stringFromRow( $row, 'credential_id' );
		try {
			$repository = new ManagedRepository(
				$provider,
				$handle,
				$this->stringFromRow( $row, 'provider_repository_id' ),
				$this->stringFromRow( $row, 'branch' ),
				(bool) $this->valueFromRow( $row, 'private', false ),
				'' === $credential ? null : $credential
			);
		} catch ( InvalidArgumentException ) {
			throw PackageStorageFailure::invalidProviderIdentity();
		}

		$package->setRepository( $repository );
		$package->setDeploymentPolicy(
			DeploymentPolicy::fromDatabase( $this->valueFromRow( $row, 'deployment_policy', DeploymentPolicy::MANUAL->value ) )
		);
		$source = new PackageModel(
			array(
				'source'          => $this->valueFromRow( $row, 'source' ),
				'source_revision' => $this->valueFromRow( $row, 'source_revision' ),
			)
		);
		$package->setSource( PackageSource::fromDatabase( $source->source ), $source->source_revision );
		$package->setSubdirectory( $this->valueFromRow( $row, 'subdirectory' ) );

		return $package;
	}

	private function stringFromRow( object $row, string $field ): string {
		return (string) $this->valueFromRow( $row, $field, '' );
	}

	private function valueFromRow( object $row, string $field, mixed $default = null ): mixed {
		return property_exists( $row, $field ) ? $row->$field : $default;
	}

	private function requireStorageSupport( PackageStorageOperation $operation ): void {
		$this->databaseLifecycle ??= new Database();
		try {
			$this->databaseLifecycle->requireReady();
		} catch ( DatabaseCompatibilityFailure | DatabaseLifecycleFailure ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The enum is converted into a display-safe typed storage failure.
			throw PackageStorageFailure::unsupportedDatabase( $operation );
		}
	}

	abstract protected function packageType(): int;

	abstract protected function packageExists( string $identifier ): bool;

	abstract protected function packageFromInstallation( string $identifier ): Package;

	abstract protected function notFoundException(): Throwable;
}
