<?php

declare(strict_types=1);

namespace RAN\WordPress;

use Closure;
use LogicException;
use RAN\PackageArtifactLimit;

/**
 * Bounded adapter over the selected release-updater registrar.
 *
 * Provider code receives only resolved Booster policy. This host-owned adapter
 * also owns compatibility with release-updater versions that omit the optional
 * maximum-artifact argument when Booster is using its default ceiling.
 */
final readonly class ManagedReleaseUpdaterRegistrar {
	public function __construct( private object $registrar ) {
	}

	public function maximumArtifactBytes(): int {
		return PackageArtifactLimit::resolve();
	}

	public function plugin( mixed ...$arguments ): object {
		return $this->invokeNativeTarget( 'plugin', $arguments );
	}

	public function theme( mixed ...$arguments ): object {
		return $this->invokeNativeTarget( 'theme', $arguments );
	}

	public function releases( mixed ...$arguments ): object {
		return $this->invoke( 'releases', $arguments );
	}

	/** @param list<mixed> $arguments */
	private function invokeNativeTarget( string $method, array $arguments ): object {
		if ( 8 === count( $arguments ) ) {
			$maximumArtifactBytes = $arguments[7];
			if ( ! is_int( $maximumArtifactBytes ) ) {
				throw new LogicException( 'The selected release updater artifact limit is incompatible.' );
			}
			if ( PackageArtifactLimit::DEFAULT_MAXIMUM_ARTIFACT_BYTES === $maximumArtifactBytes ) {
				array_pop( $arguments );
			}
		}

		return $this->invoke( $method, $arguments );
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
