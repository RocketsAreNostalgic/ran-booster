<?php

declare(strict_types=1);

namespace RAN; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Core owns the established three-character RAN namespace; WPCS requires four characters.

use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\RepositoryReference;

/** Immutable identity and local deployment settings for one managed package. */
final readonly class ManagedRepository {

	public ProviderCode $provider;
	public RepositoryReference $reference;
	public string $branch;

	public function __construct(
		ProviderCode|string $provider,
		string $locator,
		string $provider_repository_id,
		string $branch,
		bool $is_private = false,
		?string $credential_id = null
	) {
		$credential_id   = null === $credential_id || '' === trim( $credential_id ) ? null : $credential_id;
		$this->provider  = is_string( $provider ) ? ProviderCode::parse( $provider ) : $provider;
		$this->reference = new RepositoryReference( $locator, $provider_repository_id, $is_private, $credential_id );
		$this->branch    = '' === $branch ? 'main' : $branch;
	}

	public function __toString(): string {
		return $this->reference->locator;
	}
}
