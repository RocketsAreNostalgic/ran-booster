<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

/**
 * Validates only newly submitted or replacement credential material.
 *
 * Existing saved, constant, and imported credentials deliberately bypass this
 * check so provider format changes do not make historical material unreadable.
 *
 * @internal Core-owned compatibility seam; not part of Provider API 8.
 */
interface SubmittedCredentialValidator {

	/** @param array<string, mixed> $metadata Canonical non-secret credential metadata. */
	public function validateSubmittedCredential(
		array $metadata,
		#[\SensitiveParameter] string $secret
	): void;
}
