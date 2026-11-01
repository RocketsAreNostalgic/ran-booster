<?php

declare(strict_types=1);

namespace RAN\Storage;

use InvalidArgumentException;
use RAN\AdoptionIdentity;
use RAN\AbstractPackage;
use RAN\Deployment\DeploymentPolicy;
use RAN\ManagedRepository;
use RAN\Package;
use RAN\PackageSubdirectory;
use RAN\PackageSource;
use RAN\Runtime\RuntimeSupport;
use RAN\Settings\GeneralPreferenceStore;
use RAN\WordPress\ManagedReleaseConfiguration;
use Throwable;

/** @template TPackage of Package */
abstract class AbstractPackageRepository {

	private ?Database $database_lifecycle = null;

	public function __construct( ?Database $database_lifecycle = null ) {
		$this->database_lifecycle = $database_lifecycle;
	}

	/**
	 * Observe recorded management rows without schema preparation or package hydration.
	 * The caller allocates one combined detail budget across plugin/theme readers.
	 *
	 * @return array{available:bool,records:list<array<string,int|string|null>>,truncated:bool}
	 * @phpstan-impure
	 */
	public function support_snapshot( int $limit = 100 ): array {
		$unavailable                = array(
			'available' => false,
			'records'   => array(),
			'truncated' => false,
		);
		$this->database_lifecycle ??= new Database();
		if ( $limit < 0 || $limit > 100 || ! $this->database_lifecycle->is_ready() ) {
			return $unavailable;
		}
		global $wpdb;
		$fields = array(
			'package'           => 255,
			'repository'        => 512,
			'branch'            => 255,
			'package_type'      => 3,
			'provider'          => 32,
			'deployment_policy' => 10,
			'source'            => 16,
			'private'           => 1,
		);
		$query  = $wpdb->prepare(
			'SELECT LEFT(package,255) AS package,LEFT(repository,512) AS repository,LEFT(branch,255) AS branch,LEFT(package_type,3) AS package_type,LEFT(provider,32) AS provider,LEFT(deployment_policy,10) AS deployment_policy,LEFT(source,16) AS source,LEFT(private,1) AS private FROM %i WHERE package_type = %d ORDER BY id LIMIT %d',
			ran_booster_table_name(),
			$this->package_type(),
			$limit + 1
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed columns above; table, type and limit are prepared at this boundary.
		$rows = $wpdb->get_results( $query );
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return $unavailable;
		}
		$records = array();
		foreach ( array_slice( $rows, 0, $limit ) as $row ) {
			if ( ! is_object( $row ) ) {
				return $unavailable;
			}
			$record = array();
			foreach ( $fields as $field => $bytes ) {
				$value = $row->$field ?? null;
				if ( ! is_int( $value ) && ! is_string( $value ) && null !== $value ) {
					return $unavailable;
				}
				$record[ $field ] = is_string( $value ) ? substr( $value, 0, $bytes ) : $value;
			}
			$records[] = $record;
		}
		return array(
			'available' => true,
			'records'   => $records,
			'truncated' => count( $rows ) > $limit,
		);
	}

	/**
	 * Return all installed packages managed by Booster, keyed by their identifier.
	 *
	 * @return array<string, TPackage>
	 */
	protected function all_packages( ?PackageSource $source = null ): array {
		$rows     = $this->package_rows( null, $source );
		$packages = array();

		foreach ( $rows as $row ) {
			$identifier = $this->string_from_row( $row, 'package' );

			if ( ! $this->package_exists( $identifier ) ) {
				continue;
			}

			$packages[ $identifier ] = $this->hydrate_package( $row );
		}

		return $packages;
	}

	/** @return array{id:string,package:string,type:int,adoption_token:string,source:string,digest:string} */
	public function recovery_record( string $identifier ): array {
		$this->require_recovery_storage();
		new PackageModel( array( 'package' => $identifier ) );
		return $this->recovery_snapshot( $identifier, $this->package_rows( $identifier ) );
	}

	/**
	 * Exact local recovery inventory; absence is distinct from unreadable or duplicate rows.
	 * Caller owns the selection transaction when requesting the locking form.
	 *
	 * @return array{id:string,package:string,type:int,adoption_token:string,source:string,digest:string}|null
	 */
	public function native_recovery_record( string $identifier, bool $for_update = false ): ?array {
		$this->require_recovery_storage();
		new PackageModel( array( 'package' => $identifier ) );
		global $wpdb;
		// A plugin directory can contain more than one main file. Never unlink an unreviewed sibling.
		$scope  = strtolower( 1 === $this->package_type() ? explode( '/', $identifier )[0] : $identifier );
		$query  = $wpdb->prepare(
			'SELECT p.*, p.package_type AS type FROM %i p WHERE package_type = %d AND (LOWER(package) = %s OR LOWER(package) LIKE %s) LIMIT 2',
			ran_booster_table_name(),
			$this->package_type(),
			$scope,
			$wpdb->esc_like( $scope . '/' ) . '%'
		);
		$query .= $for_update ? ' FOR UPDATE' : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared bounded directory scope; locking suffix is a closed boolean choice.
		$rows  = $wpdb->get_results( $query );
		$error = property_exists( $wpdb, 'last_error' ) ? trim( (string) $wpdb->last_error ) : '';
		if ( '' !== $error || ! is_array( $rows ) || count( $rows ) > 1 ) {
			throw PackageStorageFailure::query_failed();
		}
		if ( array() === $rows ) {
			return null;
		}
		$row = reset( $rows );
		if ( ! is_object( $row ) ) {
			throw PackageStorageFailure::query_failed();
		}
		return $this->recovery_snapshot( $identifier, array( $row ) );
	}

