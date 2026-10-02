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
		public string $display_name,
		public string $provider,
		public string $provider_repository_id,
		public string $repository,
		public string $branch,
		public ?string $subdirectory
	) {
		if ( null !== $subdirectory && strlen( $subdirectory ) > 255 ) {
			throw new InvalidArgumentException( 'The portability package record is invalid.' );
		}
		$normalized_subdirectory = PackageSubdirectory::normalize( $subdirectory );
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true )
			|| ! self::safe_package_identifier( $identifier, $type )
			|| '' === $display_name || trim( $display_name ) !== $display_name || strlen( $display_name ) > 191 || 1 !== preg_match( '//u', $display_name ) || preg_match( '/[\x00-\x1F\x7F]/', $display_name )
			|| '' === $provider_repository_id || strlen( $provider_repository_id ) > 191 || 1 !== preg_match( '//u', $provider_repository_id ) || preg_match( '/[\x00-\x1F\x7F]/', $provider_repository_id )
			|| '' === $branch || strlen( $branch ) > 255 || 1 !== preg_match( '//u', $branch ) || preg_match( '/[\x00-\x1F\x7F]/', $branch )
			|| $subdirectory !== $normalized_subdirectory ) {
			throw new InvalidArgumentException( 'The portability package record is invalid.' );
		}

		ProviderCode::parse( $provider );
		self::safe_repository_locator( $repository );
	}

	/** @param array<string, mixed> $record */
	public static function from_array( array $record ): self {
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

	public static function from_managed_package( string $type, Package $package ): self {
		return new self(
			$type,
			(string) $package->get_identifier(),
			$package->get_display_name(),
			(string) $package->get_provider_code(),
			(string) $package->get_provider_repository_id(),
			(string) $package->get_repository(),
			(string) $package->get_branch(),
			$package->get_subdirectory()
		);
	}

	/** @return array<string, scalar|null> */
	public function to_array(): array {
		return array(
			'type'                   => $this->type,
			'identifier'             => $this->identifier,
			'display_name'           => $this->display_name,
			'provider'               => $this->provider,
			'provider_repository_id' => $this->provider_repository_id,
			'repository'             => $this->repository,
			'branch'                 => $this->branch,
			'subdirectory'           => $this->subdirectory,
		);
	}

	public function same_management_as( self $other ): bool {
		$left  = $this->to_array();
		$right = $other->to_array();
		unset( $left['display_name'], $right['display_name'] );

		return $left === $right;
	}

	private static function safe_package_identifier( string $identifier, string $type ): bool {
		if ( '' === $identifier || strlen( $identifier ) > 255 || 1 !== preg_match( '//u', $identifier ) || str_starts_with( $identifier, '/' ) || str_contains( $identifier, '\\' ) || preg_match( '/[\x00-\x1F\x7F]/', $identifier ) ) {
			return false;
		}
		if ( 'theme' === $type ) {
			return PackageSubdirectory::normalize_slug( $identifier ) === $identifier;
		}
		if ( ! str_ends_with( $identifier, '.php' ) ) {
			return false;
		}
		try {
			return PackageSubdirectory::normalize( $identifier ) === $identifier;
		} catch ( InvalidArgumentException ) {
			return false;
		}
	}

	private static function safe_repository_locator( string $repository ): void {
		RepositoryLocator::require_valid( $repository );

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
