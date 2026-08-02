<?php

declare(strict_types=1);

namespace RAN;

use InvalidArgumentException;
use RAN\Deployment\DeploymentPolicy;
use RAN\RepositoryProvider\ProviderCode;

/**
 * Validated input for one administrator-initiated package operation.
 */
final readonly class PackageOperation {

	private const OPERATIONS = array( 'install', 'edit', 'update', 'unlink', 'unlink-and-delete' );
	private const TYPES      = array( 'plugin', 'theme' );

	private function __construct(
		public string $operation,
		public string $packageType,
		public ?string $identifier,
		public ?string $repository,
		public ?string $branch,
		public ?string $providerCode,
		public ?string $providerRepositoryId,
		public string $providerRepositoryIdentitySource,
		public ?string $credentialId,
		public bool $private,
		public DeploymentPolicy $deploymentPolicy,
		public bool $linkOnly,
		public ?string $subdirectory,
		public ?string $packageSlug,
		public ?string $ref,
		public array $expectedPackage
	) {
	}

	/** @param array<string, mixed> $input */
	public static function fromInput( string $action, array $input ): self {
		$parts = self::actionParts( $action );
		if ( null === $parts ) {
			throw new InvalidArgumentException( 'Choose a valid package operation.' );
		}

		[$operation, $packageType] = $parts;
		$linkOnly                  = 'install' === $operation && isset( $input['dry-run'] );
		$identifier                = 'install' === $operation
			? ( $linkOnly && '1' === (string) ( $input['exact_identifier'] ?? '' )
				? self::nullableScalar( $input[ 'plugin' === $packageType ? 'file' : 'stylesheet' ] ?? null )
				: null )
			: self::requiredScalar( $input, 'plugin' === $packageType ? 'file' : 'stylesheet' );

		if ( in_array( $operation, array( 'unlink', 'unlink-and-delete' ), true ) ) {
			$identifier = self::removalIdentifier( $identifier, $packageType );
			if ( '1' !== (string) ( $input['confirm_package_removal'] ?? '' ) ) {
				throw new InvalidArgumentException( 'Confirm the package removal before continuing.' );
			}
			$expectedSourceRevision = self::expectedNonNegativeInt( $input['expected_source_revision'] ?? null );
			if ( null === $expectedSourceRevision || $expectedSourceRevision < 1 ) {
				throw new InvalidArgumentException( 'Refresh the package settings before continuing.' );
			}

			return new self(
				$operation,
				$packageType,
				$identifier,
				null,
				null,
				null,
				null,
				'',
				null,
				false,
				DeploymentPolicy::MANUAL,
				false,
				null,
				null,
				null,
				array( 'source_revision' => $expectedSourceRevision )
			);
		}

		if ( 'update' === $operation ) {
			return new self(
				$operation,
				$packageType,
				$identifier,
				self::requiredScalar( $input, 'repository' ),
				null,
				null,
				null,
				'',
				null,
				false,
				DeploymentPolicy::MANUAL,
				false,
				null,
				null,
				self::nullableScalar( $input['ref'] ?? null ),
				self::expectedPackage( $input )
			);
		}

		$providerInput = $input['provider'] ?? null;
		if ( ! is_string( $providerInput ) ) {
			throw new InvalidArgumentException( 'Choose a repository provider.' );
		}
		try {
			$providerCode = ProviderCode::parse( wp_unslash( $providerInput ) )->value;
		} catch ( \Throwable ) {
			throw new InvalidArgumentException( 'Choose a repository provider.' );
		}

		$credentialId   = isset( $input['credential_id'] ) && is_scalar( $input['credential_id'] )
			? sanitize_text_field( (string) $input['credential_id'] )
			: '';
		$identitySource = isset( $input['provider_repository_identity_source'] ) && is_scalar( $input['provider_repository_identity_source'] )
			? sanitize_key( wp_unslash( (string) $input['provider_repository_identity_source'] ) )
			: '';
		$providerId     = isset( $input['provider_repository_id'] ) && is_scalar( $input['provider_repository_id'] )
			? self::providerRepositoryId( (string) $input['provider_repository_id'] )
			: null;
		$subdirectory   = PackageSubdirectory::normalize( $input['subdirectory'] ?? null );
		$packageSlug    = 'install' === $operation
			? ( $linkOnly
				? PackageSubdirectory::installationSlug( $input['package_slug'] ?? '', $subdirectory )
				: PackageSubdirectory::deploymentSlug( $input['package_slug'] ?? '', $subdirectory ) )
			: null;

		return new self(
			$operation,
			$packageType,
			$identifier,
			self::requiredScalar( $input, 'repository' ),
			'install' === $operation
				? self::nullableScalar( $input['branch'] ?? null ) ?? ''
				: self::requiredScalar( $input, 'branch' ),
			$providerCode,
			$providerId,
			in_array( $identitySource, array( 'stored', 'picker', 'manual', 'resolved' ), true ) ? $identitySource : '',
			'' === $credentialId ? null : $credentialId,
			'1' === (string) ( $input['private'] ?? '0' ) || ( 'resolved' !== $identitySource && '' !== $credentialId ),
			self::deploymentPolicy( $input ),
			$linkOnly,
			$subdirectory,
			$packageSlug,
			null,
			'edit' === $operation ? self::expectedPackage( $input ) : array()
		);
	}

	public static function updateFromSavedPackage( self $edit, Package $package ): self {
		$identifier = $package->getIdentifier();
		if ( 'edit' !== $edit->operation
			|| ! is_string( $identifier )
			|| '' === $identifier
			|| $identifier !== $edit->identifier
		) {
			throw new InvalidArgumentException( 'The saved package cannot be reinstalled.' );
		}

		return self::fromInput(
			'update-' . $edit->packageType,
			array(
				'plugin' === $edit->packageType ? 'file' : 'stylesheet' => $identifier,
				'repository'                      => (string) $package->getRepository(),
				'expected_provider'               => $package->getProviderCode(),
				'expected_provider_repository_id' => $package->getProviderRepositoryId(),
				'expected_repository'             => (string) $package->getRepository(),
				'expected_branch'                 => (string) $package->getBranch(),
				'expected_credential_id'          => $package->getCredentialId(),
				'expected_subdirectory'           => (string) $package->getSubdirectory(),
				'expected_private'                => (bool) $package->getPrivate(),
				'expected_package_slug'           => (string) $package->getSlug(),
				'expected_deployment_policy'      => $package->getDeploymentPolicy()->value,
				'expected_source'                 => $package->getSource()->value,
				'expected_source_revision'        => (string) $package->getSourceRevision(),
			)
		);
	}

	public function isDeployment(): bool {
		return 'update' === $this->operation || ( 'install' === $this->operation && ! $this->linkOnly );
	}

	public function hasExpectedPackage(): bool {
		return 11 === count( $this->expectedPackage ) && ! in_array( null, $this->expectedPackage, true );
	}

	public function getExpectedSourceRevision(): ?int {
		$revision = $this->expectedPackage['source_revision'] ?? null;

		return is_int( $revision ) ? $revision : null;
	}

	/** @return array{string, string}|null */
	private static function actionParts( string $action ): ?array {
		if ( str_starts_with( $action, 'unlink-delete-' ) ) {
			$type = substr( $action, strlen( 'unlink-delete-' ) );

			return in_array( $type, self::TYPES, true )
				? array( 'unlink-and-delete', $type )
				: null;
		}

		$parts = explode( '-', $action, 2 );

		return 2 === count( $parts )
			&& in_array( $parts[0], self::OPERATIONS, true )
			&& in_array( $parts[1], self::TYPES, true )
				? array( $parts[0], $parts[1] )
				: null;
	}

	/** @param array<string, mixed> $input */
	private static function deploymentPolicy( array $input ): DeploymentPolicy {
		$policy = $input['deployment_policy'] ?? DeploymentPolicy::MANUAL->value;
		if ( ! is_string( $policy ) ) {
			throw new InvalidArgumentException( 'Choose a valid deployment policy.' );
		}
		try {
			return DeploymentPolicy::fromDatabase( $policy );
		} catch ( InvalidArgumentException ) {
			throw new InvalidArgumentException( 'Choose a valid deployment policy.' );
		}
	}

	/** @param array<string, mixed> $input */
	private static function expectedPackage( array $input ): array {
		return array(
			'provider'               => self::nullableScalar( $input['expected_provider'] ?? null ),
			'provider_repository_id' => self::nullableOpaqueScalar( $input['expected_provider_repository_id'] ?? null ),
			'repository'             => self::nullableScalar( $input['expected_repository'] ?? null ),
			'branch'                 => self::nullableScalar( $input['expected_branch'] ?? null ),
			'credential_id'          => self::presentScalar( $input, 'expected_credential_id' ),
			'subdirectory'           => self::presentScalar( $input, 'expected_subdirectory' ),
			'private'                => self::expectedPrivate( $input ),
			'package_slug'           => self::nullableScalar( $input['expected_package_slug'] ?? null ),
			'deployment_policy'      => isset( $input['expected_deployment_policy'] ) && is_scalar( $input['expected_deployment_policy'] )
				? DeploymentPolicy::tryFrom( trim( (string) $input['expected_deployment_policy'] ) )
				: null,
			'source'                 => isset( $input['expected_source'] ) && is_scalar( $input['expected_source'] )
				? PackageSource::tryFrom( trim( (string) $input['expected_source'] ) )
				: null,
			'source_revision'        => self::expectedNonNegativeInt( $input['expected_source_revision'] ?? null ),
		);
	}

	private static function expectedNonNegativeInt( mixed $value ): ?int {
		if ( ! is_scalar( $value ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]*)$/D', trim( (string) $value ) ) ) {
			return null;
		}

		$revision = (int) $value;

		return (string) $revision === trim( (string) $value ) ? $revision : null;
	}

	/** @param array<string, mixed> $input */
	private static function expectedPrivate( array $input ): ?bool {
		if ( ! array_key_exists( 'expected_private', $input ) ) {
			return null;
		}
		return match ( $input['expected_private'] ) {
			true, 1, '1'  => true,
			false, 0, '0' => false,
			default       => null,
		};
	}

	/** @param array<string, mixed> $input */
	private static function requiredScalar( array $input, string $key ): string {
		if ( ! array_key_exists( $key, $input ) || ! is_scalar( $input[ $key ] ) ) {
			throw new InvalidArgumentException( 'Complete every required package field.' );
		}
		return (string) $input[ $key ];
	}

	private static function nullableScalar( mixed $value ): ?string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		return '' === $value ? null : $value;
	}

	private static function nullableOpaqueScalar( mixed $value ): ?string {
		$value = is_scalar( $value ) ? (string) $value : '';

		return '' === $value ? null : $value;
	}

	/** @param array<string, mixed> $input */
	private static function presentScalar( array $input, string $key ): ?string {
		return array_key_exists( $key, $input ) && is_scalar( $input[ $key ] )
			? trim( (string) $input[ $key ] )
			: null;
	}

	private static function providerRepositoryId( string $value ): ?string {
		$value = wp_strip_all_tags( wp_unslash( $value ), true );
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $value );

		return '' === $value ? null : $value;
	}

	private static function removalIdentifier( ?string $identifier, string $packageType ): string {
		$identifier = null === $identifier ? '' : trim( $identifier );
		if ( '' === $identifier
			|| strlen( $identifier ) > 191
			|| str_starts_with( $identifier, '/' )
			|| str_contains( $identifier, '\\' )
			|| preg_match( '/[\x00-\x1F\x7F]/', $identifier ) === 1
			|| preg_match( '#(^|/)\.\.?(/|$)#', $identifier ) === 1
			|| ( 'plugin' === $packageType && ! str_ends_with( strtolower( $identifier ), '.php' ) )
		) {
			throw new InvalidArgumentException( 'Choose a valid managed package.' );
		}

		return $identifier;
	}
}
