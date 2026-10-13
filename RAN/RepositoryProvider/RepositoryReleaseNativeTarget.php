<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

interface RepositoryReleaseNativeTarget {
	public function matches_check_source( string $runtime_provider, string $repository_identity, string $channel ): bool;

	public function register(): bool;

	public function status(): RepositoryReleaseNativeTargetStatus;

	public function refresh(): bool;
}
