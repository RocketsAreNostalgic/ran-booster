<?php

declare(strict_types=1);

namespace RAN\Storage;

final class PackageMutationResult {

	private function __construct(
		private readonly PackageMutationStatus $status,
		private readonly PackageStorageOperation $operation,
		private readonly string $diagnostic_id,
		private readonly string $message,
		private readonly bool $recovery_required = false
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

	public static function conflict( PackageStorageOperation $operation, string $diagnostic_id, string $message ): self {
		return new self( PackageMutationStatus::CONFLICT, $operation, $diagnostic_id, $message );
	}

	public static function failed(
		PackageStorageOperation $operation,
		string $diagnostic_id,
		string $message,
		bool $recovery_required = false
	): self {
		return new self( PackageMutationStatus::FAILED, $operation, $diagnostic_id, $message, $recovery_required );
	}

	public function get_status(): PackageMutationStatus {
		return $this->status;
	}

	public function get_diagnostic_id(): string {
		return $this->diagnostic_id;
	}

	public function get_operation(): PackageStorageOperation {
		return $this->operation;
	}

	public function get_message(): string {
		return $this->message;
	}

	public function is_successful(): bool {
		return in_array(
			$this->status,
			array( PackageMutationStatus::CHANGED, PackageMutationStatus::UNCHANGED ),
			true
		);
	}

	public function is_recovery_required(): bool {
		return $this->recovery_required;
	}

	public function require_success(): void {
		if ( ! $this->is_successful() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The exception carries a fixed translated message for the controller boundary.
			throw PackageStorageFailure::from_mutation_result( $this );
		}
	}
}
