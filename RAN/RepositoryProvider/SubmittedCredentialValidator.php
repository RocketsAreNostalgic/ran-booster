<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

/**
 * Validates only newly submitted or replacement credential material.
 *
 * Existing saved, constant, and imported credentials deliberately bypass this
 * check so provider format changes do not make historical material unreadable.
 *
 * This optional Provider API contract must reject invalid input with a bounded
 * InvalidCredentialInput or another failure that Core maps to generic copy.
 */
interface SubmittedCredentialValidator {

	/** @param array<string, mixed> $metadata Canonical non-secret credential metadata. */
	public function validateSubmittedCredential(
		array $metadata,
		#[\SensitiveParameter] string $secret
	): void;
}
