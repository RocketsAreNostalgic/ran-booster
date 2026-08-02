<?php

declare(strict_types=1);

namespace RAN\Storage;

final class PackageMutationResult {

	private function __construct(
		private readonly PackageMutationStatus $status,
		private readonly PackageStorageOperation $operation,
		private readonly string $diagnosticId,
		private readonly string $message,
		private readonly bool $recoveryRequired = false
	) {
	}

	public static function changed( PackageStorageOperation $operation = PackageStorageOperation::UPDATE ): self {
		return new self(
			PackageMutationStatus::CHANGED,
			$operation,
			'ran_booster_storage_changed',
			__( 'Package management data was saved.', 'ran-booster' )
		);
	}

	public static function unchanged( PackageStorageOperation $operation = PackageStorageOperation::UPDATE ): self {
		return new self(
			PackageMutationStatus::UNCHANGED,
			$operation,
			'ran_booster_storage_unchanged',
			__( 'Package management data was already in the requested state.', 'ran-booster' )
		);
	}

	public static function conflict( PackageStorageOperation $operation, string $diagnosticId, string $message ): self {
		return new self( PackageMutationStatus::CONFLICT, $operation, $diagnosticId, $message );
	}

	public static function failed(
		PackageStorageOperation $operation,
		string $diagnosticId,
		string $message,
		bool $recoveryRequired = false
	): self {
		return new self( PackageMutationStatus::FAILED, $operation, $diagnosticId, $message, $recoveryRequired );
	}

	public function getStatus(): PackageMutationStatus {
		return $this->status;
	}

	public function getDiagnosticId(): string {
		return $this->diagnosticId;
	}

	public function getOperation(): PackageStorageOperation {
		return $this->operation;
	}

	public function getMessage(): string {
		return $this->message;
	}

	public function isSuccessful(): bool {
		return in_array(
			$this->status,
			array( PackageMutationStatus::CHANGED, PackageMutationStatus::UNCHANGED ),
			true
		);
	}

	public function isRecoveryRequired(): bool {
		return $this->recoveryRequired;
	}

	public function requireSuccess(): void {
		if ( ! $this->isSuccessful() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The exception carries a fixed translated message for the controller boundary.
			throw PackageStorageFailure::fromMutationResult( $this );
		}
	}
}
