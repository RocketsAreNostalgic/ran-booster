<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

/**
 * Provider-neutral custody over one exact verified release archive.
 *
 * The provider retains its source path and lifetime behind inspect(); Core may
 * read it only while the custody object grants that inspection. Facts exposed
 * here are immutable transfer evidence, not host policy.
 */
interface RepositoryReleaseArtifactCustody {
	/** @param callable(string): mixed $inspection */
	public function inspect( callable $inspection ): mixed;

	public function discard(): bool;

	public function resolvedRef(): string;

	public function version(): string;

	public function size(): int;

	public function sha256(): string;
}
