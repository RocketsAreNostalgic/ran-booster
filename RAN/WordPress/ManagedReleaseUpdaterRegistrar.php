<?php

declare(strict_types=1);

namespace RAN\WordPress;

use Closure;
use LogicException;

/** Fail-closed host boundary over the selected release-updater registrar. */
final readonly class ManagedReleaseUpdaterRegistrar {
	public function __construct( private object $registrar ) {
	}

	public function with_operation_guard( callable $guard ): self {
		return new self( $this->invoke( 'with_operation_guard', array( $guard ) ) );
	}

	public function with_native_mutation_callbacks( callable $begin, callable $complete ): self {
		return new self( $this->invoke( 'with_native_mutation_callbacks', array( $begin, $complete ) ) );
	}

	/** Return null when the selected runtime cannot install the live admission fence. */
	public function with_descriptor_admission( callable $descriptor_admission ): ?self {
		$callable = array( $this->registrar, 'with_descriptor_admission' );
		if ( ! is_callable( $callable ) ) {
			return null;
		}
		try {
			$result = Closure::fromCallable( $callable )( $descriptor_admission );
		} catch ( \Throwable ) {
			return null;
		}

		return is_object( $result ) ? new self( $result ) : null;
	}

	public function plugin( mixed ...$arguments ): object {
		return $this->invoke( 'plugin', $arguments );
	}

	public function theme( mixed ...$arguments ): object {
		return $this->invoke( 'theme', $arguments );
	}

	public function releases( mixed ...$arguments ): object {
		return $this->invoke( 'releases', $arguments );
	}

	/** @param array<array-key, mixed> $arguments */
	private function invoke( string $method, array $arguments ): object {
		$callable = array( $this->registrar, $method );
		if ( ! is_callable( $callable ) ) {
			throw new LogicException( 'The selected release updater registrar is incompatible.' );
		}

		$result = Closure::fromCallable( $callable )( ...$arguments );
		if ( ! is_object( $result ) ) {
			throw new LogicException( 'The selected release updater registrar returned an invalid handle.' );
		}

		return $result;
	}
}
