<?php

declare(strict_types=1);

namespace RAN\Internal;

use ReflectionClass;
use ReflectionNamedType;

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
	 *
	 * @param string $alias Service lookup key.
	 * @param callable|string|object $concrete Factory, class name or instance.
	 */
	public function bind( $alias, $concrete ): void {
		$this->services[ $alias ] = $concrete;
	}

	/**
	 * @internal Core composition only; this is not an extension API.
	 *
	 * @param string $alias Service alias or class name.
	 * @return mixed Factories may return any value; reflected services are objects.
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

	/**
	 * @param string $class_name Class name to resolve through reflection.
	 * @return object
	 */
	private function resolve( $class_name ) {
		$reflection  = new ReflectionClass( $class_name );
		$constructor = $reflection->getConstructor();

		if ( ! $constructor ) {
			return new $class_name();
		}

		$params = $constructor->getParameters();
		if ( count( $params ) === 0 ) {
			return new $class_name();
		}

		$new_instance_params = array();
		foreach ( $params as $param ) {
			$type = $param->getType();
			if ( null === $type ) {
				$new_instance_params[] = null;
				continue;
			}

			if ( ! $type instanceof ReflectionNamedType ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed internal diagnostic; escaping belongs at the output boundary.
				throw new \Error( 'Core container cannot resolve union or intersection constructor parameter types.' );
			}

			$new_instance_params[] = $this->make( $type->getName() );
		}

		return $reflection->newInstanceArgs( $new_instance_params );
	}
}
