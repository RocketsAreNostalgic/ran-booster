<?php

declare(strict_types=1);

namespace RAN; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Core owns the established three-character RAN namespace; WPCS requires four characters.

interface RepositoryPackageIdentity {
	public function get_provider_code(): ?string;

	public function get_provider_repository_id(): ?string;

	public function get_source_revision(): int;
}
