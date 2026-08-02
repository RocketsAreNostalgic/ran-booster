<?php

declare(strict_types=1);

namespace RAN\Portability;

use InvalidArgumentException;
use RAN\RepositoryProvider\ProviderCode;

/** A normalized, source-ID-free credential carried only inside an encrypted blueprint. */
final readonly class BlueprintCredential {

	/** @param array<string, mixed> $configuration @param list<array{type:string,identifier:string}> $packages */
	public function __construct(
		public string $provider,
		public string $label,
		public string $kind,
		public array $configuration,
		#[\SensitiveParameter] public string $secret,
		public array $packages
	) {
		ProviderCode::parse( $provider );
		if ( ! self::text( $label, 160 ) || ! self::text( $kind, 64 ) || ! self::text( $secret, 4096 )
			|| ( array() !== $configuration && array_is_list( $configuration ) )
			|| array() === $packages || count( $packages ) > PackageBlueprint::MAX_PACKAGES ) {
			throw new InvalidArgumentException( 'The portability credential record is invalid.' );
		}
		self::canonicalConfiguration( $configuration );
		$identities = array();
		foreach ( $packages as $package ) {
			if ( ! is_array( $package ) || array_keys( $package ) !== array( 'type', 'identifier' )
				|| ! is_string( $package['type'] ) || ! in_array( $package['type'], array( 'plugin', 'theme' ), true )
				|| ! is_string( $package['identifier'] ) || ! self::text( $package['identifier'], 255 )
				|| isset( $identities[ $package['type'] . "\0" . $package['identifier'] ] ) ) {
				throw new InvalidArgumentException( 'The portability credential record is invalid.' );
			}
			$identities[ $package['type'] . "\0" . $package['identifier'] ] = true;
		}
	}

	/** @param array<string, mixed> $record */
	public static function fromArray( #[\SensitiveParameter] array $record ): self {
		if ( array_keys( $record ) !== array( 'provider', 'label', 'kind', 'configuration', 'secret', 'packages' )
			|| ! is_string( $record['provider'] ) || ! is_string( $record['label'] ) || ! is_string( $record['kind'] )
			|| ! is_array( $record['configuration'] )
			|| ! is_string( $record['secret'] ) || ! is_array( $record['packages'] ) || ! array_is_list( $record['packages'] ) ) {
			throw new InvalidArgumentException( 'The portability credential record is invalid.' );
		}

		return new self( $record['provider'], $record['label'], $record['kind'], $record['configuration'], $record['secret'], $record['packages'] );
	}

	/** @return array{provider:string,label:string,kind:string,configuration:array<string,mixed>|object,secret:string,packages:list<array{type:string,identifier:string}>} */
	public function toArray(): array {
		$configuration = self::canonicalConfiguration( $this->configuration );
		$packages      = $this->packages;
		usort( $packages, static fn( array $left, array $right ): int => array( $left['type'], $left['identifier'] ) <=> array( $right['type'], $right['identifier'] ) );

		return array(
			'provider'      => $this->provider,
			'label'         => $this->label,
			'kind'          => $this->kind,
			'configuration' => array() === $configuration ? (object) array() : $configuration,
			'secret'        => $this->secret,
			'packages'      => $packages,
		);
	}

	private static function text( string $value, int $maximum ): bool {
		return '' !== $value && trim( $value ) === $value && strlen( $value ) <= $maximum && 1 === preg_match( '//u', $value ) && ! preg_match( '/[\x00-\x1F\x7F]/', $value );
	}

	/** @param array<string, mixed> $configuration @return array<string, mixed> */
	private static function canonicalConfiguration( array $configuration ): array {
		$nodes = 0;
		/** @var array<string, mixed> $result */
		$result = self::canonicalValue( $configuration, 0, $nodes );

		return $result;
	}

	private static function canonicalValue( mixed $value, int $depth, int &$nodes ): mixed {
		if ( ++$nodes > 128 || $depth > 4 ) {
			throw new InvalidArgumentException( 'The portability credential record is invalid.' );
		}
		if ( is_string( $value ) ) {
			if ( strlen( $value ) > 512 || 1 !== preg_match( '//u', $value ) || preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
				throw new InvalidArgumentException( 'The portability credential record is invalid.' );
			}
			return $value;
		}
		if ( null === $value || is_bool( $value ) || is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) ) {
			return $value;
		}
		if ( ! is_array( $value ) || count( $value ) > 16 ) {
			throw new InvalidArgumentException( 'The portability credential record is invalid.' );
		}
		$list   = array_is_list( $value );
		$result = array();
		foreach ( $value as $key => $item ) {
			if ( ! $list && ( ! is_string( $key ) || ! self::text( $key, 64 ) ) ) {
				throw new InvalidArgumentException( 'The portability credential record is invalid.' );
			}
			$result[ $key ] = self::canonicalValue( $item, $depth + 1, $nodes );
		}
		if ( ! $list ) {
			ksort( $result, SORT_STRING );
		}

		return $result;
	}
}
