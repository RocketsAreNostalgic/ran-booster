<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use Closure;

/**
 * Bounded host policy exposed while a repository provider is registered.
 *
 * This context deliberately exposes provider-neutral scalar policy only. Its
 * single private resolver preserves Core's operation-level validation boundary;
 * it is not a service locator and exposes no Core implementation service.
 */
final readonly class ProviderRegistrationContext {
	/** @var Closure(): int */
	private Closure $maximumArtifactBytes;

	/** @param callable(): int $maximumArtifactBytes */
	public function __construct( callable $maximumArtifactBytes ) {
		$this->maximumArtifactBytes = Closure::fromCallable( $maximumArtifactBytes );
	}

	public function maximumArtifactBytes(): int {
		return ( $this->maximumArtifactBytes )();
	}
}
