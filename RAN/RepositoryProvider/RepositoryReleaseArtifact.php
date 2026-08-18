<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\Deployment\PreparedArtifact;

/** One exact verified release archive with single-use Core handoff. */
interface RepositoryReleaseArtifact {
	public function discard(): bool;

	public function handoffToCore(): PreparedArtifact;

	public function version(): string;

	public function packageRoot(): string;

	public function mainFile(): string;

	public function identifier( string $packageType ): string;
}
