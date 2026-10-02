<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

/** Secret-free package and repository facts required by provider workflow operations. */
readonly class RepositoryReleaseWorkflowTarget {
	public function __construct(
		private string $type,
		private string $identifier,
		private int $source_revision,
		private string $provider_repository_id,
		private string $package_root = '',
		private string $installed_version = '',
		private string $expected_update_uri = ''
	) {
		if ( ! in_array( $this->type, array( 'plugin', 'theme' ), true )
			|| ! $this->text( $this->identifier, 255 )
			|| $this->source_revision < 1
			|| ! $this->text( $this->provider_repository_id, 191 )
			|| ! $this->optional_text( $this->package_root, 255 )
			|| ! $this->optional_text( $this->installed_version, 255 )
			|| ! $this->optional_url( $this->expected_update_uri ) ) {
			throw new InvalidArgumentException( 'Release workflow target is invalid.' );
		}
	}

	public function type(): string {
		return $this->type;
	}

	public function identifier(): string {
		return $this->identifier;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
	public function source_revision(): int {
		return $this->source_revision;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
	public function provider_repository_id(): string {
		return $this->provider_repository_id;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
	public function package_root(): string {
		return $this->package_root;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
	public function installed_version(): string {
		return $this->installed_version;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
	public function expected_update_uri(): string {
		return $this->expected_update_uri;
	}

	private function text( string $value, int $limit ): bool {
		return '' !== trim( $value )
			&& strlen( $value ) <= $limit
			&& 1 === preg_match( '//u', $value )
			&& 0 === preg_match( '/[\x00-\x1F\x7F]/', $value );
	}

	private function optional_text( string $value, int $limit ): bool {
		return '' === $value || $this->text( $value, $limit );
	}

	private function optional_url( string $value ): bool {
		if ( '' === $value ) {
			return true;
		}
		if ( strlen( $value ) > 255 || 0 !== preg_match( '/[\x00-\x20\x7F]/', $value ) || false === filter_var( $value, FILTER_VALIDATE_URL ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Provider DTO validation is deliberately WordPress-independent.
		$parts = parse_url( $value );

		return is_array( $parts )
			&& 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] )
			&& is_string( $parts['host'] ?? null )
			&& '' !== $parts['host'];
	}
}
