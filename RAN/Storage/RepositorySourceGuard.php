<?php

declare(strict_types=1);

namespace RAN\Storage;

use RAN\PackageSource;

/**
 * Decides the permitted source shape for one exact provider repository.
 *
 * This deliberately reads database rows, rather than an installed-package or
 * administration projection: disabled and inactive records still constrain
 * the repository source shape.
 */
final class RepositorySourceGuard {

	public function __construct(
		private ?object $database = null,
		private ?Database $lifecycle = null
	) {
		if ( null === $this->database ) {
			global $wpdb;
			$this->database = $wpdb;
		}
		$this->lifecycle = $this->lifecycle ?? new Database( $this->database );
	}

	/**
	 * @return array{allowed: bool, code: string, relationship_count: int, release_count: int, owner_type: ?int, owner_package: ?string, other_packages: list<array{type:int,identifier:string}>}
	 */
	public function assess(
		string $provider,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		string $providerRepositoryId,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		int $selfType,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		string $selfPackage,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		PackageSource $proposedSource,
		bool $lock = false
	): array {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		if ( ! self::identity_is_valid( $provider, $providerRepositoryId, $selfType, $selfPackage ) ) {
			return self::unavailable();
		}
		$this->lifecycle?->requireReady();
		$query = $this->database->prepare(
			'SELECT type, package, source, provider, provider_repository_id FROM %i WHERE provider = %s AND BINARY provider_repository_id = BINARY %s' . ( $lock ? ' FOR UPDATE' : '' ),
			ran_booster_table_name(),
			$provider,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
			$providerRepositoryId
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared immediately above; exact raw storage rows are authoritative.
		$rows  = $this->database->get_results( $query );
		$error = property_exists( $this->database, 'last_error' ) ? trim( (string) $this->database->last_error ) : '';
		if ( '' !== $error || ! is_array( $rows ) || count( $rows ) !== count( array_filter( $rows, 'is_object' ) ) ) {
			return self::unavailable();
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		return self::assessRows( $rows, $provider, $providerRepositoryId, $selfType, $selfPackage, $proposedSource );
	}

	/**
	 * @param list<object> $rows
	 * @return array{allowed: bool, code: string, relationship_count: int, release_count: int, owner_type: ?int, owner_package: ?string, other_packages: list<array{type:int,identifier:string}>}
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
	public static function assessRows(
		array $rows,
		string $provider,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		string $providerRepositoryId,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		int $selfType,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		string $selfPackage,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		PackageSource $proposedSource
	): array {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		if ( ! self::identity_is_valid( $provider, $providerRepositoryId, $selfType, $selfPackage ) ) {
			return self::unavailable();
		}

		$self        = null;
		$coordinates = array();
		foreach ( $rows as $row ) {
			if ( ! is_object( $row )
				|| ! in_array( $row->type ?? null, array( 1, 2, '1', '2' ), true )
				|| ! is_string( $row->package ?? null ) || '' === $row->package
				|| ! is_string( $row->source ?? null ) || ! in_array( $row->source, array( PackageSource::BRANCH->value, PackageSource::RELEASE_ASSET->value ), true )
				|| ! is_string( $row->provider ?? null ) || ! hash_equals( $provider, $row->provider )
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
				|| ! is_string( $row->provider_repository_id ?? null ) || ! hash_equals( $providerRepositoryId, $row->provider_repository_id ) ) {
				return self::unavailable();
			}
			$row->type  = (int) $row->type;
			$coordinate = $row->type . "\0" . $row->package;
			if ( isset( $coordinates[ $coordinate ] ) ) {
				return self::unavailable();
			}
			$coordinates[ $coordinate ] = true;
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
			if ( $selfType === $row->type && hash_equals( $selfPackage, $row->package ) ) {
				if ( null !== $self ) {
					return self::unavailable();
				}
				$self = $row;
			}
		}

		$others = array();
		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
			if ( $selfType !== $row->type || ! hash_equals( $selfPackage, $row->package ) ) {
				$others[] = array(
					'type'       => $row->type,
					'identifier' => $row->package,
				);
			}
		}
		$releases = array_values( array_filter( $rows, static fn ( object $row ): bool => PackageSource::RELEASE_ASSET->value === $row->source ) );
		$release  = 1 === count( $releases ) ? $releases[0] : null;
		$result   = array(
			'allowed'            => false,
			'code'               => 0 < count( $releases ) ? 'repository_release_owner_exists' : 'repository_source_conflict',
			'relationship_count' => count( $rows ),
			'release_count'      => count( $releases ),
			'owner_type'         => null,
			'owner_package'      => null,
			'other_packages'     => array_slice( $others, 0, 10 ),
		);
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		if ( null !== $release && ( $release->type !== $selfType || ! hash_equals( $release->package, $selfPackage ) ) ) {
			$result['owner_type']    = $release->type;
			$result['owner_package'] = $release->package;
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		if ( PackageSource::BRANCH === $proposedSource
			&& ( 0 === count( $releases ) || ( null !== $self && PackageSource::BRANCH->value === $self->source ) || ( null !== $self && PackageSource::RELEASE_ASSET->value === $self->source ) ) ) {
			$result['allowed'] = true;
			$result['code']    = 'allowed';
			return $result;
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		if ( PackageSource::RELEASE_ASSET === $proposedSource
			&& ( 0 === count( $rows ) || ( 1 === count( $rows ) && null !== $self ) ) ) {
			$result['allowed'] = true;
			$result['code']    = 'allowed';
		}

		return $result;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public/protected caller contract; retain public named-parameter names.
	public function assertAllowed( string $provider, string $repositoryId, int $type, string $identifier, PackageSource $source ): void {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		if ( ! self::identity_is_valid( $provider, $repositoryId, $type, $identifier ) ) {
			throw PackageStorageFailure::invalid_provider_identity();
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		$result = $this->assess( $provider, $repositoryId, $type, $identifier, $source );
		if ( $result['allowed'] ) {
			return;
		}
		if ( 'repository_source_unavailable' === $result['code'] ) {
			throw PackageStorageFailure::query_failed();
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The validated package identifier is rendered through the dashboard escape boundary.
		throw PackageStorageFailure::repository_source_conflict( $result['owner_package'] );
	}

	private static function identity_is_valid( string $provider, string $provider_repository_id, int $self_type, string $self_package ): bool {
		return '' !== trim( $provider ) && '' !== trim( $provider_repository_id ) && in_array( $self_type, array( 1, 2 ), true ) && '' !== trim( $self_package ) && strlen( $provider ) <= 32 && strlen( $provider_repository_id ) <= 191 && strlen( $self_package ) <= 255 && ! str_contains( $provider, "\0" ) && ! str_contains( $provider_repository_id, "\0" ) && ! str_contains( $self_package, "\0" );
	}

	/** @return array{allowed: false, code: 'repository_source_unavailable', relationship_count: 0, release_count: 0, owner_type: null, owner_package: null, other_packages: list<array{type:int,identifier:string}>} */
	private static function unavailable(): array {
		return array(
			'allowed'            => false,
			'code'               => 'repository_source_unavailable',
			'relationship_count' => 0,
			'release_count'      => 0,
			'owner_type'         => null,
			'owner_package'      => null,
			'other_packages'     => array(),
		);
	}
}
