<?php

declare(strict_types=1);

namespace RAN\Portability;

use InvalidArgumentException;
use JsonException;

final readonly class PackageBlueprint {

	public const FORMAT          = 'ran-booster-package-blueprint';
	public const VERSION         = 1;
	public const MAX_BYTES       = 262144;
	public const MAX_PACKAGES    = 128;
	public const MAX_CREDENTIALS = 128;

	/** @param list<BlueprintPackage> $packages @param list<BlueprintCredential> $credentials */
	public function __construct( public array $packages, #[\SensitiveParameter] public array $credentials = array() ) {
		if ( count( $packages ) > self::MAX_PACKAGES || count( $credentials ) > self::MAX_CREDENTIALS ) {
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
			$material = $credential->toArray();
			unset( $material['packages'] );
			try {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure canonical contract with exceptions enabled.
				$encodedMaterial = json_encode( $material, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			} catch ( JsonException ) {
				throw new InvalidArgumentException( 'The portability blueprint is invalid.' );
			}
			$fingerprint = hash( 'sha256', $encodedMaterial );
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
	}

	public static function fromJson( #[\SensitiveParameter] string $json ): self {
		if ( '' === $json || strlen( $json ) > self::MAX_BYTES || 1 !== preg_match( '//u', $json ) ) {
			throw new InvalidArgumentException( 'The portability blueprint is invalid.' );
		}
		try {
			$data = json_decode( $json, true, 16, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw new InvalidArgumentException( 'The portability blueprint is invalid.' );
		}
		if ( ! is_array( $data ) || array_keys( $data ) !== array( 'format', 'version', 'packages', 'credentials' ) || self::FORMAT !== $data['format'] || self::VERSION !== $data['version'] || ! is_array( $data['packages'] ) || ! array_is_list( $data['packages'] ) || ! is_array( $data['credentials'] ) || ! array_is_list( $data['credentials'] ) ) {
			throw new InvalidArgumentException( 'The portability blueprint is invalid.' );
		}
		$credentials = array();
		foreach ( $data['credentials'] as $record ) {
			if ( ! is_array( $record ) ) {
				throw new InvalidArgumentException( 'The portability blueprint is invalid.' );
			}
			$credentials[] = BlueprintCredential::fromArray( $record );
		}
		$blueprint = new self(
			array_map(
				static fn( mixed $record ): BlueprintPackage => is_array( $record ) ? BlueprintPackage::fromArray( $record ) : throw new InvalidArgumentException( 'The portability blueprint is invalid.' ),
				$data['packages']
			),
			$credentials
		);

		if ( ! hash_equals( $blueprint->canonicalJson(), $json ) ) {
			throw new InvalidArgumentException( 'The portability blueprint is invalid.' );
		}

		return $blueprint;
	}

	public function canonicalJson(): string {
		$packages    = $this->packages;
		$credentials = $this->credentials;
		usort( $packages, static fn( BlueprintPackage $left, BlueprintPackage $right ): int => array( $left->type, $left->identifier ) <=> array( $right->type, $right->identifier ) );
		usort( $credentials, static fn( BlueprintCredential $left, BlueprintCredential $right ): int => $left->toArray() <=> $right->toArray() );
		try {
			// The core is deliberately WordPress-independent so it can be unit tested without bootstrapping WordPress.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			$json = json_encode(
				array(
					'format'      => self::FORMAT,
					'version'     => self::VERSION,
					'packages'    => array_map( static fn( BlueprintPackage $package ): array => $package->toArray(), $packages ),
					'credentials' => array_map( static fn( BlueprintCredential $credential ): array => $credential->toArray(), $credentials ),
				),
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
			if ( strlen( $json ) > self::MAX_BYTES ) {
				throw new InvalidArgumentException( 'The portability blueprint is invalid.' );
			}

			return $json;
		} catch ( JsonException ) {
			throw new InvalidArgumentException( 'The portability blueprint is invalid.' );
		}
	}
}
