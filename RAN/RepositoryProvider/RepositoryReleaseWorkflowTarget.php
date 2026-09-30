<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

/** Secret-free package and repository facts required by provider workflow operations. */
readonly class RepositoryReleaseWorkflowTarget {
	public function __construct(
		private string $type,
		private string $identifier,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		private int $sourceRevision,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		private string $providerRepositoryId,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		private string $packageRoot = '',
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		private string $installedVersion = '',
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		private string $expectedUpdateUri = ''
	) {
		if ( ! in_array( $this->type, array( 'plugin', 'theme' ), true )
			|| ! $this->text( $this->identifier, 255 )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
			|| $this->sourceRevision < 1
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
			|| ! $this->text( $this->providerRepositoryId, 191 )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
			|| ! $this->optional_text( $this->packageRoot, 255 )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
			|| ! $this->optional_text( $this->installedVersion, 255 )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
			|| ! $this->optional_url( $this->expectedUpdateUri ) ) {
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
	public function sourceRevision(): int {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		return $this->sourceRevision;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
	public function providerRepositoryId(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		return $this->providerRepositoryId;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
	public function packageRoot(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		return $this->packageRoot;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
	public function installedVersion(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		return $this->installedVersion;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
	public function expectedUpdateUri(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		return $this->expectedUpdateUri;
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
