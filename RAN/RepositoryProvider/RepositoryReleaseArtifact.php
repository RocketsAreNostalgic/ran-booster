<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

/** One exact verified release archive with single-use Core handoff. */
interface RepositoryReleaseArtifact {
	public function discard(): bool;

	public function handoff_to_core(): RepositoryReleaseArtifactCustody;

	public function version(): string;

	public function package_root(): string;

	public function main_file(): string;

	public function identifier( string $packageType ): string;
}
