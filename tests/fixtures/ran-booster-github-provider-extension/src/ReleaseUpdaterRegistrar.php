<?php

declare(strict_types=1);

namespace RANBoosterGitHubProviderExtensionFixture;

use Closure;
use LogicException;
use ReflectionClass;

/**
 * Test-fixture wrapper around the package-owned release updater registrar.
 *
 * It records only the argument vector needed to prove host-policy parity while
 * delegating every operation to the real copied updater package.
 */
final class ReleaseUpdaterRegistrar {
	/** @var list<mixed>|null */
	private ?array $releaseArguments = null;

	public function __construct( private readonly object $inner ) {
	}

	public function plugin( mixed ...$arguments ): object {
		return $this->invoke( 'plugin', $arguments );
	}

	public function theme( mixed ...$arguments ): object {
		return $this->invoke( 'theme', $arguments );
	}

	public function releases( mixed ...$arguments ): object {
		$this->releaseArguments = $arguments;

		return $this->invoke( 'releases', $arguments );
	}

	/** @return list<mixed>|null */
	public function releaseArguments(): ?array {
		return $this->releaseArguments;
	}

	public function innerSource(): string {
		$file = ( new ReflectionClass( $this->inner ) )->getFileName();

		return is_string( $file ) ? $file : '';
	}

	/** @param list<mixed> $arguments */
	private function invoke( string $method, array $arguments ): object {
		$callable = array( $this->inner, $method );
		if ( ! is_callable( $callable ) ) {
			throw new LogicException( 'The fixture release updater registrar is incompatible.' );
		}

		$result = Closure::fromCallable( $callable )( ...$arguments );
		if ( ! is_object( $result ) ) {
			throw new LogicException( 'The fixture release updater returned an invalid handle.' );
		}

		return $result;
	}
}
