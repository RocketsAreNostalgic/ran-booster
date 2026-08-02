<?php

declare(strict_types=1);

namespace RAN\Storage;

use RuntimeException;

final class PackageStorageFailure extends RuntimeException {

	private function __construct(
		private readonly PackageStorageOperation $operation,
		private readonly string $diagnosticId,
		string $message,
		private readonly bool $recoveryRequired = false
	) {
		parent::__construct( $message );
	}

	public static function queryFailed(): self {
		return new self(
			PackageStorageOperation::QUERY,
			'ran_booster_storage_query_failed',
			__( 'Booster could not read its package management data. No package changes were made.', 'ran-booster' )
		);
	}

	public static function writeFailed(): self {
		return new self(
			PackageStorageOperation::UPDATE,
			'ran_booster_storage_write_failed',
			__( 'Booster could not save its package management data. No package changes were made.', 'ran-booster' )
		);
	}

	public static function transactionUnavailable(): self {
		return new self(
			PackageStorageOperation::UPDATE,
			'ran_booster_storage_transaction_unavailable',
			__( 'Booster could not safely begin its package-management transaction. No package changes were made.', 'ran-booster' )
		);
	}

	public static function unsupportedDatabase( PackageStorageOperation $operation = PackageStorageOperation::QUERY ): self {
		return new self(
			$operation,
			'ran_booster_storage_database_unsupported',
			__( 'Booster package storage is paused because this site does not meet the database requirements. No package changes were made.', 'ran-booster' )
		);
	}

	public static function duplicatePackageRows(): self {
		return new self(
			PackageStorageOperation::QUERY,
			'ran_booster_storage_duplicate_package',
			__( 'Booster found conflicting package management records. No package changes were made.', 'ran-booster' )
		);
	}

	public static function invalidProviderIdentity(): self {
		return new self(
			PackageStorageOperation::QUERY,
			'ran_booster_storage_invalid_provider_identity',
			__( 'Booster could not read a managed package because its repository provider identity is invalid.', 'ran-booster' )
		);
	}

	public static function fromMutationResult( PackageMutationResult $result ): self {
		return new self(
			$result->getOperation(),
			$result->getDiagnosticId(),
			$result->getMessage(),
			$result->isRecoveryRequired()
		);
	}

	public static function afterWriteCouldNotBeVerified( PackageStorageOperation $operation ): self {
		return new self(
			$operation,
			'ran_booster_storage_verification_failed',
			__( 'Booster could not verify package management data after a database change. The saved state may have changed; review it before retrying.', 'ran-booster' ),
			true
		);
	}

	public function getDiagnosticId(): string {
		return $this->diagnosticId;
	}

	public function getOperation(): PackageStorageOperation {
		return $this->operation;
	}

	public function isRecoveryRequired(): bool {
		return $this->recoveryRequired;
	}

	public function isDatabaseUnsupported(): bool {
		return 'ran_booster_storage_database_unsupported' === $this->diagnosticId;
	}
}
