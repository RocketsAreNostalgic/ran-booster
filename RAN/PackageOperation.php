<?php

declare(strict_types=1);

namespace RAN; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Core owns the established three-character RAN namespace; WPCS requires four characters.

use InvalidArgumentException;
use RAN\Deployment\DeploymentPolicy;
use RAN\RepositoryProvider\ProviderCode;

/**
 * Validated input for one administrator-initiated package operation.
 */
final readonly class PackageOperation {

	private const OPERATIONS = array( 'install', 'edit', 'update', 'unlink', 'unlink-and-delete' );
	private const TYPES      = array( 'plugin', 'theme' );

	/**
	 * @param array{}|array{source_revision: int}|array{
	 *     provider: string|null,
	 *     provider_repository_id: string|null,
	 *     repository: string|null,
	 *     branch: string|null,
	 *     credential_id: string|null,
	 *     subdirectory: string|null,
	 *     private: bool|null,
	 *     package_slug: string|null,
	 *     deployment_policy: DeploymentPolicy|null,
	 *     source: PackageSource|null,
	 *     source_revision: int|null
	 * } $expected_package
	 */
	private function __construct(
		public string $operation,
		public string $package_type,
		public ?string $identifier,
		public ?string $repository,
		public ?string $branch,
		public ?string $provider_code,
		public ?string $provider_repository_id,
		public string $provider_repository_identity_source,
		public ?string $credential_id,
		public bool $is_private,
		public DeploymentPolicy $deployment_policy,
		public bool $link_only,
		public ?string $subdirectory,
		public ?string $package_slug,
		public ?string $ref,
		public array $expected_package
	) {
	}

	/** @param array<string, mixed> $input */
	public static function from_input( string $action, array $input ): self {
		$parts = self::action_parts( $action );
		if ( null === $parts ) {
			throw new InvalidArgumentException( 'Choose a valid package operation.' );
		}

		[$operation, $package_type] = $parts;
		$link_only                  = 'install' === $operation && isset( $input['dry-run'] );
		$identifier                 = 'install' === $operation
			? ( $link_only && '1' === (string) ( $input['exact_identifier'] ?? '' )
				? self::nullable_scalar( $input[ 'plugin' === $package_type ? 'file' : 'stylesheet' ] ?? null )
				: null )
			: self::required_scalar( $input, 'plugin' === $package_type ? 'file' : 'stylesheet' );

		if ( in_array( $operation, array( 'unlink', 'unlink-and-delete' ), true ) ) {
			$identifier = self::removal_identifier( $identifier, $package_type );
			if ( '1' !== (string) ( $input['confirm_package_removal'] ?? '' ) ) {
				throw new InvalidArgumentException( 'Confirm the package removal before continuing.' );
			}
			$expected_source_revision = self::expected_non_negative_int( $input['expected_source_revision'] ?? null );
			if ( null === $expected_source_revision || $expected_source_revision < 1 ) {
				throw new InvalidArgumentException( 'Refresh the package settings before continuing.' );
			}

			return new self(
				$operation,
				$package_type,
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
				array( 'source_revision' => $expected_source_revision )
			);
		}

		if ( 'update' === $operation ) {
			return new self(
				$operation,
				$package_type,
				$identifier,
				self::required_scalar( $input, 'repository' ),
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
				self::nullable_scalar( $input['ref'] ?? null ),
				self::expected_package( $input )
			);
		}

		$provider_input = $input['provider'] ?? null;
		if ( ! is_string( $provider_input ) ) {
			throw new InvalidArgumentException( 'Choose a repository provider.' );
		}
		try {
			$provider_code = ProviderCode::parse( wp_unslash( $provider_input ) )->value;
		} catch ( \Throwable ) {
			throw new InvalidArgumentException( 'Choose a repository provider.' );
		}

		$credential_id   = isset( $input['credential_id'] ) && is_scalar( $input['credential_id'] )
			? sanitize_text_field( (string) $input['credential_id'] )
			: '';
		$identity_source = isset( $input['provider_repository_identity_source'] ) && is_scalar( $input['provider_repository_identity_source'] )
			? sanitize_key( wp_unslash( (string) $input['provider_repository_identity_source'] ) )
			: '';
		$provider_id     = isset( $input['provider_repository_id'] ) && is_scalar( $input['provider_repository_id'] )
			? self::provider_repository_id( (string) $input['provider_repository_id'] )
			: null;
		$subdirectory    = PackageSubdirectory::normalize( $input['subdirectory'] ?? null );
		$package_slug    = 'install' === $operation
			? ( $link_only
				? PackageSubdirectory::installation_slug( $input['package_slug'] ?? '', $subdirectory )
				: PackageSubdirectory::deployment_slug( $input['package_slug'] ?? '', $subdirectory ) )
			: null;

		return new self(
			$operation,
			$package_type,
			$identifier,
			self::required_scalar( $input, 'repository' ),
			'install' === $operation
				? self::nullable_scalar( $input['branch'] ?? null ) ?? ''
				: self::required_scalar( $input, 'branch' ),
			$provider_code,
			$provider_id,
			in_array( $identity_source, array( 'stored', 'picker', 'manual', 'resolved' ), true ) ? $identity_source : '',
			'' === $credential_id ? null : $credential_id,
			( '1' === (string) ( $input['private'] ?? '0' ) ) || ( 'resolved' !== $identity_source && '' !== $credential_id ),
			self::deployment_policy( $input ),
			$link_only,
			$subdirectory,
			$package_slug,
			null,
			'edit' === $operation ? self::expected_package( $input ) : array()
		);
	}

	public static function update_from_saved_package( self $edit, Package $package ): self {
		$identifier = $package->get_identifier();
		if ( 'edit' !== $edit->operation
			|| ! is_string( $identifier )
			|| '' === $identifier
			|| $identifier !== $edit->identifier
		) {
			throw new InvalidArgumentException( 'The saved package cannot be reinstalled.' );
		}

		return self::from_input(
			'update-' . $edit->package_type,
			array(
				'plugin' === $edit->package_type ? 'file' : 'stylesheet' => $identifier,
				'repository'                      => (string) $package->get_repository(),
				'expected_provider'               => $package->get_provider_code(),
				'expected_provider_repository_id' => $package->get_provider_repository_id(),
				'expected_repository'             => (string) $package->get_repository(),
				'expected_branch'                 => (string) $package->get_branch(),
				'expected_credential_id'          => $package->get_credential_id(),
				'expected_subdirectory'           => (string) $package->get_subdirectory(),
				'expected_private'                => (bool) $package->get_private(),
				'expected_package_slug'           => (string) $package->get_slug(),
				'expected_deployment_policy'      => $package->get_deployment_policy()->value,
				'expected_source'                 => $package->get_source()->value,
				'expected_source_revision'        => (string) $package->get_source_revision(),
			)
		);
	}

	public function is_deployment(): bool {
		return 'update' === $this->operation || ( 'install' === $this->operation && ! $this->link_only );
	}

	/**
	 * @phpstan-assert-if-true array{provider: string, provider_repository_id: string, repository: string, branch: string, credential_id: string, subdirectory: string, private: bool, package_slug: string, deployment_policy: DeploymentPolicy, source: PackageSource, source_revision: int} $this->expected_package
	 */
	public function has_expected_package(): bool {
		return 11 === count( $this->expected_package ) && ! in_array( null, $this->expected_package, true );
	}

	public function get_expected_source_revision(): ?int {
		$revision = $this->expected_package['source_revision'] ?? null;

		return is_int( $revision ) ? $revision : null;
	}

	/** @return array{string, string}|null */
	private static function action_parts( string $action ): ?array {
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
	private static function deployment_policy( array $input ): DeploymentPolicy {
		$policy = $input['deployment_policy'] ?? DeploymentPolicy::MANUAL->value;
		if ( ! is_string( $policy ) ) {
			throw new InvalidArgumentException( 'Choose a valid deployment policy.' );
		}
		try {
			return DeploymentPolicy::from_database( $policy );
		} catch ( InvalidArgumentException ) {
			throw new InvalidArgumentException( 'Choose a valid deployment policy.' );
		}
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{
	 *     provider: string|null,
	 *     provider_repository_id: string|null,
	 *     repository: string|null,
	 *     branch: string|null,
	 *     credential_id: string|null,
	 *     subdirectory: string|null,
	 *     private: bool|null,
	 *     package_slug: string|null,
	 *     deployment_policy: DeploymentPolicy|null,
	 *     source: PackageSource|null,
	 *     source_revision: int|null
	 * }
	 */
	private static function expected_package( array $input ): array {
		return array(
			'provider'               => self::nullable_scalar( $input['expected_provider'] ?? null ),
			'provider_repository_id' => self::nullable_opaque_scalar( $input['expected_provider_repository_id'] ?? null ),
			'repository'             => self::nullable_scalar( $input['expected_repository'] ?? null ),
			'branch'                 => self::nullable_scalar( $input['expected_branch'] ?? null ),
			'credential_id'          => self::present_scalar( $input, 'expected_credential_id' ),
			'subdirectory'           => self::present_scalar( $input, 'expected_subdirectory' ),
			'private'                => self::expected_private( $input ),
			'package_slug'           => self::nullable_scalar( $input['expected_package_slug'] ?? null ),
			'deployment_policy'      => isset( $input['expected_deployment_policy'] ) && is_scalar( $input['expected_deployment_policy'] )
				? DeploymentPolicy::tryFrom( trim( (string) $input['expected_deployment_policy'] ) )
				: null,
			'source'                 => isset( $input['expected_source'] ) && is_scalar( $input['expected_source'] )
				? PackageSource::tryFrom( trim( (string) $input['expected_source'] ) )
				: null,
			'source_revision'        => self::expected_non_negative_int( $input['expected_source_revision'] ?? null ),
		);
	}

	private static function expected_non_negative_int( mixed $value ): ?int {
		if ( ! is_scalar( $value ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]*)$/D', trim( (string) $value ) ) ) {
			return null;
		}

		$revision = (int) $value;

		return trim( (string) $value ) === (string) $revision ? $revision : null;
	}

	/** @param array<string, mixed> $input */
	private static function expected_private( array $input ): ?bool {
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
	private static function required_scalar( array $input, string $key ): string {
		if ( ! array_key_exists( $key, $input ) || ! is_scalar( $input[ $key ] ) ) {
			throw new InvalidArgumentException( 'Complete every required package field.' );
		}
		return (string) $input[ $key ];
	}

	private static function nullable_scalar( mixed $value ): ?string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		return '' === $value ? null : $value;
	}

	private static function nullable_opaque_scalar( mixed $value ): ?string {
		$value = is_scalar( $value ) ? (string) $value : '';

		return '' === $value ? null : $value;
	}

	/** @param array<string, mixed> $input */
	private static function present_scalar( array $input, string $key ): ?string {
		return array_key_exists( $key, $input ) && is_scalar( $input[ $key ] )
			? trim( (string) $input[ $key ] )
			: null;
	}

	private static function provider_repository_id( string $value ): ?string {
		$value = wp_strip_all_tags( wp_unslash( $value ), true );
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $value );

		return '' === $value ? null : $value;
	}

	private static function removal_identifier( ?string $identifier, string $package_type ): string {
		$identifier = null === $identifier ? '' : trim( $identifier );
		if ( '' === $identifier
			|| strlen( $identifier ) > 191
			|| str_starts_with( $identifier, '/' )
			|| str_contains( $identifier, '\\' )
			|| preg_match( '/[\x00-\x1F\x7F]/', $identifier ) === 1
			|| preg_match( '#(^|/)\.\.?(/|$)#', $identifier ) === 1
			|| ( 'plugin' === $package_type && ! str_ends_with( strtolower( $identifier ), '.php' ) )
		) {
			throw new InvalidArgumentException( 'Choose a valid managed package.' );
		}

		return $identifier;
	}
}
