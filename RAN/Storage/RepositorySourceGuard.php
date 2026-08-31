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
		string $providerRepositoryId,
		int $selfType,
		string $selfPackage,
		PackageSource $proposedSource,
		bool $lock = false
	): array {
		if ( ! self::identityIsValid( $provider, $providerRepositoryId, $selfType, $selfPackage ) ) {
			return self::unavailable();
		}
		$this->lifecycle?->requireReady();
		$query = $this->database->prepare(
			'SELECT type, package, source, provider, provider_repository_id FROM %i WHERE provider = %s AND BINARY provider_repository_id = BINARY %s' . ( $lock ? ' FOR UPDATE' : '' ),
			ran_booster_table_name(),
			$provider,
			$providerRepositoryId
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared immediately above; exact raw storage rows are authoritative.
		$rows  = $this->database->get_results( $query );
		$error = property_exists( $this->database, 'last_error' ) ? trim( (string) $this->database->last_error ) : '';
		if ( '' !== $error || ! is_array( $rows ) || count( $rows ) !== count( array_filter( $rows, 'is_object' ) ) ) {
			return self::unavailable();
		}

		return self::assessRows( $rows, $provider, $providerRepositoryId, $selfType, $selfPackage, $proposedSource );
	}

	/**
	 * @param list<object> $rows
	 * @return array{allowed: bool, code: string, relationship_count: int, release_count: int, owner_type: ?int, owner_package: ?string, other_packages: list<array{type:int,identifier:string}>}
	 */
	public static function assessRows(
		array $rows,
		string $provider,
		string $providerRepositoryId,
		int $selfType,
		string $selfPackage,
		PackageSource $proposedSource
	): array {
		if ( ! self::identityIsValid( $provider, $providerRepositoryId, $selfType, $selfPackage ) ) {
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
				|| ! is_string( $row->provider_repository_id ?? null ) || ! hash_equals( $providerRepositoryId, $row->provider_repository_id ) ) {
				return self::unavailable();
			}
			$row->type  = (int) $row->type;
			$coordinate = $row->type . "\0" . $row->package;
			if ( isset( $coordinates[ $coordinate ] ) ) {
				return self::unavailable();
			}
			$coordinates[ $coordinate ] = true;
			if ( $selfType === $row->type && hash_equals( $selfPackage, $row->package ) ) {
				if ( null !== $self ) {
					return self::unavailable();
				}
				$self = $row;
			}
		}

		$others = array();
		foreach ( $rows as $row ) {
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
		if ( null !== $release && ( $release->type !== $selfType || ! hash_equals( $release->package, $selfPackage ) ) ) {
			$result['owner_type']    = $release->type;
			$result['owner_package'] = $release->package;
		}

		if ( PackageSource::BRANCH === $proposedSource
			&& ( 0 === count( $releases ) || ( null !== $self && $self->source === PackageSource::BRANCH->value ) || ( null !== $self && $self->source === PackageSource::RELEASE_ASSET->value ) ) ) {
			$result['allowed'] = true;
			$result['code']    = 'allowed';
			return $result;
		}
		if ( PackageSource::RELEASE_ASSET === $proposedSource
			&& ( 0 === count( $rows ) || ( 1 === count( $rows ) && null !== $self ) ) ) {
			$result['allowed'] = true;
			$result['code']    = 'allowed';
		}

		return $result;
	}

	public function assertAllowed( string $provider, string $repositoryId, int $type, string $identifier, PackageSource $source ): void {
		if ( ! self::identityIsValid( $provider, $repositoryId, $type, $identifier ) ) {
			throw PackageStorageFailure::invalidProviderIdentity();
		}

		$result = $this->assess( $provider, $repositoryId, $type, $identifier, $source );
		if ( $result['allowed'] ) {
			return;
		}
		if ( 'repository_source_unavailable' === $result['code'] ) {
			throw PackageStorageFailure::queryFailed();
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The validated package identifier is rendered through the dashboard escape boundary.
		throw PackageStorageFailure::repositorySourceConflict( $result['owner_package'] );
	}

	private static function identityIsValid( string $provider, string $providerRepositoryId, int $selfType, string $selfPackage ): bool {
		return '' !== trim( $provider ) && '' !== trim( $providerRepositoryId ) && in_array( $selfType, array( 1, 2 ), true ) && '' !== trim( $selfPackage ) && strlen( $provider ) <= 32 && strlen( $providerRepositoryId ) <= 191 && strlen( $selfPackage ) <= 255 && ! str_contains( $provider, "\0" ) && ! str_contains( $providerRepositoryId, "\0" ) && ! str_contains( $selfPackage, "\0" );
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
