<?php

declare(strict_types=1);

namespace RAN\Storage;

use RuntimeException;

final class PackageStorageFailure extends RuntimeException {

	private function __construct(
		private readonly PackageStorageOperation $operation,
		private readonly string $diagnostic_id,
		string $message,
		private readonly bool $recovery_required = false
	) {
		parent::__construct( $message );
	}

	public static function query_failed(): self {
		return new self(
			PackageStorageOperation::QUERY,
			'ran_booster_storage_query_failed',
			__( 'Booster could not read its package management data. No package changes were made.', 'ran-booster' )
		);
	}

	public static function write_failed(): self {
		return new self(
			PackageStorageOperation::UPDATE,
			'ran_booster_storage_write_failed',
			__( 'Booster could not save its package management data. No package changes were made.', 'ran-booster' )
		);
	}

	public static function transaction_unavailable(): self {
		return new self(
			PackageStorageOperation::UPDATE,
			'ran_booster_storage_transaction_unavailable',
			__( 'Booster could not safely begin its package-management transaction. No package changes were made.', 'ran-booster' )
		);
	}

	public static function unsupported_database( PackageStorageOperation $operation = PackageStorageOperation::QUERY ): self {
		return new self(
			$operation,
			'ran_booster_storage_database_unsupported',
			__( 'Booster package storage is paused because this site does not meet the database requirements. No package changes were made.', 'ran-booster' )
		);
	}

	public static function duplicate_package_rows(): self {
		return new self(
			PackageStorageOperation::QUERY,
			'ran_booster_storage_duplicate_package',
			__( 'Booster found conflicting package management records. No package changes were made.', 'ran-booster' )
		);
	}

	public static function invalid_provider_identity(): self {
		return new self(
			PackageStorageOperation::QUERY,
			'ran_booster_storage_invalid_provider_identity',
			__( 'Booster could not read a managed package because its repository provider identity is invalid.', 'ran-booster' )
		);
	}

	public static function repository_source_conflict( ?string $release_owner = null ): self {
		return new self(
			PackageStorageOperation::QUERY,
			'ran_booster_repository_source_conflict',
			null === $release_owner
				? __( 'This repository is shared by managed packages. Releases require a repository used by only one managed package. Review the repository’s package settings before changing source.', 'ran-booster' )
				: sprintf(
					/* translators: %s: identifier of the existing Release package. */
					__( 'This repository already supplies releases to %s. Additional packages cannot use it. To use this repository for multiple Branch packages, switch that package to Branch first.', 'ran-booster' ),
					$release_owner
				)
		);
	}

	public static function from_mutation_result( PackageMutationResult $result ): self {
		return new self(
			$result->get_operation(),
			$result->get_diagnostic_id(),
			$result->get_message(),
			$result->is_recovery_required()
		);
	}

	public static function after_write_could_not_be_verified( PackageStorageOperation $operation ): self {
		return new self(
			$operation,
			'ran_booster_storage_verification_failed',
			__( 'Booster could not verify package management data after a database change. The saved state may have changed; review it before retrying.', 'ran-booster' ),
			true
		);
	}

	public function get_diagnostic_id(): string {
		return $this->diagnostic_id;
	}

	public function get_operation(): PackageStorageOperation {
		return $this->operation;
	}

	public function is_recovery_required(): bool {
		return $this->recovery_required;
	}

	public function is_database_unsupported(): bool {
		return 'ran_booster_storage_database_unsupported' === $this->diagnostic_id;
	}
}
