<?php

declare(strict_types=1);

namespace RAN\Portability;

use InvalidArgumentException;
use JsonException;

final readonly class PackageBlueprint {

	public const FORMAT          = 'ran-booster-package-blueprint';
	public const VERSION         = 2;
	public const MAX_BYTES       = 262144;
	public const MAX_PACKAGES    = 128;
	public const MAX_CREDENTIALS = 128;

	/** @var list<BlueprintPackage> */
	public array $packages;

	/** @var list<BlueprintCredential> */
	public array $credentials;

	/**
	 * @param array<array-key, mixed> $packages
	 * @param array<array-key, mixed> $credentials
	 */
	public function __construct( array $packages, #[\SensitiveParameter] array $credentials = array() ) {
		if ( ! array_is_list( $packages ) || ! array_is_list( $credentials ) || count( $packages ) > self::MAX_PACKAGES || count( $credentials ) > self::MAX_CREDENTIALS ) {
			throw new InvalidArgumentException( 'The portability blueprint is invalid.' );
		}
		$identities = array();
		foreach ( $packages as $package ) {
			$key = $package instanceof BlueprintPackage ? $package->type . "\0" . $package->identifier : '';
			if ( ! $package instanceof BlueprintPackage || isset( $identities[ $key ] ) ) {
				throw new InvalidArgumentException( 'The portability blueprint is invalid.' );
			}
			$identities[ $key ] = $package->provider;
		}
		$associated = array();
		$materials  = array();
		foreach ( $credentials as $credential ) {
			if ( ! $credential instanceof BlueprintCredential ) {
				throw new InvalidArgumentException( 'The portability blueprint is invalid.' );
			}
			$material = $credential->to_array();
			unset( $material['packages'] );
			try {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure canonical contract with exceptions enabled.
				$encoded_material = json_encode( $material, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			} catch ( JsonException ) {
				throw new InvalidArgumentException( 'The portability blueprint is invalid.' );
			}
			$fingerprint = hash( 'sha256', $encoded_material );
			if ( isset( $materials[ $fingerprint ] ) ) {
				throw new InvalidArgumentException( 'The portability blueprint is invalid.' );
			}
			$materials[ $fingerprint ] = true;
			foreach ( $credential->packages as $package ) {
				$key = $package['type'] . "\0" . $package['identifier'];
				if ( ( $identities[ $key ] ?? null ) !== $credential->provider || isset( $associated[ $key ] ) ) {
					throw new InvalidArgumentException( 'The portability blueprint is invalid.' );
				}
				$associated[ $key ] = true;
			}
		}
		$this->packages    = $packages;
		$this->credentials = $credentials;
	}

	public static function from_json( #[\SensitiveParameter] string $json ): self {
		if ( '' === $json || strlen( $json ) > self::MAX_BYTES || 1 !== preg_match( '//u', $json ) ) {
			throw new InvalidArgumentException( 'The portability blueprint is invalid.' );
		}
		try {
			$data = json_decode( $json, true, 16, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw new InvalidArgumentException( 'The portability blueprint is invalid.' );
		}
		if ( ! is_array( $data ) || ( count( $data ) !== 4 || array_diff( array( 'format', 'version', 'packages', 'credentials' ), array_keys( $data ) ) ) || self::FORMAT !== $data['format'] || ! in_array( $data['version'], array( 1, self::VERSION ), true ) || ! is_array( $data['packages'] ) || ! array_is_list( $data['packages'] ) || ! is_array( $data['credentials'] ) || ! array_is_list( $data['credentials'] ) ) {
			throw new InvalidArgumentException( 'The portability blueprint is invalid.' );
		}
		$legacy      = 1 === $data['version'];
		$credentials = array();
		foreach ( $data['credentials'] as $record ) {
			if ( ! is_array( $record ) ) {
				throw new InvalidArgumentException( 'The portability blueprint is invalid.' );
			}
			if ( ! $legacy ) {
				$keys = array( 'provider', 'label', 'kind', 'configuration', 'secret', 'packages' );
				if ( count( $record ) !== count( $keys ) || array_diff( $keys, array_keys( $record ) ) || ! is_array( $record['packages'] ) ) {
					throw new InvalidArgumentException( 'The portability blueprint is invalid.' );
				}
				$record = array_replace( array_fill_keys( $keys, null ), $record );
				foreach ( $record['packages'] as &$identity ) {
					if ( ! is_array( $identity ) || count( $identity ) !== 2 || ! isset( $identity['type'], $identity['identifier'] ) ) {
						throw new InvalidArgumentException( 'The portability blueprint is invalid.' );
					}
					$identity = array(
						'type'       => $identity['type'],
						'identifier' => $identity['identifier'],
					);
				}
				unset( $identity );
			}
			$credentials[] = BlueprintCredential::from_array( $record );
		}
		$blueprint = new self(
			array_map(
				static function ( mixed $record ) use ( $legacy ): BlueprintPackage {
					if ( ! is_array( $record ) ) {
						throw new InvalidArgumentException( 'The portability blueprint is invalid.' );
					}
					if ( $legacy ) {
						if ( array_keys( $record ) !== array( 'type', 'identifier', 'display_name', 'provider', 'provider_repository_id', 'repository', 'branch', 'subdirectory' ) ) {
							throw new InvalidArgumentException( 'The portability blueprint is invalid.' );
						}
						$record['source'] = array(
							'kind'         => 'branch',
							'branch'       => $record['branch'],
							'subdirectory' => $record['subdirectory'],
						);
						unset( $record['branch'], $record['subdirectory'] );
					}
					return BlueprintPackage::from_array( $record );
				},
				$data['packages']
			),
			$credentials
		);

		if ( ! hash_equals( $blueprint->encode( $legacy ), $json ) ) {
			throw new InvalidArgumentException( 'The portability blueprint is invalid.' );
		}

		return $blueprint;
	}

	public function canonical_json(): string {
		return $this->encode( false );
	}

	private function encode( bool $legacy ): string {
		$packages    = $this->packages;
		$credentials = $this->credentials;
		usort( $packages, static fn( BlueprintPackage $left, BlueprintPackage $right ): int => array( $left->type, $left->identifier ) <=> array( $right->type, $right->identifier ) );
		usort( $credentials, static fn( BlueprintCredential $left, BlueprintCredential $right ): int => $left->to_array() <=> $right->to_array() );
		$records = array_map( static fn( BlueprintPackage $package ): array => $package->to_array(), $packages );
		foreach ( $records as $index => &$record ) {
			if ( null !== $packages[ $index ]->requirements ) {
				foreach ( array( 'observed_identity', 'declarations' ) as $key ) {
					if ( array() === $packages[ $index ]->requirements[ $key ] ) {
						$record['requirements'][ $key ] = (object) array();
					}
				}
			}
		}
		unset( $record );
		if ( $legacy ) {
			foreach ( $records as $index => &$record ) {
				unset( $record['source'] );
				$record['branch']       = $packages[ $index ]->branch;
				$record['subdirectory'] = $packages[ $index ]->subdirectory;
			}
			unset( $record );
		}
		$document = array(
			'format'      => self::FORMAT,
			'version'     => $legacy ? 1 : self::VERSION,
			'packages'    => $records,
			'credentials' => array_map( static fn( BlueprintCredential $credential ): array => $credential->to_array(), $credentials ),
		);
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Canonical archive identity requires native throwing JSON encoding outside WordPress.
			$json = json_encode( $legacy ? $document : self::sort_keys( $document ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( strlen( $json ) > self::MAX_BYTES ) {
				throw new InvalidArgumentException( 'The portability blueprint is invalid.' );
			}

			return $json;
		} catch ( JsonException ) {
			throw new InvalidArgumentException( 'The portability blueprint is invalid.' );
		}
	}

	private static function sort_keys( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as &$item ) {
			$item = self::sort_keys( $item );
		}
		unset( $item );
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		return $value;
	}
}