	/**
	 * The caller holds the updater lock; the guard checks installed identity and unresolved work.
	 *
	 * @param callable():void $guard Executed under the same row lock used by admission.
	 */
	public function recover_unlink( string $identifier, string $expected_token, string $expected_record_digest, callable $guard ): PackageMutationResult {
		RuntimeSupport::assert_managed_operations_allowed();
		global $wpdb;
		$this->require_recovery_storage();
		if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' ) || false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $this->failure_result( PackageStorageFailure::transaction_unavailable() );
		}
		try {
			$snapshot = $this->recovery_snapshot( $identifier, $this->locked_package_rows( $identifier ) );
			if ( $snapshot['adoption_token'] !== $expected_token || ! hash_equals( $snapshot['digest'], $expected_record_digest ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->delete_conflict_result();
			}
			$guard();
			$query = $wpdb->prepare( 'DELETE FROM %i WHERE id = %s AND package_type = %d AND BINARY package = BINARY %s AND BINARY adoption_token = BINARY %s', ran_booster_table_name(), $snapshot['id'], $this->package_type(), $identifier, $expected_token );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- All exact-record values are prepared above and the record remains locked.
			if ( 1 !== $wpdb->query( $query ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->delete_failure_result();
			}
			$verified = $this->verify_package_deletion( $identifier, 1 );
			if ( ! $verified->is_successful() || false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->post_write_verification_failure( PackageStorageOperation::DELETE );
			}
			return $verified;
		} catch ( Throwable $failure ) {
			$wpdb->query( 'ROLLBACK' );
			throw $failure;
		}
	}

	/**
	 * @param list<object> $rows Unhydrated authoritative rows.
	 * @return array{id:string,package:string,type:int,adoption_token:string,source:string,digest:string}
	 */
	private function recovery_snapshot( string $identifier, array $rows ): array {
		if ( 1 !== count( $rows ) ) {
			throw PackageStorageFailure::duplicate_package_rows();
		}
		$row   = $rows[0];
		$id    = $row->id ?? null;
		$token = $this->row_adoption_token( $row );
		if ( ( ! is_int( $id ) && ! is_string( $id ) ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/', (string) $id )
			|| ( $row->package ?? null ) !== $identifier
			|| ! in_array( $row->type ?? null, array( $this->package_type(), (string) $this->package_type() ), true ) ) {
			throw PackageStorageFailure::duplicate_package_rows();
		}
		$source = PackageSource::from_database( $row->source ?? null )->value;
		$values = get_object_vars( $row );
		ksort( $values, SORT_STRING );
		$digest = hash_init( 'sha256' );
		foreach ( $values as $field => $value ) {
			if ( ! is_string( $value ) && ! is_int( $value ) && null !== $value ) {
				throw PackageStorageFailure::query_failed();
			}
			$bytes = (string) $value;
			hash_update( $digest, strlen( $field ) . ':' . $field . ':' . gettype( $value ) . ':' . strlen( $bytes ) . ':' . $bytes );
		}
		return array(
			'id'             => (string) $id,
			'package'        => $identifier,
			'type'           => $this->package_type(),
			'adoption_token' => $token,
			'source'         => $source,
			'digest'         => hash_final( $digest ),
		);
	}

	private function require_recovery_storage(): void {
		$this->database_lifecycle ??= new Database();
		try {
			$this->database_lifecycle->require_recovery_ready();
		} catch ( DatabaseCompatibilityFailure | DatabaseLifecycleFailure ) {
			$failure = PackageStorageFailure::unsupported_database( PackageStorageOperation::DELETE );
			throw $failure;
		}
	}

	private function row_adoption_token( object $row ): string {
		$token    = $row->adoption_token ?? null;
		$provider = $row->provider ?? null;
		$id       = $row->provider_repository_id ?? null;
		if ( ! is_string( $token ) || ! is_string( $provider ) || ! is_string( $id ) || ! AdoptionIdentity::matches( $token, $provider, $id ) ) {
			throw PackageStorageFailure::invalid_provider_identity();
		}
		return $token;
	}

	/**
	 * Whether Booster has any management row for this package identity.
	 *
	 * This intentionally does not hydrate or clean the row. Callers that need
	 * the managed package must still use their type-specific reader so malformed
	 * and duplicate records remain distinguishable.
	 *
	 * @phpstan-impure
	 */
	public function has_management_record( mixed $identifier ): bool {
		$model = new PackageModel( array( 'package' => $identifier ) );

		return array() !== $this->package_rows( $model->package );
	}

	/**
	 * Atomically fence every stale package command before destructive removal.
	 */
	protected function disable_package_for_removal( Package $package ): PackageMutationResult {
		RuntimeSupport::assert_managed_operations_allowed();

		global $wpdb;

		$this->require_storage_support( PackageStorageOperation::UPDATE );
		$revision = $package->get_source_revision();
		$token    = AdoptionIdentity::of( $package );
		if ( null === $token || PHP_INT_MAX === $revision ) {
			return $this->source_conflict_result();
		}

		$model = new PackageModel( array( 'package' => (string) $package->get_identifier() ) );
		$data  = array(
			'deployment_policy' => DeploymentPolicy::DISABLED->value,
			'source_revision'   => $revision + 1,
		);
		if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' ) || false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $this->failure_result( PackageStorageFailure::transaction_unavailable() );
		}
		try {
			$rows = $this->locked_package_rows( (string) $model->package );
			if ( 1 !== count( $rows ) || ( $rows[0]->package ?? null ) !== $model->package || $this->row_adoption_token( $rows[0] ) !== $token ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->source_conflict_result();
			}
			$result = $wpdb->update(
				ran_booster_table_name(),
				$data,
				array(
					'package'           => $model->package,
					'package_type'      => $this->package_type(),
					'source'            => $package->get_source()->value,
					'source_revision'   => $revision,
					'deployment_policy' => $package->get_deployment_policy()->value,
					'adoption_token'    => $token,
				)
			);

			$verified = $this->verify_package_mutation(
				(string) $model->package,
				$data,
				$result,
				PackageStorageOperation::UPDATE
			);

			if ( ! $verified->is_successful() || false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $verified->is_successful() ? $this->post_write_verification_failure( PackageStorageOperation::UPDATE ) : $verified;
			}
			return $verified;
		} catch ( Throwable $failure ) {
			$wpdb->query( 'ROLLBACK' );
			throw $failure;
		}
	}

	/**
	 * Update the editable repository fields for one package.
	 *
	 * @param array<string, mixed> $input Sanitized command input.
	 */
	protected function edit_package( mixed $identifier, array $input ): PackageMutationResult {
		RuntimeSupport::assert_managed_operations_allowed();

		global $wpdb;

		$this->require_storage_support( PackageStorageOperation::UPDATE );
		try {
			$expected_source = new PackageModel(
				array(
					'source'          => $input['expected_source'] ?? null,
					'source_revision' => $input['expected_source_revision'] ?? null,
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->source_conflict_result();
		}
		if ( ! in_array( $expected_source->source, array( PackageSource::BRANCH->value, PackageSource::RELEASE_ASSET->value ), true )
			|| PHP_INT_MAX === $expected_source->source_revision ) {
			return $this->source_conflict_result();
		}

		$repository = $input['repository'] instanceof ManagedRepository ? $input['repository'] : null;
		if ( null === $repository ) {
			return $this->invalid_provider_identity_result( PackageStorageOperation::UPDATE );
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
					'credential_id'          => $repository->reference->credential_id ?? '',
					'provider'               => $repository->provider->value,
					'provider_repository_id' => $repository->reference->provider_repository_id,
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->invalid_package_identity_result( PackageStorageOperation::UPDATE );
		}
		// ManagedRepository supplies validated non-empty identities that PackageModel preserves.
		/** @var PackageModel<array{package: mixed, repository: string, branch: string, deployment_policy: mixed, subdirectory: mixed, private: bool, credential_id: string, provider: string, provider_repository_id: string|null}>&object{provider: non-empty-string, provider_repository_id: non-empty-string} $model */
		if ( ! $this->package_exists( (string) $model->package ) ) {
			return $this->invalid_package_identity_result( PackageStorageOperation::UPDATE );
		}
		if ( PackageSource::RELEASE_ASSET->value === $expected_source->source && null !== $model->subdirectory ) {
			return $this->source_conflict_result();
		}
		$data = array(
			'repository'        => $model->repository,
			'branch'            => $model->branch,
			'deployment_policy' => $model->deployment_policy,
			'subdirectory'      => $model->subdirectory,
			'private'           => $model->private,
			'credential_id'     => $model->credential_id,
			'source'            => $expected_source->source,
			'source_revision'   => $expected_source->source_revision + 1,
		);

		$data['provider']               = $model->provider;
		$data['provider_repository_id'] = $model->provider_repository_id;

		$where = array(
			'package'         => $model->package,
			'package_type'    => $this->package_type(),
			'source'          => $expected_source->source,
			'source_revision' => $expected_source->source_revision,
		);
		if ( PackageSource::RELEASE_ASSET->value === $expected_source->source ) {
			try {
				$rows = $this->package_rows( $model->package );
				if ( 1 !== count( $rows )
					|| null !== PackageSubdirectory::normalize( $this->value_from_row( $rows[0], 'subdirectory' ) ) ) {
					return $this->source_conflict_result();
				}
			} catch ( InvalidArgumentException ) {
				return $this->source_conflict_result();
			} catch ( PackageStorageFailure $failure ) {
				return $this->failure_result( $failure );
			}

			// Preserve the stored root representation in the CAS predicate. Legacy
			// records may use either NULL or an empty string for the repository root.
			$where['subdirectory'] = $this->value_from_row( $rows[0], 'subdirectory' );
		}
		if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' )
			|| false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $this->failure_result( PackageStorageFailure::transaction_unavailable() );
		}
		try {
			$rows  = $this->locked_package_rows( $model->package );
			$token = $input['expected_adoption_token'] ?? null;
			if ( 1 !== count( $rows ) || ! is_string( $token ) || $this->row_adoption_token( $rows[0] ) !== $token
				|| ( $rows[0]->package ?? null ) !== $model->package || ! AdoptionIdentity::matches( $token, $model->provider, $model->provider_repository_id ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->source_conflict_result();
			}
			$where['adoption_token'] = $token;
			$assessment              = ( new RepositorySourceGuard( $wpdb, $this->database_lifecycle ) )->assess(
				$model->provider,
				$model->provider_repository_id,
				$this->package_type(),
				$model->package,
				PackageSource::from( $expected_source->source ),
				true
			);
			if ( ! $assessment['allowed'] ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->repository_source_result( $assessment, PackageStorageOperation::UPDATE );
			}
			$result   = $wpdb->update( ran_booster_table_name(), $data, $where );
			$verified = $this->verify_package_mutation( $model->package, $data, $result, PackageStorageOperation::UPDATE );
			if ( ! $verified->is_successful() ) {
				$wpdb->query( 'ROLLBACK' );
				return $verified;
			}
			$this->clear_confirmed_github_hide_request( $model->provider );
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->post_write_verification_failure( PackageStorageOperation::UPDATE );
			}
			$this->clear_github_visibility_cache( $model->provider );

			return $verified;
		} catch ( Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' );
			return $this->failure_result( PackageStorageFailure::query_failed() );
		}
	}

	/**
	 * Atomically change only the deployment policy for a validated package set.
	 *
	 * @param list<array<string, mixed>> $snapshots Expected stored package rows.
	 * @return array{selected: int, changed: int, unchanged: int}
	 */
	protected function set_deployment_policies( array $snapshots, DeploymentPolicy $policy ): array {
		RuntimeSupport::assert_managed_operations_allowed();

		global $wpdb;

		$this->require_storage_support( PackageStorageOperation::UPDATE );
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
			AdoptionIdentity::parse( is_string( $snapshot['adoption_token'] ?? null ) ? $snapshot['adoption_token'] : '' );
			$snapshot['package']                    = (string) $model->package;
			$snapshot['deployment_policy']          = DeploymentPolicy::from_database(
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
			throw PackageStorageFailure::transaction_unavailable();
		}

		try {
			$changed = 0;
			foreach ( $normalized as $identifier => $snapshot ) {
				$rows = $this->locked_package_rows( $identifier );
				if ( 1 !== count( $rows ) || $this->row_adoption_token( $rows[0] ) !== $snapshot['adoption_token'] || ! $this->row_matches( $rows[0], $snapshot ) ) {
					throw PackageStorageFailure::duplicate_package_rows();
				}
				try {
					$subdirectory = PackageSubdirectory::normalize( $rows[0]->subdirectory ?? null );
				} catch ( InvalidArgumentException ) {
					throw PackageStorageFailure::write_failed();
				}
				if ( PackageSource::RELEASE_ASSET->value === ( $rows[0]->source ?? null )
					&& null !== $subdirectory ) {
					throw PackageStorageFailure::write_failed();
				}

				if ( (string) ( $rows[0]->deployment_policy ?? '' ) === $policy->value ) {
					continue;
				}

				$result = $wpdb->update(
					ran_booster_table_name(),
					array( 'deployment_policy' => $policy->value ),
					array(
						'package'      => $identifier,
						'package_type' => $this->package_type(),
					)
				);
				if ( 1 !== $result ) {
					throw PackageStorageFailure::write_failed();
				}
				++$changed;
			}

			foreach ( array_keys( $normalized ) as $identifier ) {
				$rows = $this->locked_package_rows( $identifier );
				if ( 1 !== count( $rows ) || (string) ( $rows[0]->deployment_policy ?? '' ) !== $policy->value ) {
					throw PackageStorageFailure::after_write_could_not_be_verified( PackageStorageOperation::UPDATE );
				}
			}

			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw PackageStorageFailure::after_write_could_not_be_verified( PackageStorageOperation::UPDATE );
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
	 * @return TPackage
	 * @throws Throwable When the managed package cannot be found.
	 */
	protected function managed_package( mixed $identifier ): Package {
		$model = new PackageModel( array( 'package' => $identifier ) );
		$rows  = $this->package_rows( $model->package );

		if ( count( $rows ) > 1 ) {
			throw PackageStorageFailure::duplicate_package_rows();
		}

		$row = $rows[0] ?? null;

		if ( ! is_object( $row ) || ( $row->package ?? null ) !== $model->package || ! $this->package_exists( $this->string_from_row( $row, 'package' ) ) ) {
			throw $this->not_found_exception();
		}

		return $this->hydrate_package( $row );
	}

	protected function store_package( Package $package ): PackageMutationResult {
		RuntimeSupport::assert_managed_operations_allowed();

		global $wpdb;

		$this->require_storage_support( PackageStorageOperation::INSERT );
		try {
			[$model, $data] = $this->package_record( $package );
		} catch ( InvalidArgumentException ) {
			return $this->invalid_package_identity_result( PackageStorageOperation::INSERT );
		}
		$table_name = ran_booster_table_name();
		$where      = array(
			'package'      => $model->package,
			'package_type' => $this->package_type(),
		);
		if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' )
			|| false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $this->failure_result( PackageStorageFailure::transaction_unavailable() );
		}
		try {
			$assessment = ( new RepositorySourceGuard( $wpdb, $this->database_lifecycle ) )->assess(
				$model->provider,
				$model->provider_repository_id,
				$this->package_type(),
				$model->package,
				$package->get_source(),
				true
			);
			if ( ! $assessment['allowed'] ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->repository_source_result( $assessment, PackageStorageOperation::INSERT );
			}
			$existing_rows = $this->locked_package_rows( $model->package );
			if ( count( $existing_rows ) > 1 ) {
				$wpdb->query( 'ROLLBACK' );
				return PackageMutationResult::conflict( PackageStorageOperation::QUERY, 'ran_booster_storage_duplicate_package', __( 'Booster found conflicting package management records. No package changes were made.', 'ran-booster' ) );
			}
			if ( array() !== $existing_rows ) {
				$token = $this->row_adoption_token( $existing_rows[0] );
				if ( AdoptionIdentity::of( $package ) !== $token || ( $existing_rows[0]->package ?? null ) !== $model->package || ! AdoptionIdentity::matches( $token, $model->provider, $model->provider_repository_id ) ) {
					$wpdb->query( 'ROLLBACK' );
					return $this->source_conflict_result();
				}
				$where['adoption_token'] = $token;
				$stored_source           = $this->source_model_from_row( $existing_rows[0] );
				if ( PackageSource::BRANCH->value !== $stored_source->source || PackageSource::BRANCH !== $package->get_source() || $stored_source->source_revision !== $package->get_source_revision() || PHP_INT_MAX === $stored_source->source_revision ) {
					$wpdb->query( 'ROLLBACK' );
					return $this->source_conflict_result();
				}
				$data['source']           = PackageSource::BRANCH->value;
				$data['source_revision']  = $stored_source->source_revision + 1;
				$where['source']          = PackageSource::BRANCH->value;
				$where['source_revision'] = $stored_source->source_revision;
				$result                   = $wpdb->update( $table_name, $data, $where );
				$verified                 = $this->verify_package_mutation( $model->package, $data, $result, PackageStorageOperation::UPDATE );
			} else {
				if ( null !== AdoptionIdentity::of( $package ) ) {
					$wpdb->query( 'ROLLBACK' );
					return $this->adoption_conflict();
				}
				$data['adoption_token'] = AdoptionIdentity::create( $model->provider, $model->provider_repository_id );
				$insert_data            = array_merge(
					array(
						'package'      => $model->package,
						'package_type' => $this->package_type(),
					),
					$data
				);
				$result                 = $wpdb->insert( $table_name, $insert_data );
				$verified               = $this->verify_package_mutation( $model->package, $insert_data, $result, PackageStorageOperation::INSERT );
			}
			if ( ! $verified->is_successful() ) {
				$wpdb->query( 'ROLLBACK' );
				return $verified;
			}
			$this->clear_confirmed_github_hide_request( $model->provider );
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->post_write_verification_failure( $verified->get_operation() );
			}
			$this->clear_github_visibility_cache( $model->provider );
			if ( $package instanceof AbstractPackage && is_string( $data['adoption_token'] ?? null ) ) {
				$package->set_adoption_token( $data['adoption_token'] );
			}
			return $verified;
		} catch ( Throwable ) {
			$wpdb->query( 'ROLLBACK' );
			return $this->failure_result( PackageStorageFailure::query_failed() );
		}
	}

	/** Store an installed package only when no management row exists yet. */
	protected function adopt_package( Package $package ): PackageMutationResult {
		RuntimeSupport::assert_managed_operations_allowed();

		global $wpdb;

		$this->require_storage_support( PackageStorageOperation::INSERT );
		if ( PackageSource::BRANCH !== $package->get_source()
			|| 1 !== $package->get_source_revision() ) {
			return $this->source_conflict_result();
		}

		try {
			[$model, $data] = $this->package_record( $package );
		} catch ( InvalidArgumentException ) {
			return $this->invalid_package_identity_result( PackageStorageOperation::INSERT );
		}
		if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' )
			|| false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $this->failure_result( PackageStorageFailure::transaction_unavailable() );
		}
		try {
			$assessment = ( new RepositorySourceGuard( $wpdb, $this->database_lifecycle ) )->assess(
				$model->provider,
				$model->provider_repository_id,
				$this->package_type(),
				$model->package,
				PackageSource::BRANCH,
				true
			);
			if ( ! $assessment['allowed'] ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->repository_source_result( $assessment, PackageStorageOperation::INSERT );
			}
			try {
				if ( array() !== $this->package_rows( $model->package ) ) {
					$wpdb->query( 'ROLLBACK' );
					return $this->adoption_conflict();
				}
			} catch ( PackageStorageFailure $failure ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->failure_result( $failure );
			}

			if ( null !== AdoptionIdentity::of( $package ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->adoption_conflict();
			}
			$data['adoption_token'] = AdoptionIdentity::create( $model->provider, $model->provider_repository_id );
			$insert_data            = array_merge(
				array(
					'package'      => $model->package,
					'package_type' => $this->package_type(),
				),
				$data
			);
			$result                 = $wpdb->insert( ran_booster_table_name(), $insert_data );
			if ( false === $result || 0 === $result ) {
				try {
					if ( array() !== $this->package_rows( $model->package ) ) {
						$wpdb->query( 'ROLLBACK' );
						return $this->adoption_conflict();
					}
				} catch ( PackageStorageFailure ) {
					$wpdb->query( 'ROLLBACK' );
					return $this->post_write_verification_failure( PackageStorageOperation::INSERT );
				}
				$wpdb->query( 'ROLLBACK' );
				return PackageMutationResult::failed(
					PackageStorageOperation::INSERT,
					'ran_booster_storage_write_failed',
					__( 'Booster could not save the package management record. No success was reported.', 'ran-booster' )
				);
			}
			$verified = $this->verify_package_mutation( $model->package, $insert_data, $result, PackageStorageOperation::INSERT );
			if ( ! $verified->is_successful() ) {
				$wpdb->query( 'ROLLBACK' );
				return $verified;
			}
			$this->clear_confirmed_github_hide_request( $model->provider );
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->post_write_verification_failure( PackageStorageOperation::INSERT );
			}
			$this->clear_github_visibility_cache( $model->provider );
			if ( $package instanceof AbstractPackage && is_string( $data['adoption_token'] ?? null ) ) {
				$package->set_adoption_token( $data['adoption_token'] );
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
	protected function adopt_release_package(
		Package $package,
		ManagedReleaseConfiguration $configuration,
		int $user_id
	): PackageMutationResult {
		RuntimeSupport::assert_managed_operations_allowed();

		global $wpdb;

		$identifier = (string) $package->get_identifier();
		$expected   = 1 === $this->package_type()
			? $configuration->package_root() . '/' . $configuration->metadata_file()
			: $configuration->package_root();
		if ( PackageSource::RELEASE_ASSET !== $package->get_source()
			|| 1 !== $package->get_source_revision()
			|| null !== $package->get_subdirectory()
			|| ! hash_equals( $expected, $identifier )
			|| $user_id < 0 ) {
			return $this->source_conflict_result();
		}

		try {
			[$model, $data] = $this->package_record( $package );
		} catch ( InvalidArgumentException ) {
			return $this->invalid_package_identity_result( PackageStorageOperation::INSERT );
		}
		if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' )
			|| false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $this->failure_result( PackageStorageFailure::transaction_unavailable() );
		}
		try {
			$assessment = ( new RepositorySourceGuard( $wpdb, $this->database_lifecycle ) )->assess(
				$model->provider,
				$model->provider_repository_id,
				$this->package_type(),
				$model->package,
				PackageSource::RELEASE_ASSET,
				true
			);
			if ( ! $assessment['allowed'] ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->repository_source_result( $assessment, PackageStorageOperation::INSERT );
			}
			try {
				if ( array() !== $this->package_rows( $model->package ) ) {
					$wpdb->query( 'ROLLBACK' );
					return $this->adoption_conflict();
				}
			} catch ( PackageStorageFailure $failure ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->failure_result( $failure );
			}

			if ( null !== AdoptionIdentity::of( $package ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->adoption_conflict();
			}
			$data['adoption_token'] = AdoptionIdentity::create( $model->provider, $model->provider_repository_id );
			$insert_data            = array_merge(
				array(
					'package'               => $model->package,
					'package_type'          => $this->package_type(),
					'source_previous'       => null,
					'source_changed_at'     => current_time( 'mysql', true ),
					'source_changed_by'     => $user_id > 0 ? $user_id : null,
					'release_configuration' => $configuration->to_json(),
				),
				$data
			);
			$result                 = $wpdb->insert( ran_booster_table_name(), $insert_data );
			if ( false === $result || 0 === $result ) {
				$wpdb->query( 'ROLLBACK' );
				try {
					if ( array() !== $this->package_rows( $model->package ) ) {
						return $this->adoption_conflict();
					}
				} catch ( PackageStorageFailure ) {
					return $this->post_write_verification_failure( PackageStorageOperation::INSERT );
				}

				return PackageMutationResult::failed(
					PackageStorageOperation::INSERT,
					'ran_booster_storage_write_failed',
					__( 'Booster could not save the release management record. The installed package state is uncertain.', 'ran-booster' )
				);
			}

			$verified = $this->verify_package_mutation( $model->package, $insert_data, $result, PackageStorageOperation::INSERT );
			if ( ! $verified->is_successful() ) {
				$wpdb->query( 'ROLLBACK' );
				return $verified;
			}
			$this->clear_confirmed_github_hide_request( $model->provider );
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->post_write_verification_failure( PackageStorageOperation::INSERT );
			}
			$this->clear_github_visibility_cache( $model->provider );
			if ( $package instanceof AbstractPackage && is_string( $data['adoption_token'] ?? null ) ) {
				$package->set_adoption_token( $data['adoption_token'] );
			}
			return $verified;
		} catch ( Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' );
			throw $exception;
		}
	}

	/**
	 * The fixed visibility preference is cleared only by a verified GitHub
	 * management write in this still-open transaction.
	 */
	private function clear_confirmed_github_hide_request( string $provider ): void {
		if ( 'gh' === $provider && ! ( new GeneralPreferenceStore() )->clear_confirmed_github_hide_request() ) {
			throw new \RuntimeException( 'GitHub visibility preference could not be cleared.' );
		}
	}

	private function clear_github_visibility_cache( string $provider ): void {
		if ( 'gh' !== $provider || ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}
		wp_cache_delete( GeneralPreferenceStore::OPTION_NAME, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/** @return array{0: PackageModel<array{package: mixed}>&object{provider: non-empty-string, provider_repository_id: non-empty-string}, 1: array<string, mixed>} */
	private function package_record( Package $package ): array {
		$repository = $package->get_repository();
		$model      = new PackageModel(
			array(
				'package'                => $package->get_identifier(),
				'repository'             => (string) $repository,
				'branch'                 => $repository->branch,
				'provider'               => $repository->provider->value,
				'provider_repository_id' => $repository->reference->provider_repository_id,
				'private'                => $repository->reference->private,
				'deployment_policy'      => $package->get_deployment_policy()->value,
				'source'                 => $package->get_source()->value,
				'source_revision'        => $package->get_source_revision(),
				'subdirectory'           => $package->get_subdirectory(),
				'credential_id'          => $repository->reference->credential_id ?? '',
			)
		);
		// ManagedRepository supplies validated non-empty identities that PackageModel preserves.
		/** @var PackageModel<array{package: mixed, repository: string, branch: string, provider: string, provider_repository_id: string|null, private: bool, deployment_policy: mixed, source: mixed, source_revision: int, subdirectory: mixed, credential_id: string}>&object{provider: non-empty-string, provider_repository_id: non-empty-string} $model */
		if ( ! $this->package_exists( (string) $model->package ) ) {
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

	private function adoption_conflict(): PackageMutationResult {
		return PackageMutationResult::conflict(
			PackageStorageOperation::INSERT,
			'ran_booster_storage_adoption_conflict',
			__( 'Booster found existing package management data. No package changes were made.', 'ran-booster' )
		);
	}

	/**
	 * @param array{code: string} $assessment
	 */
	private function repository_source_result( array $assessment, PackageStorageOperation $operation ): PackageMutationResult {
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
	 * Each call observes the current database state, including intervening writes.
	 *
	 * @return list<object>
	 * @phpstan-impure
	 */
	private function package_rows( ?string $identifier = null, ?PackageSource $source = null ): array {
		global $wpdb;

		$this->require_storage_support( PackageStorageOperation::QUERY );
		if ( null !== $identifier ) {
			$query = $wpdb->prepare(
				'SELECT p.*, p.package_type AS type FROM %i p WHERE package_type = %d AND package = %s',
				ran_booster_table_name(),
				$this->package_type(),
				$identifier
			);
		} elseif ( null !== $source ) {
			$query = $wpdb->prepare(
				'SELECT p.*, p.package_type AS type FROM %i p WHERE package_type = %d AND source = %s',
				ran_booster_table_name(),
				$this->package_type(),
				$source->value
			);
		} else {
			$query = $wpdb->prepare(
				'SELECT p.*, p.package_type AS type FROM %i p WHERE package_type = %d',
				ran_booster_table_name(),
				$this->package_type()
			);
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Every query variant is prepared immediately above.
		$rows  = $wpdb->get_results( $query );
		$error = property_exists( $wpdb, 'last_error' ) ? trim( (string) $wpdb->last_error ) : '';

		if ( '' !== $error || ! is_array( $rows ) ) {
			throw PackageStorageFailure::query_failed();
		}

		$validated = array();
		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) ) {
				throw PackageStorageFailure::query_failed();
			}
			$validated[] = $row;
		}

		return $validated;
	}

	/**
	 * @return list<object>
	 */
	private function locked_package_rows( string $identifier ): array {
		global $wpdb;

		$this->require_storage_support( PackageStorageOperation::QUERY );
		$query = $wpdb->prepare(
			'SELECT p.*, p.package_type AS type FROM %i p WHERE package_type = %d AND package = %s FOR UPDATE',
			ran_booster_table_name(),
			$this->package_type(),
			$identifier
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query is prepared immediately above.
		$rows  = $wpdb->get_results( $query );
		$error = property_exists( $wpdb, 'last_error' ) ? trim( (string) $wpdb->last_error ) : '';

		if ( '' !== $error || ! is_array( $rows ) ) {
			throw PackageStorageFailure::query_failed();
		}

		$validated = array();
		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) ) {
				throw PackageStorageFailure::query_failed();
			}
			$validated[] = $row;
		}

		return $validated;
	}

	/**
	 * @param array<string, mixed> $expected Expected stored fields.
	 */
	private function verify_package_mutation(
		string $identifier,
		array $expected,
		int|false $write_result,
		PackageStorageOperation $operation
	): PackageMutationResult {
		if ( false === $write_result ) {
			return PackageMutationResult::failed(
				$operation,
				'ran_booster_storage_write_failed',
				__( 'Booster could not save the package management record. No success was reported.', 'ran-booster' )
			);
		}

		try {
			$rows = $this->package_rows( $identifier );
		} catch ( PackageStorageFailure ) {
			return $this->post_write_verification_failure( $operation );
		}

		if ( 1 !== count( $rows ) || ! $this->row_matches( $rows[0], $expected ) ) {
			return PackageMutationResult::conflict(
				$operation,
				'ran_booster_storage_verification_conflict',
				__( 'Booster could not verify the saved package management record. No success was reported.', 'ran-booster' )
			);
		}

		return 0 === $write_result
			? PackageMutationResult::unchanged( $operation )
			: PackageMutationResult::changed( $operation );
	}

	private function verify_package_deletion( string $identifier, int|false $write_result ): PackageMutationResult {
		if ( false === $write_result ) {
			return $this->delete_failure_result();
		}

		try {
			$rows = $this->package_rows( $identifier );
		} catch ( PackageStorageFailure ) {
			return $this->post_write_verification_failure( PackageStorageOperation::DELETE );
		}

		if ( array() !== $rows ) {
			return $this->delete_conflict_result();
		}

		return 0 === $write_result
			? PackageMutationResult::unchanged( PackageStorageOperation::DELETE )
			: PackageMutationResult::changed( PackageStorageOperation::DELETE );
	}

	private function delete_failure_result(): PackageMutationResult {
		return PackageMutationResult::failed(
			PackageStorageOperation::DELETE,
			'ran_booster_storage_delete_failed',
			__( 'Booster could not remove the package management record. No success was reported.', 'ran-booster' )
		);
	}

	private function delete_conflict_result(): PackageMutationResult {
		return PackageMutationResult::conflict(
			PackageStorageOperation::DELETE,
			'ran_booster_storage_delete_conflict',
			__( 'Booster could not verify removal of the package management record. No success was reported.', 'ran-booster' )
		);
	}

	private function failure_result( PackageStorageFailure $failure ): PackageMutationResult {
		return PackageMutationResult::failed(
			$failure->get_operation(),
			$failure->get_diagnostic_id(),
			$failure->getMessage()
		);
	}

	private function post_write_verification_failure( PackageStorageOperation $operation ): PackageMutationResult {
		$failure = PackageStorageFailure::after_write_could_not_be_verified( $operation );

		return PackageMutationResult::failed(
			$failure->get_operation(),
			$failure->get_diagnostic_id(),
			$failure->getMessage(),
			true
		);
	}

	private function source_conflict_result(): PackageMutationResult {
		return PackageMutationResult::conflict(
			PackageStorageOperation::UPDATE,
			'ran_booster_storage_source_conflict',
			__( 'The managed package source changed before this operation. No package changes were made.', 'ran-booster' )
		);
	}

	private function source_model_from_row( object $row ): PackageModel {
		return new PackageModel(
			array(
				'source'          => $this->value_from_row( $row, 'source' ),
				'source_revision' => $this->value_from_row( $row, 'source_revision' ),
			)
		);
	}

	private function invalid_provider_identity_result( PackageStorageOperation $operation ): PackageMutationResult {
		return PackageMutationResult::failed(
			$operation,
			'ran_booster_storage_invalid_provider_identity',
			__( 'Booster could not save this package because its Git provider identity is incomplete.', 'ran-booster' )
		);
	}

	private function invalid_package_identity_result( PackageStorageOperation $operation ): PackageMutationResult {
		return PackageMutationResult::failed(
			$operation,
			'ran_booster_storage_invalid_package_identity',
			__( 'Booster could not save this package because its installed package identity is invalid.', 'ran-booster' )
		);
	}

	/**
	 * @param array<string, mixed> $expected Expected stored fields.
	 */
	private function row_matches( object $row, array $expected ): bool {
		foreach ( $expected as $field => $value ) {
			$actual = $this->value_from_row( $row, $field );

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

	/** @return TPackage */
	private function hydrate_package( object $row ): Package {
		$identifier = $this->string_from_row( $row, 'package' );
		$package    = $this->package_from_installation( $identifier );
		if ( ! $package instanceof AbstractPackage ) {
			throw PackageStorageFailure::invalid_provider_identity();
		}
		$package->set_adoption_token( $this->row_adoption_token( $row ) );
		$provider   = $this->string_from_row( $row, 'provider' );
		$handle     = $this->string_from_row( $row, 'repository' );
		$credential = $this->string_from_row( $row, 'credential_id' );
		try {
			$privacy    = new PackageModel( array( 'private' => $this->value_from_row( $row, 'private' ) ) );
			$repository = new ManagedRepository(
				$provider,
				$handle,
				$this->string_from_row( $row, 'provider_repository_id' ),
				$this->string_from_row( $row, 'branch' ),
				(bool) $privacy->private,
				'' === $credential ? null : $credential
			);
		} catch ( InvalidArgumentException ) {
			throw PackageStorageFailure::invalid_provider_identity();
		}

		$package->set_repository( $repository );
		$package->set_deployment_policy(
			DeploymentPolicy::from_database( $this->value_from_row( $row, 'deployment_policy', DeploymentPolicy::MANUAL->value ) )
		);
		$source = new PackageModel(
			array(
				'source'          => $this->value_from_row( $row, 'source' ),
				'source_revision' => $this->value_from_row( $row, 'source_revision' ),
			)
		);
		$package->set_source( PackageSource::from_database( $source->source ), $source->source_revision );
		$package->set_subdirectory( $this->value_from_row( $row, 'subdirectory' ) );

		return $package;
	}

	private function string_from_row( object $row, string $field ): string {
		return (string) $this->value_from_row( $row, $field, '' );
	}

	private function value_from_row( object $row, string $field, mixed $default_value = null ): mixed {
		return property_exists( $row, $field ) ? $row->$field : $default_value;
	}

	private function require_storage_support( PackageStorageOperation $operation ): void {
		$this->database_lifecycle ??= new Database();
		try {
			$this->database_lifecycle->require_ready();
		} catch ( DatabaseCompatibilityFailure | DatabaseLifecycleFailure ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The enum is converted into a display-safe typed storage failure.
			throw PackageStorageFailure::unsupported_database( $operation );
		}
	}

	abstract protected function package_type(): int;

	abstract protected function package_exists( string $identifier ): bool;

	/** @return TPackage */
	abstract protected function package_from_installation( string $identifier ): Package;

	abstract protected function not_found_exception(): Throwable;
}
