<?php

declare(strict_types=1);

namespace RAN\Portability;

use InvalidArgumentException;
use RAN\PackageSubdirectory;
use RAN\Package;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\RepositoryLocator;

final readonly class BlueprintPackage {

	public function __construct(
		public string $type,
		public string $identifier,
		public string $displayName,
		public string $provider,
		public string $providerRepositoryId,
		public string $repository,
		public string $branch,
		public ?string $subdirectory
	) {
		if ( null !== $subdirectory && strlen( $subdirectory ) > 255 ) {
			throw new InvalidArgumentException( 'The portability package record is invalid.' );
		}
		$normalizedSubdirectory = PackageSubdirectory::normalize( $subdirectory );
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true )
			|| ! self::safePackageIdentifier( $identifier, $type )
			|| '' === $displayName || trim( $displayName ) !== $displayName || strlen( $displayName ) > 191 || 1 !== preg_match( '//u', $displayName ) || preg_match( '/[\x00-\x1F\x7F]/', $displayName )
			|| '' === $providerRepositoryId || strlen( $providerRepositoryId ) > 191 || 1 !== preg_match( '//u', $providerRepositoryId ) || preg_match( '/[\x00-\x1F\x7F]/', $providerRepositoryId )
			|| '' === $branch || strlen( $branch ) > 255 || 1 !== preg_match( '//u', $branch ) || preg_match( '/[\x00-\x1F\x7F]/', $branch )
			|| $subdirectory !== $normalizedSubdirectory ) {
			throw new InvalidArgumentException( 'The portability package record is invalid.' );
		}

		ProviderCode::parse( $provider );
		self::safeRepositoryLocator( $repository );
	}

	/** @param array<string, mixed> $record */
	public static function fromArray( array $record ): self {
		$keys = array(
			'type',
			'identifier',
			'display_name',
			'provider',
			'provider_repository_id',
			'repository',
			'branch',
			'subdirectory',
		);
		if ( array_keys( $record ) !== $keys || ! is_string( $record['type'] ) || ! is_string( $record['identifier'] ) || ! is_string( $record['display_name'] ) || ! is_string( $record['provider'] ) || ! is_string( $record['provider_repository_id'] ) || ! is_string( $record['repository'] ) || ! is_string( $record['branch'] ) || ( null !== $record['subdirectory'] && ! is_string( $record['subdirectory'] ) ) ) {
			throw new InvalidArgumentException( 'The portability package record is invalid.' );
		}

		return new self( $record['type'], $record['identifier'], $record['display_name'], $record['provider'], $record['provider_repository_id'], $record['repository'], $record['branch'], $record['subdirectory'] );
	}

	public static function fromManagedPackage( string $type, Package $package ): self {
		return new self(
			$type,
			(string) $package->getIdentifier(),
			$package->getDisplayName(),
			(string) $package->getProviderCode(),
			(string) $package->getProviderRepositoryId(),
			(string) $package->getRepository(),
			(string) $package->getBranch(),
			$package->getSubdirectory()
		);
	}

	/** @return array<string, scalar|null> */
	public function toArray(): array {
		return array(
			'type'                   => $this->type,
			'identifier'             => $this->identifier,
			'display_name'           => $this->displayName,
			'provider'               => $this->provider,
			'provider_repository_id' => $this->providerRepositoryId,
			'repository'             => $this->repository,
			'branch'                 => $this->branch,
			'subdirectory'           => $this->subdirectory,
		);
	}

	public function sameManagementAs( self $other ): bool {
		$left  = $this->toArray();
		$right = $other->toArray();
		unset( $left['display_name'], $right['display_name'] );

		return $left === $right;
	}

	private static function safePackageIdentifier( string $identifier, string $type ): bool {
		if ( '' === $identifier || strlen( $identifier ) > 255 || 1 !== preg_match( '//u', $identifier ) || str_starts_with( $identifier, '/' ) || str_contains( $identifier, '\\' ) || preg_match( '/[\x00-\x1F\x7F]/', $identifier ) ) {
			return false;
		}
		if ( 'theme' === $type ) {
			return $identifier === PackageSubdirectory::normalizeSlug( $identifier );
		}
		if ( ! str_ends_with( $identifier, '.php' ) ) {
			return false;
		}
		try {
			return $identifier === PackageSubdirectory::normalize( $identifier );
		} catch ( InvalidArgumentException ) {
			return false;
		}
	}

	private static function safeRepositoryLocator( string $repository ): void {
		RepositoryLocator::requireValid( $repository );

		if ( ! str_contains( $repository, '://' ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This pure contract cannot require a WordPress bootstrap.
		$parts = parse_url( $repository );
		if ( false === $parts || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			throw new InvalidArgumentException( 'The portability package record is invalid.' );
		}
	}
}
