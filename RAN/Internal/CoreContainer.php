<?php

declare(strict_types=1);

namespace RAN\Internal;

use ReflectionClass;

/**
 * Core's request-local composition mechanism.
 *
 * @internal This is not an extension API or a confidentiality boundary.
 */
final class CoreContainer {
	/** @var array<string, callable|string|object> */
	private array $services = array();

	/**
	 * @internal Core composition only; this is not an extension API.
	 */
	public function bind( $alias, $concrete ): void {
		$this->services[ $alias ] = $concrete;
	}

	/**
	 * @internal Core composition only; this is not an extension API.
	 */
	public function make( $alias ) {
		if ( isset( $this->services[ $alias ] ) && is_callable( $this->services[ $alias ] ) ) {
			return call_user_func_array( $this->services[ $alias ], array( $this ) );
		}

		if ( isset( $this->services[ $alias ] ) && is_object( $this->services[ $alias ] ) ) {
			return $this->services[ $alias ];
		}

		if ( isset( $this->services[ $alias ] ) && class_exists( $this->services[ $alias ] ) ) {
			return $this->resolve( $this->services[ $alias ] );
		}

		return $this->resolve( $alias );
	}

	private function resolve( $class ) {
		$reflection  = new ReflectionClass( $class );
		$constructor = $reflection->getConstructor();

		if ( ! $constructor ) {
			return new $class();
		}

		$params = $constructor->getParameters();
		if ( count( $params ) === 0 ) {
			return new $class();
		}

		$newInstanceParams = array();
		foreach ( $params as $param ) {
			$type = $param->getType();
			if ( null === $type ) {
				$newInstanceParams[] = null;
				continue;
			}

			$newInstanceParams[] = $this->make( $type->getName() );
		}

		return $reflection->newInstanceArgs( $newInstanceParams );
	}
}
