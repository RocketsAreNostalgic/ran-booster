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
		string $provider_repository_id,
		int $self_type,
		string $self_package,
		PackageSource $proposed_source,
		bool $lock = false
	): array {
		if ( ! self::identity_is_valid( $provider, $provider_repository_id, $self_type, $self_package ) ) {
			return self::unavailable();
		}
		$this->lifecycle?->require_ready();
		$query = $this->database->prepare(
			'SELECT type, package, source, provider, provider_repository_id FROM %i WHERE provider = %s AND BINARY provider_repository_id = BINARY %s' . ( $lock ? ' FOR UPDATE' : '' ),
			ran_booster_table_name(),
			$provider,
			$provider_repository_id
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared immediately above; exact raw storage rows are authoritative.
		$rows  = $this->database->get_results( $query );
		$error = property_exists( $this->database, 'last_error' ) ? trim( (string) $this->database->last_error ) : '';
		if ( '' !== $error || ! is_array( $rows ) || count( $rows ) !== count( array_filter( $rows, 'is_object' ) ) ) {
			return self::unavailable();
		}

		return self::assess_rows( $rows, $provider, $provider_repository_id, $self_type, $self_package, $proposed_source );
	}

	/**
	 * @param array<array-key, object> $rows
	 * @return array{allowed: bool, code: string, relationship_count: int, release_count: int, owner_type: ?int, owner_package: ?string, other_packages: list<array{type:int,identifier:string}>}
	 */
	public static function assess_rows(
		array $rows,
		string $provider,
		string $provider_repository_id,
		int $self_type,
		string $self_package,
		PackageSource $proposed_source
	): array {
		if ( ! self::identity_is_valid( $provider, $provider_repository_id, $self_type, $self_package ) ) {
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
				|| ! is_string( $row->provider_repository_id ?? null ) || ! hash_equals( $provider_repository_id, $row->provider_repository_id ) ) {
				return self::unavailable();
			}
			$row->type  = (int) $row->type;
			$coordinate = $row->type . "\0" . $row->package;
			if ( isset( $coordinates[ $coordinate ] ) ) {
				return self::unavailable();
			}
			$coordinates[ $coordinate ] = true;
			if ( $self_type === $row->type && hash_equals( $self_package, $row->package ) ) {
				if ( null !== $self ) {
					return self::unavailable();
				}
				$self = $row;
			}
		}

		// Every row survived the complete validation loop and its type was normalized.
		/** @var array<array-key, object{type: 1|2, package: non-empty-string, source: 'branch'|'release_asset', provider: non-empty-string, provider_repository_id: non-empty-string}> $rows */
		/** @var object{type: 1|2, package: non-empty-string, source: 'branch'|'release_asset', provider: non-empty-string, provider_repository_id: non-empty-string}|null $self */
		$others = array();
		foreach ( $rows as $row ) {
			if ( $self_type !== $row->type || ! hash_equals( $self_package, $row->package ) ) {
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
		if ( null !== $release && ( $release->type !== $self_type || ! hash_equals( $release->package, $self_package ) ) ) {
			$result['owner_type']    = $release->type;
			$result['owner_package'] = $release->package;
		}

		if ( PackageSource::BRANCH === $proposed_source
			&& ( 0 === count( $releases ) || ( null !== $self && PackageSource::BRANCH->value === $self->source ) || ( null !== $self && PackageSource::RELEASE_ASSET->value === $self->source ) ) ) {
			$result['allowed'] = true;
			$result['code']    = 'allowed';
			return $result;
		}
		if ( PackageSource::RELEASE_ASSET === $proposed_source
			&& ( 0 === count( $rows ) || ( 1 === count( $rows ) && null !== $self ) ) ) {
			$result['allowed'] = true;
			$result['code']    = 'allowed';
		}

		return $result;
	}

	public function assert_allowed( string $provider, string $repository_id, int $type, string $identifier, PackageSource $source ): void {
		if ( ! self::identity_is_valid( $provider, $repository_id, $type, $identifier ) ) {
			throw PackageStorageFailure::invalid_provider_identity();
		}

		$result = $this->assess( $provider, $repository_id, $type, $identifier, $source );
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
