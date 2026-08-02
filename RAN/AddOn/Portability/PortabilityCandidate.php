<?php

declare(strict_types=1);

namespace RAN\AddOn\Portability;

use InvalidArgumentException;
use RAN\Portability\BlueprintPackage;

/** Immutable, credential-reference-only description of one installed package. */
final readonly class PortabilityCandidate {

	public function __construct(
		public string $type,
		public string $identifier,
		public string $displayName,
		public string $providerCode,
		public string $repository,
		public string $branch,
		public ?string $subdirectory = null,
		public ?string $credentialId = null
	) {
		// Reuse Blueprint's canonical package bounds without exposing or accepting
		// the provider-issued identity that Core must resolve independently.
		new BlueprintPackage(
			$type,
			$identifier,
			$displayName,
			$providerCode,
			'core-resolved',
			$repository,
			$branch,
			$subdirectory
		);

		if ( null !== $credentialId
			&& 1 !== preg_match( '/\A[A-Za-z0-9_-]{3,64}\z/D', $credentialId ) ) {
			throw new InvalidArgumentException( 'The Portability credential profile identifier is invalid.' );
		}
	}

	/** @return array{type:string,identifier:string,display_name:string,provider:string,repository:string,branch:string,subdirectory:string|null,credential_id:string|null} */
	public function toArray(): array {
		return array(
			'type'          => $this->type,
			'identifier'    => $this->identifier,
			'display_name'  => $this->displayName,
			'provider'      => $this->providerCode,
			'repository'    => $this->repository,
			'branch'        => $this->branch,
			'subdirectory'  => $this->subdirectory,
			'credential_id' => $this->credentialId,
		);
	}
}
