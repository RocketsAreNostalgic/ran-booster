<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use Closure;

/**
 * Bounded host policy exposed while a repository provider is registered.
 *
 * This context exposes bounded scalar policy and native-update checkpoints. Its
 * private resolvers preserve Core's operation-level validation boundary;
 * it is not a service locator and exposes no Core implementation service.
 */
final readonly class ProviderRegistrationContext {
	/** @var Closure(): int */
	private Closure $maximum_artifact_bytes;
	/** @var Closure(): bool */
	private Closure $operation_allowed;
	/** @var Closure(string, string): string|null */
	private ?Closure $native_mutation_begin;
	/** @var Closure(string, string, string, string): void|null */
	private ?Closure $native_mutation_complete;

	/**
	 * @param callable(): int $maximum_artifact_bytes
	 * @param callable(): bool|null $operation_allowed Fresh host authorization, never a preview receipt.
	 * @param callable(string, string): string|null $native_mutation_begin Exact host update admission.
	 * @param callable(string, string, string, string): void|null $native_mutation_complete Exact host update completion.
	 */
	public function __construct(
		callable $maximum_artifact_bytes,
		?callable $operation_allowed = null,
		?callable $native_mutation_begin = null,
		?callable $native_mutation_complete = null
	) {
		if ( ( null === $native_mutation_begin ) !== ( null === $native_mutation_complete ) ) {
			throw new \InvalidArgumentException( 'Native provider mutation callbacks must be supplied together.' );
		}
		$this->maximum_artifact_bytes   = Closure::fromCallable( $maximum_artifact_bytes );
		$this->operation_allowed        = null === $operation_allowed ? static fn (): bool => true : Closure::fromCallable( $operation_allowed );
		$this->native_mutation_begin    = null === $native_mutation_begin ? null : Closure::fromCallable( $native_mutation_begin );
		$this->native_mutation_complete = null === $native_mutation_complete ? null : Closure::fromCallable( $native_mutation_complete );
	}

	public function operation_allowed(): bool {
		try {
			return ( $this->operation_allowed )();
		} catch ( \Throwable ) {
			return false;
		}
	}

	/** Obtain the host's exact update owner immediately before installed-file mutation. */
	public function begin_native_mutation( string $type, string $identity ): string {
		if ( null === $this->native_mutation_begin || ! $this->operation_allowed() ) {
			throw new \RuntimeException( 'Native provider mutation coordination is unavailable.' );
		}
		return ( $this->native_mutation_begin )( $type, $identity );
	}

	/** Completion retains exact custody even after current operation approval is lost. */
	public function complete_native_mutation( string $owner, string $type, string $identity, string $outcome ): void {
		if ( null === $this->native_mutation_complete ) {
			throw new \RuntimeException( 'Native provider mutation coordination is unavailable.' );
		}
		( $this->native_mutation_complete )( $owner, $type, $identity, $outcome );
	}

	public function maximum_artifact_bytes(): int {
		return ( $this->maximum_artifact_bytes )();
	}
}
