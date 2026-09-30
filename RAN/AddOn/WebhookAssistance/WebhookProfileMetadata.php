<?php

declare(strict_types=1);

namespace RAN\AddOn\WebhookAssistance;

use InvalidArgumentException;
use RAN\RepositoryProvider\ProviderCode;
use RAN\Secrets\SecretsFile;

/** Immutable, display-safe metadata for one Core-owned webhook profile. */
final readonly class WebhookProfileMetadata {

	private string $provider_code;

	public function __construct(
		private string $id,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		string $providerCode,
		private string $scope,
		private string $target,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		private string $authorityId,
		private int $revision,
		private string $disposition,
		private string $source,
		private bool $immutable
	) {
		try {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			$this->provider_code = ProviderCode::parse( $providerCode )->value;
		} catch ( InvalidArgumentException ) {
			throw new InvalidArgumentException( 'Webhook profile metadata is invalid.' );
		}

		if ( ( SecretsFile::CONSTANT_PROFILE !== $this->id && 1 !== preg_match( '/^wh_[a-f0-9]{24}$/', $this->id ) )
			|| ! in_array( $this->scope, array( 'owner', 'repository' ), true )
			|| $this->revision < 1
			|| ! in_array( $this->disposition, array( 'created', 'reused' ), true )
			|| ( 'created' === $this->disposition && 'repository' !== $this->scope )
			|| ! in_array( $this->source, array( 'file', 'constant' ), true )
			|| ( 'file' === $this->source && $this->immutable )
			|| ( 'constant' === $this->source && ! $this->immutable )
			|| ! $this->valid_target()
			|| ! $this->valid_authority()
		) {
			throw new InvalidArgumentException( 'Webhook profile metadata is invalid.' );
		}
	}

	public function id(): string {
		return $this->id;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function providerCode(): string {
		return $this->provider_code;
	}

	public function scope(): string {
		return $this->scope;
	}

	public function target(): string {
		return $this->target;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function authorityId(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
		return $this->authorityId;
	}

	public function revision(): int {
		return $this->revision;
	}

	public function disposition(): string {
		return $this->disposition;
	}

	/**
	 * @return array{id: string, provider_code: string, scope: string, target: string, authority_id: string, revision: int, disposition: string, source: string, immutable: bool}
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function toArray(): array {
		return array(
			'id'            => $this->id,
			'provider_code' => $this->provider_code,
			'scope'         => $this->scope,
			'target'        => $this->target,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
			'authority_id'  => $this->authorityId,
			'revision'      => $this->revision,
			'disposition'   => $this->disposition,
			'source'        => $this->source,
			'immutable'     => $this->immutable,
		);
	}

	private function valid_target(): bool {
		if ( '' === $this->target || strlen( $this->target ) > 201 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $this->target ) ) {
			return false;
		}
		if ( 'owner' === $this->scope ) {
			return 1 === preg_match( '/^[A-Za-z0-9](?:[A-Za-z0-9_-]{0,62}[A-Za-z0-9])?$/', $this->target );
		}

		return 1 === preg_match( '/^[A-Za-z0-9](?:[A-Za-z0-9_-]{0,62}[A-Za-z0-9])?\/[A-Za-z0-9_.-]{1,100}$/', $this->target );
	}

	private function valid_authority(): bool {
		if ( 'owner' === $this->scope ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
			return '' === $this->authorityId;
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
		return '' !== $this->authorityId
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
			&& strlen( $this->authorityId ) <= 191
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
			&& 1 !== preg_match( '/[\x00-\x1F\x7F]/', $this->authorityId );
	}
}
