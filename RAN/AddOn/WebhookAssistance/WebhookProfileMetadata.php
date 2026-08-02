<?php

declare(strict_types=1);

namespace RAN\AddOn\WebhookAssistance;

use InvalidArgumentException;
use RAN\RepositoryProvider\ProviderCode;

/** Immutable, display-safe metadata for one Core-owned webhook profile. */
final readonly class WebhookProfileMetadata {

	private string $providerCode;

	public function __construct(
		private string $id,
		string $providerCode,
		private string $scope,
		private string $target,
		private string $authorityId,
		private int $revision,
		private string $disposition,
		private string $source,
		private bool $immutable
	) {
		try {
			$this->providerCode = ProviderCode::parse( $providerCode )->value;
		} catch ( InvalidArgumentException ) {
			throw new InvalidArgumentException( 'Webhook profile metadata is invalid.' );
		}

		if ( 1 !== preg_match( '/^wh_[a-f0-9]{24}$/', $this->id )
			|| ! in_array( $this->scope, array( 'owner', 'repository' ), true )
			|| $this->revision < 1
			|| ! in_array( $this->disposition, array( 'created', 'reused' ), true )
			|| ( 'created' === $this->disposition && 'repository' !== $this->scope )
			|| 'file' !== $this->source
			|| $this->immutable
			|| ! $this->validTarget()
			|| ! $this->validAuthority()
		) {
			throw new InvalidArgumentException( 'Webhook profile metadata is invalid.' );
		}
	}

	public function id(): string {
		return $this->id;
	}

	public function providerCode(): string {
		return $this->providerCode;
	}

	public function scope(): string {
		return $this->scope;
	}

	public function target(): string {
		return $this->target;
	}

	public function authorityId(): string {
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
	public function toArray(): array {
		return array(
			'id'            => $this->id,
			'provider_code' => $this->providerCode,
			'scope'         => $this->scope,
			'target'        => $this->target,
			'authority_id'  => $this->authorityId,
			'revision'      => $this->revision,
			'disposition'   => $this->disposition,
			'source'        => $this->source,
			'immutable'     => $this->immutable,
		);
	}

	private function validTarget(): bool {
		if ( '' === $this->target || strlen( $this->target ) > 201 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $this->target ) ) {
			return false;
		}
		if ( 'owner' === $this->scope ) {
			return 1 === preg_match( '/^[A-Za-z0-9](?:[A-Za-z0-9_-]{0,62}[A-Za-z0-9])?$/', $this->target );
		}

		return 1 === preg_match( '/^[A-Za-z0-9](?:[A-Za-z0-9_-]{0,62}[A-Za-z0-9])?\/[A-Za-z0-9_.-]{1,100}$/', $this->target );
	}

	private function validAuthority(): bool {
		if ( 'owner' === $this->scope ) {
			return '' === $this->authorityId;
		}

		return '' !== $this->authorityId
			&& strlen( $this->authorityId ) <= 191
			&& 1 !== preg_match( '/[\x00-\x1F\x7F]/', $this->authorityId );
	}
}
