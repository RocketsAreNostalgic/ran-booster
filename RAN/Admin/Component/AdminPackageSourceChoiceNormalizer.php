<?php

declare(strict_types=1);

namespace RAN\Admin\Component;

use LogicException;

/**
 * Validates the two bounded package-source choices rendered by Core.
 */
final class AdminPackageSourceChoiceNormalizer {

	/**
	 * @param mixed $choices
	 * @return array<string, array<string, mixed>>
	 */
	public function normalize( mixed $choices ): array {
		if ( ! is_array( $choices ) ) {
			throw new LogicException( 'Package source choices must be a keyed array.' );
		}

		$normalized = array();
		foreach ( $choices as $key => $choice ) {
			if ( ! is_string( $key )
				|| ! in_array( $key, array( 'branch', 'release_asset' ), true )
				|| ! is_array( $choice ) ) {
				throw new LogicException( 'Package source choices require a known source key.' );
			}
			if ( isset( $choice['key'] ) && $key !== $choice['key'] ) {
				throw new LogicException( 'Package source choice keys must match their map identity.' );
			}
			foreach ( array( 'disabled', 'hydrated', 'client_hydratable' ) as $flag ) {
				if ( isset( $choice[ $flag ] ) && ! is_bool( $choice[ $flag ] ) ) {
					throw new LogicException( 'Package source choice flags must be booleans.' );
				}
			}

			$url      = $this->boundedString( $choice['url'] ?? '', 2048, true );
			$disabled = true === ( $choice['disabled'] ?? false );
			if ( ! $disabled ) {
				$this->assertUrl( $url );
			}

			$normalized[ $key ] = array(
				'key'               => $key,
				'heading'           => $this->boundedString( $choice['heading'] ?? null, 96, false ),
				'description'       => $this->boundedString( $choice['description'] ?? null, 255, false ),
				'meta'              => $this->boundedString( $choice['meta'] ?? '', 96, true ),
				'url'               => $url,
				'disabled'          => $disabled,
				'hydrated'          => true === ( $choice['hydrated'] ?? false ),
				'client_hydratable' => true === ( $choice['client_hydratable'] ?? false ),
			);
		}

		if ( ! isset( $normalized['branch'], $normalized['release_asset'] ) || 2 !== count( $normalized ) ) {
			throw new LogicException( 'Core requires exactly Branch and Published release source choices.' );
		}

		return $normalized;
	}

	private function assertUrl( string $url ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Validate before rendering.
		$parts = parse_url( $url );
		if ( ! is_array( $parts )
			|| ! isset( $parts['scheme'], $parts['host'] )
			|| ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] ) ) {
			throw new LogicException( 'Enabled package source choices require a safe absolute URL.' );
		}
	}

	private function boundedString( mixed $value, int $maximum, bool $allowEmpty ): string {
		if ( ! is_string( $value )
			|| ( ! $allowEmpty && '' === trim( $value ) )
			|| strlen( $value ) > $maximum
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			throw new LogicException( 'Package source choices contain an invalid display value.' );
		}

		return $value;
	}
}
