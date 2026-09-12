<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

interface RepositoryReleaseNativeTarget {
	/** Configure the acquired artifact ceiling before registration. */
	public function configureMaximumArtifactBytes( int $maximumArtifactBytes ): void;

	public function register(): bool;

	public function status(): RepositoryReleaseNativeTargetStatus;

	public function refresh(): bool;
}
