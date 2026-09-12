<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

interface RepositoryReleaseNativeTarget {
	public function register(): bool;

	public function status(): RepositoryReleaseNativeTargetStatus;

	public function refresh(): bool;
}
