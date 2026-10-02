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
	private Closure $maximum_artifact_bytes;

	/** @param callable(): int $maximum_artifact_bytes */
	public function __construct( callable $maximum_artifact_bytes ) {
		$this->maximum_artifact_bytes = Closure::fromCallable( $maximum_artifact_bytes );
	}

	public function maximum_artifact_bytes(): int {
		return ( $this->maximum_artifact_bytes )();
	}
}
