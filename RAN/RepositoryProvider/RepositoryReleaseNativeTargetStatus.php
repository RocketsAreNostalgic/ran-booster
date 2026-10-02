<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

final readonly class RepositoryReleaseNativeTargetStatus {
	private const RELATIONSHIPS = array( '', 'newer', 'same', 'older', 'invalid' );

	public function __construct(
		public bool $active,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		public string $offered_version = '',
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		public string $version_relationship = '',
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		public ?int $last_check = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		public ?int $next_check = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		public string $failure_code = '',
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		public string $candidate_code = '',
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		public string $candidate_release_tag = '',
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		public string $candidate_release_version = '',
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		public string $candidate_package_header_version = '',
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		public string $candidate_provider_release_id = ''
	) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		if ( ! in_array( $version_relationship, self::RELATIONSHIPS, true )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			|| ! self::valid_version( $offered_version )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			|| ! self::valid_time( $last_check )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			|| ! self::valid_time( $next_check )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			|| ! self::valid_code( $failure_code )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			|| ! self::valid_code( $candidate_code )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			|| ! self::valid_text( $candidate_release_tag, 100 )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			|| ! self::valid_version( $candidate_release_version )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			|| ! self::valid_version( $candidate_package_header_version )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			|| ! self::valid_text( $candidate_provider_release_id, 191 ) ) {
			throw new InvalidArgumentException( 'The repository release native target status is invalid.' );
		}
		$candidate_values = array(
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			$candidate_code,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			$candidate_release_tag,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			$candidate_release_version,
		);
		if ( ( array() !== array_filter( $candidate_values, static fn ( string $value ): bool => '' === $value )
			&& array() !== array_filter( $candidate_values, static fn ( string $value ): bool => '' !== $value ) )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			|| ( '' === $candidate_code && '' !== $candidate_package_header_version )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			|| ( '' !== $candidate_provider_release_id
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
				&& ( 'release_identity_verified' !== $candidate_code
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
					|| '' === $candidate_release_tag
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
					|| '' === $candidate_release_version
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
					|| '' === $candidate_package_header_version ) ) ) {
			throw new InvalidArgumentException( 'The repository release native target status is invalid.' );
		}
	}

	private static function valid_time( ?int $value ): bool {
		return null === $value || $value > 0;
	}

	private static function valid_code( string $value ): bool {
		return '' === $value || 1 === preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/D', $value );
	}

	private static function valid_version( string $value ): bool {
		return '' === $value || 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,63}\z/D', $value );
	}

	private static function valid_text( string $value, int $maximum_length ): bool {
		return strlen( $value ) <= $maximum_length && 0 === preg_match( '/[\x00-\x1F\x7F]/', $value );
	}
}
