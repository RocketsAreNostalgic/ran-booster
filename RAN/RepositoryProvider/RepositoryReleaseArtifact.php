<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

/** One exact verified release archive with single-use Core handoff. */
interface RepositoryReleaseArtifact {
	public function discard(): bool;

	public function handoffToCore(): RepositoryReleaseArtifactCustody;

	public function version(): string;

	public function packageRoot(): string;

	public function mainFile(): string;

	public function identifier( string $packageType ): string;
}
