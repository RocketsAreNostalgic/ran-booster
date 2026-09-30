<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

final readonly class RepositoryReleaseNativeTargetStatus {
	private const RELATIONSHIPS = array( '', 'newer', 'same', 'older', 'invalid' );

	public function __construct(
		public bool $active,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		public string $offeredVersion = '',
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		public string $versionRelationship = '',
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		public ?int $lastCheck = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		public ?int $nextCheck = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		public string $failureCode = '',
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		public string $candidateCode = '',
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		public string $candidateReleaseTag = '',
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		public string $candidateReleaseVersion = '',
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		public string $candidatePackageHeaderVersion = '',
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		public string $candidateProviderReleaseId = ''
	) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		if ( ! in_array( $versionRelationship, self::RELATIONSHIPS, true )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			|| ! self::valid_version( $offeredVersion )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			|| ! self::valid_time( $lastCheck )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			|| ! self::valid_time( $nextCheck )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			|| ! self::valid_code( $failureCode )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			|| ! self::valid_code( $candidateCode )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			|| ! self::valid_text( $candidateReleaseTag, 100 )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			|| ! self::valid_version( $candidateReleaseVersion )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			|| ! self::valid_version( $candidatePackageHeaderVersion )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			|| ! self::valid_text( $candidateProviderReleaseId, 191 ) ) {
			throw new InvalidArgumentException( 'The repository release native target status is invalid.' );
		}
		$candidate_values = array(
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			$candidateCode,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			$candidateReleaseTag,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			$candidateReleaseVersion,
		);
		if ( ( array() !== array_filter( $candidate_values, static fn ( string $value ): bool => '' === $value )
			&& array() !== array_filter( $candidate_values, static fn ( string $value ): bool => '' !== $value ) )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			|| ( '' === $candidateCode && '' !== $candidatePackageHeaderVersion )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			|| ( '' !== $candidateProviderReleaseId
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
				&& ( 'release_identity_verified' !== $candidateCode
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
					|| '' === $candidateReleaseTag
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
					|| '' === $candidateReleaseVersion
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
					|| '' === $candidatePackageHeaderVersion ) ) ) {
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
