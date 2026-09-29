<?php

declare(strict_types=1);

namespace RAN\Admin\Interaction;

use InvalidArgumentException;

/**
 * Immutable declaration shared by an add-on form and its handler.
 */
final readonly class AdminInteractionRequest {

	private function __construct(
		private string $operation,
		private AdminInteractionTarget $target,
		private string $canonical_url,
		private string $error_region_id,
		private ?string $target_instance = null
	) {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9-]{0,63}:[a-z][a-z0-9-]{0,63}$/', $operation ) ) {
			throw new InvalidArgumentException( 'Administration interaction operations require a bounded namespaced key.' );
		}
		if ( '' === $canonical_url
			|| strlen( $canonical_url ) > 2048
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $canonical_url ) ) {
			throw new InvalidArgumentException( 'Administration interaction return URLs are invalid.' );
		}
		if ( 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,127}$/', $error_region_id ) ) {
			throw new InvalidArgumentException( 'Administration interaction error regions require a bounded element ID.' );
		}
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public interaction methods retain the existing connected caller and callback contracts.
	public static function providerRepositories(
		string $operation,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
		string $canonicalUrl,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
		string $errorRegionId
	): self {
		return new self(
			$operation,
			AdminInteractionTarget::PROVIDER_REPOSITORIES,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
			$canonicalUrl,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
			$errorRegionId
		);
	}

	/**
	 * Declare one add-on-rendered Transporter source row.
	 *
	 * The namespace is presentation identity only. It must not contain a source
	 * row ID, source key, source revision or cleanup instruction.
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public interaction methods retain the existing connected caller and callback contracts.
	public static function transporterMigrationSourceRow(
		string $operation,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
		string $rowNamespace,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
		string $canonicalUrl,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
		string $errorRegionId
	): self {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
		if ( 1 !== preg_match( '/^[a-z][a-z0-9-]{0,63}:[a-z][a-z0-9-]{0,63}$/', $rowNamespace ) ) {
			throw new InvalidArgumentException( 'Transporter migration rows require a bounded namespaced presentation key.' );
		}

		return new self(
			$operation,
			AdminInteractionTarget::TRANSPORTER_MIGRATION_SOURCE,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
			$canonicalUrl,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
			$errorRegionId,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
			substr( hash( 'sha256', $rowNamespace ), 0, 32 )
		);
	}

	public function operation(): string {
		return $this->operation;
	}

	public function target(): AdminInteractionTarget {
		return $this->target;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public interaction methods retain the existing connected caller and callback contracts.
	public function targetKey(): string {
		return $this->target->key( $this->target_instance );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public interaction methods retain the existing connected caller and callback contracts.
	public function targetSelector(): string {
		return $this->target->selector( $this->target_instance );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public interaction methods retain the existing connected caller and callback contracts.
	public function targetElementId(): string {
		return $this->target->elementId( $this->target_instance );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public interaction methods retain the existing connected caller and callback contracts.
	public function canonicalUrl(): string {
		return $this->canonical_url;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public interaction methods retain the existing connected caller and callback contracts.
	public function errorRegionId(): string {
		return $this->error_region_id;
	}
}
