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
	public static function provider_repositories(
		string $operation,
		string $canonical_url,
		string $error_region_id
	): self {
		return new self(
			$operation,
			AdminInteractionTarget::PROVIDER_REPOSITORIES,
			$canonical_url,
			$error_region_id
		);
	}

	/**
	 * Declare one add-on-rendered Transporter source row.
	 *
	 * The namespace is presentation identity only. It must not contain a source
	 * row ID, source key, source revision or cleanup instruction.
	 */
	public static function transporter_migration_source_row(
		string $operation,
		string $row_namespace,
		string $canonical_url,
		string $error_region_id
	): self {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9-]{0,63}:[a-z][a-z0-9-]{0,63}$/', $row_namespace ) ) {
			throw new InvalidArgumentException( 'Transporter migration rows require a bounded namespaced presentation key.' );
		}

		return new self(
			$operation,
			AdminInteractionTarget::TRANSPORTER_MIGRATION_SOURCE,
			$canonical_url,
			$error_region_id,
			substr( hash( 'sha256', $row_namespace ), 0, 32 )
		);
	}

	public function operation(): string {
		return $this->operation;
	}

	public function target(): AdminInteractionTarget {
		return $this->target;
	}
	public function target_key(): string {
		return $this->target->key( $this->target_instance );
	}
	public function target_selector(): string {
		return $this->target->selector( $this->target_instance );
	}
	public function target_element_id(): string {
		return $this->target->element_id( $this->target_instance );
	}
	public function canonical_url(): string {
		return $this->canonical_url;
	}
	public function error_region_id(): string {
		return $this->error_region_id;
	}
}
