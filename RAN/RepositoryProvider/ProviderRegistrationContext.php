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
	/** @var Closure(): bool */
	private Closure $operation_allowed;

	/**
	 * @param callable(): int $maximum_artifact_bytes
	 * @param callable(): bool|null $operation_allowed Fresh host authorization, never a preview receipt.
	 */
	public function __construct( callable $maximum_artifact_bytes, ?callable $operation_allowed = null ) {
		$this->maximum_artifact_bytes = Closure::fromCallable( $maximum_artifact_bytes );
		$this->operation_allowed      = null === $operation_allowed ? static fn (): bool => true : Closure::fromCallable( $operation_allowed );
	}

	public function operation_allowed(): bool {
		try {
			return ( $this->operation_allowed )();
		} catch ( \Throwable ) {
			return false;
		}
	}

	public function maximum_artifact_bytes(): int {
		return ( $this->maximum_artifact_bytes )();
	}
}
