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
		private string $canonicalUrl,
		private string $errorRegionId,
		private ?string $targetInstance = null
	) {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9-]{0,63}:[a-z][a-z0-9-]{0,63}$/', $operation ) ) {
			throw new InvalidArgumentException( 'Administration interaction operations require a bounded namespaced key.' );
		}
		if ( '' === $canonicalUrl
			|| strlen( $canonicalUrl ) > 2048
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $canonicalUrl ) ) {
			throw new InvalidArgumentException( 'Administration interaction return URLs are invalid.' );
		}
		if ( 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,127}$/', $errorRegionId ) ) {
			throw new InvalidArgumentException( 'Administration interaction error regions require a bounded element ID.' );
		}
	}

	public static function providerRepositories(
		string $operation,
		string $canonicalUrl,
		string $errorRegionId
	): self {
		return new self(
			$operation,
			AdminInteractionTarget::PROVIDER_REPOSITORIES,
			$canonicalUrl,
			$errorRegionId
		);
	}

	/**
	 * Declare one add-on-rendered Transporter source row.
	 *
	 * The namespace is presentation identity only. It must not contain a source
	 * row ID, source key, source revision or cleanup instruction.
	 */
	public static function transporterMigrationSourceRow(
		string $operation,
		string $rowNamespace,
		string $canonicalUrl,
		string $errorRegionId
	): self {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9-]{0,63}:[a-z][a-z0-9-]{0,63}$/', $rowNamespace ) ) {
			throw new InvalidArgumentException( 'Transporter migration rows require a bounded namespaced presentation key.' );
		}

		return new self(
			$operation,
			AdminInteractionTarget::TRANSPORTER_MIGRATION_SOURCE,
			$canonicalUrl,
			$errorRegionId,
			substr( hash( 'sha256', $rowNamespace ), 0, 32 )
		);
	}

	public function operation(): string {
		return $this->operation;
	}

	public function target(): AdminInteractionTarget {
		return $this->target;
	}

	public function targetKey(): string {
		return $this->target->key( $this->targetInstance );
	}

	public function targetSelector(): string {
		return $this->target->selector( $this->targetInstance );
	}

	public function targetElementId(): string {
		return $this->target->elementId( $this->targetInstance );
	}

	public function canonicalUrl(): string {
		return $this->canonicalUrl;
	}

	public function errorRegionId(): string {
		return $this->errorRegionId;
	}
}
