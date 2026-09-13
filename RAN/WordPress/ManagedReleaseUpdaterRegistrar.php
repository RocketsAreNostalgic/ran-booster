<?php

declare(strict_types=1);

namespace RAN\WordPress;

use Closure;
use LogicException;
use RAN\PackageArtifactLimit;

/**
 * Bounded adapter over the selected release-updater registrar.
 *
 * The provider module receives the same registrar object contract as before;
 * Booster-owned archive policy remains resolved at this Core composition edge.
 */
final readonly class ManagedReleaseUpdaterRegistrar {
	public function __construct( private object $registrar ) {
	}

	public function maximumArtifactBytes(): int {
		return PackageArtifactLimit::resolve( null );
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

	/** @param list<mixed> $arguments */
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
