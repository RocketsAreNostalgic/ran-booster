<?php

declare(strict_types=1);

namespace RAN\Portability;

use InvalidArgumentException;
use RAN\PackageSubdirectory;
use RAN\Package;
use RAN\PackageSource;
use RAN\WordPress\ManagedReleaseConfiguration;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\RepositoryLocator;

final readonly class BlueprintPackage {

	/**
	 * @param array<string, mixed>|null $selection
	 * @param array<string, mixed>|null $requirements
	 */
	public function __construct(
		public string $type,
		public string $identifier,
		public string $display_name,
		public string $provider,
		public string $provider_repository_id,
		public string $repository,
		public string $branch,
		public ?string $subdirectory,
		public ?ManagedReleaseConfiguration $release_configuration = null,
		public ?array $selection = null,
		public ?array $requirements = null
	) {
		if ( null !== $subdirectory && strlen( $subdirectory ) > 255 ) {
			throw new InvalidArgumentException( 'The portability package record is invalid.' );
		}
		$normalized_subdirectory = PackageSubdirectory::normalize( $subdirectory );
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true )
			|| ! self::safe_package_identifier( $identifier, $type )
			|| '' === $display_name || trim( $display_name ) !== $display_name || strlen( $display_name ) > 191 || 1 !== preg_match( '//u', $display_name ) || preg_match( '/[\x00-\x1F\x7F]/', $display_name )
			|| '' === $provider_repository_id || strlen( $provider_repository_id ) > 191 || 1 !== preg_match( '//u', $provider_repository_id ) || preg_match( '/[\x00-\x1F\x7F]/', $provider_repository_id )
			|| ( null === $release_configuration && '' === $branch ) || strlen( $branch ) > 255 || 1 !== preg_match( '//u', $branch ) || preg_match( '/[\x00-\x1F\x7F]/', $branch )
			|| $subdirectory !== $normalized_subdirectory ) {
			throw new InvalidArgumentException( 'The portability package record is invalid.' );
		}

		ProviderCode::parse( $provider );
		self::safe_repository_locator( $repository );
		if ( null !== $release_configuration && ( '' !== $branch || null !== $subdirectory
			|| ( 'theme' === $type && ( 'style.css' !== $release_configuration->metadata_file() || $identifier !== $release_configuration->package_root() ) )
			|| ( 'plugin' === $type && $identifier !== $release_configuration->package_root() . '/' . $release_configuration->metadata_file() ) ) ) {
			throw new InvalidArgumentException( 'The portability package record is invalid.' );
		}
		$this->validate_observations();
	}

	/** @param array<string, mixed> $record */
	public static function from_array( array $record ): self {
		self::keys( $record, array( 'type', 'identifier', 'display_name', 'provider', 'provider_repository_id', 'repository', 'source' ), array( 'selection', 'requirements' ) );
		foreach ( array( 'type', 'identifier', 'display_name', 'provider', 'provider_repository_id', 'repository' ) as $key ) {
			if ( ! is_string( $record[ $key ] ) ) {
				throw new InvalidArgumentException( 'The portability package record is invalid.' );
			}
		}
		$source = $record['source'];
		if ( ! is_array( $source ) ) {
			throw new InvalidArgumentException( 'The portability package record is invalid.' );
		}
		$release      = null;
		$branch       = '';
		$subdirectory = null;
		if ( 'branch' === ( $source['kind'] ?? null ) ) {
			self::keys( $source, array( 'kind', 'branch', 'subdirectory' ) );
			if ( ! is_string( $source['branch'] ) || ( null !== $source['subdirectory'] && ! is_string( $source['subdirectory'] ) ) ) {
				throw new InvalidArgumentException( 'The portability package record is invalid.' );
			}
			$branch       = $source['branch'];
			$subdirectory = $source['subdirectory'];
		} elseif ( 'release_asset' === ( $source['kind'] ?? null ) ) {
			self::keys( $source, array( 'kind', 'channel', 'package_root', 'metadata_file' ) );
			if ( ! is_string( $source['channel'] ) || ! is_string( $source['package_root'] ) || ! is_string( $source['metadata_file'] ) ) {
				throw new InvalidArgumentException( 'The portability package record is invalid.' );
			}
			$release = new ManagedReleaseConfiguration( $source['package_root'], $source['metadata_file'], $source['channel'] );
		} else {
			throw new InvalidArgumentException( 'The portability package record is invalid.' );
		}
		foreach ( array( 'selection', 'requirements' ) as $key ) {
			if ( array_key_exists( $key, $record ) && ! is_array( $record[ $key ] ) ) {
				throw new InvalidArgumentException( 'The portability package record is invalid.' );
			}
		}
		return new self( $record['type'], $record['identifier'], $record['display_name'], $record['provider'], $record['provider_repository_id'], $record['repository'], $branch, $subdirectory, $release, $record['selection'] ?? null, $record['requirements'] ?? null );
	}

	public static function from_managed_package( string $type, Package $package, ?ManagedReleaseConfiguration $release_configuration = null ): self {
		if ( ( PackageSource::RELEASE_ASSET === $package->get_source() ) !== ( null !== $release_configuration ) ) {
			throw new InvalidArgumentException( 'The managed package source configuration is unavailable.' );
		}
		return new self(
			$type,
			(string) $package->get_identifier(),
			$package->get_display_name(),
			(string) $package->get_provider_code(),
			(string) $package->get_provider_repository_id(),
			(string) $package->get_repository(),
			null === $release_configuration ? (string) $package->get_branch() : '',
			$package->get_subdirectory(),
			$release_configuration
		);
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		$record = array(
			'type'                   => $this->type,
			'identifier'             => $this->identifier,
			'display_name'           => $this->display_name,
			'provider'               => $this->provider,
			'provider_repository_id' => $this->provider_repository_id,
			'repository'             => $this->repository,
			'source'                 => null === $this->release_configuration
				? array(
					'kind'         => 'branch',
					'branch'       => $this->branch,
					'subdirectory' => $this->subdirectory,
				)
				: array_merge( array( 'kind' => 'release_asset' ), $this->release_configuration->to_array() ),
		);
		if ( null !== $this->selection ) {
			$record['selection'] = $this->selection;
		}
		if ( null !== $this->requirements ) {
			$record['requirements'] = $this->requirements;
		}
		return $record;
	}

	public function same_management_as( self $other ): bool {
		$left  = $this->to_array();
		$right = $other->to_array();
		unset( $left['display_name'], $right['display_name'], $left['selection'], $right['selection'], $left['requirements'], $right['requirements'] );

		return $left === $right;
	}

	/**
	 * @param array<string, mixed> $value
	 * @param list<string> $required
	 * @param list<string> $optional
	 */
	private static function keys( array $value, array $required, array $optional = array() ): void {
		if ( array_diff( $required, array_keys( $value ) ) || array_diff( array_keys( $value ), array_merge( $required, $optional ) ) ) {
			throw new InvalidArgumentException( 'The portability package record is invalid.' );
		}
	}

	private static function text( mixed $value, int $maximum ): bool {
		return is_string( $value ) && '' !== $value && strlen( $value ) <= $maximum && 1 === preg_match( '//u', $value ) && ! preg_match( '/[\x00-\x1F\x7F]/', $value );
	}

	private static function commit( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/D', $value );
	}

	private static function digest( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[a-f0-9]{64}\z/D', $value );
	}

	private function validate_observations(): void {
		$selection = $this->selection;
		if ( null !== $selection ) {
			self::keys( $selection, null === $this->release_configuration ? array( 'commit' ) : array( 'release_id', 'tag', 'version', 'commit', 'artifact_id', 'artifact_sha256', 'artifact_size' ) );
			if ( ! self::commit( $selection['commit'] ) ) {
				throw new InvalidArgumentException( 'The portability package record is invalid.' );
			}
			if ( null !== $this->release_configuration ) {
				foreach ( array(
					'release_id'  => 191,
					'tag'         => 100,
					'version'     => 100,
					'artifact_id' => 191,
				) as $key => $bound ) {
					if ( ! self::text( $selection[ $key ], $bound ) ) {
						throw new InvalidArgumentException( 'The portability package record is invalid.' );
					}
				}
				if ( ! self::digest( $selection['artifact_sha256'] ) || ! is_int( $selection['artifact_size'] ) || $selection['artifact_size'] < 1 ) {
					throw new InvalidArgumentException( 'The portability package record is invalid.' );
				}
			}
		}
		if ( null === $this->requirements ) {
			return;
		}
		self::keys( $this->requirements, array( 'basis', 'observed_identity', 'declarations' ) );
		$basis        = $this->requirements['basis'];
		$identity     = $this->requirements['observed_identity'];
		$declarations = $this->requirements['declarations'];
		if ( ! in_array( $basis, array( 'installed_headers', 'selected_package' ), true ) || ! is_array( $identity ) || ! is_array( $declarations ) ) {
			throw new InvalidArgumentException( 'The portability package record is invalid.' );
		}
		self::keys( $identity, array(), array( 'version', 'commit', 'artifact_sha256' ) );
		foreach ( $identity as $key => $value ) {
			$valid = match ( $key ) {
				'commit' => self::commit( $value ),
				'artifact_sha256' => self::digest( $value ),
				default => self::text( $value, 100 ),
			};
			if ( ! $valid || ( 'selected_package' === $basis && array_key_exists( $key, $selection ?? array() ) && $value !== $selection[ $key ] ) ) {
				throw new InvalidArgumentException( 'The portability package record is invalid.' );
			}
		}
		$binding = null === $this->release_configuration ? 'commit' : 'artifact_sha256';
		if ( 'selected_package' === $basis && ( null === $selection || ! isset( $identity[ $binding ] ) ) ) {
			throw new InvalidArgumentException( 'The portability package record is invalid.' );
		}
		self::keys( $declarations, array(), array( 'requires_php', 'requires_wordpress', 'plugin' === $this->type ? 'requires_plugins' : 'parent_theme' ) );
		foreach ( $declarations as $key => $value ) {
			if ( 'requires_plugins' === $key ) {
				if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > 128 ) {
					throw new InvalidArgumentException( 'The portability package record is invalid.' );
				}
				$seen = array();
				foreach ( $value as $slug ) {
					if ( ! is_string( $slug ) || strlen( $slug ) > 100 || 1 !== preg_match( '/\A[a-z0-9][a-z0-9-]*\z/D', $slug ) || isset( $seen[ $slug ] ) ) {
						throw new InvalidArgumentException( 'The portability package record is invalid.' );
					}
					$seen[ $slug ] = true;
				}
			} elseif ( null !== $value && ( 'parent_theme' === $key
				? ! self::text( $value, 100 )
				: ( ! is_string( $value ) || strlen( $value ) > 64 || 1 !== preg_match( '/\A[0-9]+(?:\.[0-9]+){0,3}\z/D', $value ) ) ) ) {
				throw new InvalidArgumentException( 'The portability package record is invalid.' );
			}
		}
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
