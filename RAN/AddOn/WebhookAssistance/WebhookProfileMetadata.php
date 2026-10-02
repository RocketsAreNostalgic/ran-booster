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
		string $provider_code,
		private string $scope,
		private string $target,
		private string $authority_id,
		private int $revision,
		private string $disposition,
		private string $source,
		private bool $immutable
	) {
		try {
			$this->provider_code = ProviderCode::parse( $provider_code )->value;
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

	public function provider_code(): string {
		return $this->provider_code;
	}

	public function scope(): string {
		return $this->scope;
	}

	public function target(): string {
		return $this->target;
	}

	public function authority_id(): string {
		return $this->authority_id;
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
	public function to_array(): array {
		return array(
			'id'            => $this->id,
			'provider_code' => $this->provider_code,
			'scope'         => $this->scope,
			'target'        => $this->target,
			'authority_id'  => $this->authority_id,
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
			return '' === $this->authority_id;
		}

		return '' !== $this->authority_id
			&& strlen( $this->authority_id ) <= 191
			&& 1 !== preg_match( '/[\x00-\x1F\x7F]/', $this->authority_id );
	}
}
