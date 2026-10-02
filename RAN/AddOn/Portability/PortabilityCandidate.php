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
		public string $display_name,
		public string $provider_code,
		public string $repository,
		public string $branch,
		public ?string $subdirectory = null,
		public ?string $credential_id = null
	) {
		// Reuse Blueprint's canonical package bounds without exposing or accepting
		// the provider-issued identity that Core must resolve independently.
		new BlueprintPackage(
			$type,
			$identifier,
			$display_name,
			$provider_code,
			'core-resolved',
			$repository,
			$branch,
			$subdirectory
		);

		if ( null !== $credential_id
			&& 1 !== preg_match( '/\A[A-Za-z0-9_-]{3,64}\z/D', $credential_id ) ) {
			throw new InvalidArgumentException( 'The Portability credential profile identifier is invalid.' );
		}
	}

	/** @return array{type:string,identifier:string,display_name:string,provider:string,repository:string,branch:string,subdirectory:string|null,credential_id:string|null} */
	public function to_array(): array {
		return array(
			'type'          => $this->type,
			'identifier'    => $this->identifier,
			'display_name'  => $this->display_name,
			'provider'      => $this->provider_code,
			'repository'    => $this->repository,
			'branch'        => $this->branch,
			'subdirectory'  => $this->subdirectory,
			'credential_id' => $this->credential_id,
		);
	}
}
