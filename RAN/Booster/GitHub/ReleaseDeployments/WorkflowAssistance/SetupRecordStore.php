<?php

declare(strict_types=1);

namespace RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

/** Stores exact, bounded, non-secret setup pull-request evidence. */
final class SetupRecordStore {
	private const OPTION                   = 'ran_booster_release_deployments_setup_records';
	private const ASSESSMENT_OPTION        = 'ran_booster_release_deployments_assessment_observations';
	private const FAILURE_OPTION           = 'ran_booster_release_deployments_failure_history';
	private const MAX_RECORDS              = 100;
	private const MAX_OBSERVATIONS         = 100;
	private const MAX_FAILURES             = 100;
	private const MAX_HISTORY              = 12;
	private const FIELDS                   = array(
		'schema_version',
		'operation',
		'repo_id',
		'repository',
		'package_type',
		'package_identifier',
		'source_revision',
		'default_branch',
		'base_sha',
		'setup_branch',
		'head_sha',
		'pr_number',
		'profile_id',
		'template_repo_name',
		'template_repo_id',
		'template_release_id',
		'template_tag',
		'template_commit',
		'template_asset_id',
		'template_asset_name',
		'template_asset_size',
		'template_asset_digest',
		'manifest_digest',
		'receipt_digest',
		'consumer_api',
		'pack_version',
		'bundle_hash',
		'changed_path_hash',
	);
	private const LEGACY_FIELDS            = array( 'repo_id', 'repository', 'package_type', 'package_identifier', 'source_revision', 'default_branch', 'setup_branch', 'head_sha', 'pr_number' );
	private const OBSERVATION_FIELDS       = array( 'kind', 'repository_id', 'package_type', 'package_identifier', 'source_revision', 'observed_at' );
	private const OBSERVATION_STATUSES     = array( 'existing_automation_detected', 'booster_setup_verified', 'no_recognisable_automation' );
	private const LEGACY_FAILURE_FIELDS    = array( 'operation', 'outcome_code', 'failure_stage', 'package_type', 'package_identifier', 'source_revision', 'repository_id', 'correlation_reference', 'recorded_at' );
	private const FAILURE_FIELDS           = array( 'operation', 'outcome_code', 'failure_stage', 'package_type', 'package_identifier', 'source_revision', 'repository_id', 'diagnostic_code', 'diagnostic_available', 'correlation_reference', 'recorded_at' );
	private const FAILURE_STAGES           = array( 'credential_authorisation', 'release_preflight', 'repository_snapshot', 'template_pack', 'preview_storage', 'repository_mutation', 'local_persistence', 'unexpected' );
	private const FAILURE_DIAGNOSTIC_CODES = array( 'diagnostic_detail_unavailable', 'credential_authorisation_unavailable', 'preflight_contract_unavailable', 'provider_unavailable', 'no_releases', 'invalid_release', 'release_identity_mismatch', 'release_incompatible', 'release_version_mismatch', 'package_header_missing', 'package_header_invalid', 'package_archive_unreadable', 'package_zip_extension_unavailable', 'package_archive_size_invalid', 'package_archive_too_large', 'package_archive_path_unsafe', 'package_archive_path_duplicate', 'package_archive_root_invalid', 'package_archive_entry_duplicate', 'package_archive_entry_limit', 'release_version_invalid', 'package_update_uri_missing', 'package_update_uri_invalid', 'package_compatibility_missing', 'package_compatibility_invalid', 'package_header_ambiguous', 'release_automation_detected', 'repository_snapshot_unavailable', 'template_pack_unavailable', 'preview_storage_unavailable', 'repository_mutation_unverified', 'local_persistence_unavailable', 'unexpected_runtime_failure' );

	private ?string $claimToken = null;
	/** @return array<string,int|string>|null */
	public function find( string $repositoryId ): ?array {
		$raw = $this->raw( $repositoryId );
		if ( null === $raw || 2 !== ( $raw['schema_version'] ?? null ) ) {
			return null;
		}
		$record = $this->normalize( $raw );
		return null !== $record && hash_equals( $repositoryId, $record['repo_id'] ) ? $record : null;
	}
	/** Any existing value owns its repository key, including legacy or malformed evidence. */
	public function occupied( string $repositoryId ): bool {
		if ( ! $this->text( $repositoryId, 191 ) ) {
			return false;
		}
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) && array_key_exists( $repositoryId, $all );
	}
	/** Serialize setup and the shared record write before any provider mutation. @return string|null Opaque exact-owner claim. */
	public function claim( string $repositoryId, string $type, string $identifier, int $revision, bool $allowExistingRecord = false ): ?string {
		if ( ! $this->number( $repositoryId ) || ! in_array( $type, array( 'plugin', 'theme' ), true )
			|| ! $this->text( $identifier, 255 ) || $revision < 1 || null !== $this->claimToken ) {
			return null;
		}
		$claim = bin2hex( random_bytes( 16 ) );
		if ( ! $this->acquireClaimLock() ) {
			return null;
		}
		$this->claimToken = $claim;
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION, 'options' );
		}
		$existing = $this->find( $repositoryId );
		if ( $allowExistingRecord
			? null === $existing || ! hash_equals( $type, $existing['package_type'] )
				|| ! hash_equals( $identifier, $existing['package_identifier'] ) || $revision !== $existing['source_revision']
			: $this->occupied( $repositoryId ) ) {
			$this->releaseClaim( $repositoryId, $claim );
			return null;
		}
		return $claim;
	}

	/** Release only the exact connection-local lock held by this store instance. */
	public function releaseClaim( string $repositoryId, string $claim ): bool {
		if ( ! $this->number( $repositoryId ) || null === $this->claimToken || ! hash_equals( $this->claimToken, $claim ) ) {
			return false;
		}
		$released = $this->releaseClaimLock();
		// A failed RELEASE_LOCK can mean the connection has already dropped and
		// released its advisory lock. Never carry a stale in-memory ownership claim
		// into a later local evidence write.
		$this->claimToken = null;
		return $released;
	}

	private function acquireClaimLock(): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-local advisory lock serializes remote setup and the shared evidence option.
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::claimLockName() ) );
		return '' === trim( (string) ( $wpdb->last_error ?? '' ) ) && '1' === (string) $result;
	}

	private function releaseClaimLock(): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-local advisory locks are released automatically if the database connection closes.
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::claimLockName() ) );
		return '' === trim( (string) ( $wpdb->last_error ?? '' ) ) && '1' === (string) $result;
	}

	private static function claimLockName(): string {
		global $wpdb;
		$options = is_object( $wpdb ) && isset( $wpdb->options ) ? (string) $wpdb->options : 'unavailable';
		return 'ran_booster_release_workflow_' . substr( hash( 'sha256', $options ), 0, 32 );
	}
	/** Refresh only the monotonic Core source revision for the same exact package record. @return array<string,int|string>|null */
	public function refreshSourceRevision( string $repositoryId, string $type, string $identifier, int $revision ): ?array {
		$acquired = null === $this->claimToken;
		if ( $acquired && ! $this->acquireClaimLock() ) {
			return null;
		}
		$readback = null;
		$released = true;
		try {
			$this->refreshRecordCache();
			$record = $this->find( $repositoryId );
			if ( null !== $record && $revision > $record['source_revision']
				&& hash_equals( $type, $record['package_type'] ) && hash_equals( $identifier, $record['package_identifier'] ) ) {
				$record['source_revision'] = $revision;
				$readback                  = $this->persistRecord( $record ) ? $this->find( $repositoryId ) : null;
			}
		} finally {
			if ( $acquired ) {
				$released = $this->releaseClaimLock();
			}
		}
		return $released ? $readback : null;
	}
	/** Schema 1 is display-only evidence and never mutation authority. @return array<string,int|string>|null */
	public function legacyEvidence( string $repositoryId, string $type, string $identifier, int $revision ): ?array {
		$raw = $this->raw( $repositoryId );
		if ( null === $raw ) {
			return null;
		}
		if ( array_keys( $raw ) !== self::LEGACY_FIELDS || ! $this->legacyValid( $raw )
			|| ! hash_equals( $repositoryId, $raw['repo_id'] ) || ! hash_equals( $type, $raw['package_type'] )
			|| ! hash_equals( $identifier, $raw['package_identifier'] ) || $revision !== $raw['source_revision'] ) {
			return array(
				'schema_version' => 1,
				'unsupported'    => 1,
			);
		}
		return array(
			'schema_version' => 1,
			'repository'     => $raw['repository'],
			'setup_branch'   => $raw['setup_branch'],
			'pr_number'      => $raw['pr_number'],
		);
	}
	/** @param array<string,mixed> $record */
	public function save( array $record ): bool {
		$record = $this->normalize( $record );
		if ( null === $record ) {
			return false;
		}
		$acquired = null === $this->claimToken;
		if ( $acquired && ! $this->acquireClaimLock() ) {
			return false;
		}
		$saved    = false;
		$released = true;
		try {
			$this->refreshRecordCache();
			$saved = $this->persistRecord( $record );
		} finally {
			if ( $acquired ) {
				$released = $this->releaseClaimLock();
			}
		}
		return $saved && $released;
	}

	/** @param array<string,int|string> $record */
	private function persistRecord( array $record ): bool {
		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) || count( $all ) > self::MAX_RECORDS
			|| ( ! array_key_exists( $record['repo_id'], $all ) && count( $all ) >= self::MAX_RECORDS ) ) {
			return false;
		}
		if ( array_key_exists( $record['repo_id'], $all ) ) {
			$existing = is_array( $all[ $record['repo_id'] ] ) ? $this->normalize( $all[ $record['repo_id'] ] ) : null;
			if ( null === $existing || ! hash_equals( $record['repo_id'], $existing['repo_id'] )
				|| ! hash_equals( $record['package_type'], $existing['package_type'] )
				|| ! hash_equals( $record['package_identifier'], $existing['package_identifier'] ) ) {
				return false;
			}
		}
		$all[ $record['repo_id'] ] = $record;
		update_option( self::OPTION, $all, false );
		return $this->find( $record['repo_id'] ) === $record;
	}

	private function refreshRecordCache(): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION, 'options' );
		}
	}
	private function refreshFailureCache(): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::FAILURE_OPTION, 'options' );
		}
	}
	private function refreshAssessmentCache(): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::ASSESSMENT_OPTION, 'options' );
		}
	}
	/** @param array<string,mixed> $observation */
	public function saveAssessmentObservation( array $observation ): bool {
		$observation = $this->normalizeObservation( $observation );
		if ( null === $observation ) {
			return false;
		}
		$acquired = null === $this->claimToken;
		if ( $acquired && ! $this->acquireClaimLock() ) {
			return false;
		}
		$saved    = false;
		$released = true;
		try {
			$this->refreshAssessmentCache();
			$saved = $this->persistAssessmentObservation( $observation );
		} finally {
			if ( $acquired ) {
				$released = $this->releaseClaimLock();
			}
		}
		return $saved && $released;
	}
	/** @param array<string,int|string> $observation */
	private function persistAssessmentObservation( array $observation ): bool {
		$all = $this->assessmentObservations();
		if ( null === $all ) {
			return false;
		}
		foreach ( $all as $index => $existing ) {
			if ( $this->sameAssessmentPackage( $observation, $existing ) ) {
				unset( $all[ $index ] );
			}
		}
		$all = array_values( $all );
		if ( count( $all ) >= self::MAX_OBSERVATIONS ) {
			$oldest = 0;
			foreach ( $all as $index => $existing ) {
				if ( $existing['observed_at'] < $all[ $oldest ]['observed_at'] ) {
					$oldest = $index;
				}
			}
			array_splice( $all, $oldest, 1 );
		}
		$all[] = $observation;
		return update_option( self::ASSESSMENT_OPTION, $all, false )
			&& $this->assessmentObservation( $observation['repository_id'], $observation['package_type'], $observation['package_identifier'], $observation['source_revision'] ) === $observation;
	}
	/** @return array<string,int|string>|null */
	public function assessmentObservation( string $repositoryId, string $type, string $identifier, int $sourceRevision ): ?array {
		if ( ! $this->number( $repositoryId ) || ! in_array( $type, array( 'plugin', 'theme' ), true ) || ! $this->text( $identifier, 255 ) || $sourceRevision < 1 ) {
			return null;
		}
		$all = $this->assessmentObservations();
		if ( null === $all ) {
			return null;
		}
		foreach ( $all as $observation ) {
			if ( $repositoryId === $observation['repository_id'] && $type === $observation['package_type']
				&& $identifier === $observation['package_identifier'] && $sourceRevision === $observation['source_revision'] ) {
				return $observation;
			}
		}
		return null;
	}
	/** @param array<string,mixed> $failure */
	public function recordFailure( array $failure ): bool {
		$failure = $this->normalizeFailure( $failure );
		if ( null === $failure ) {
			return false;
		}
		$acquired = null === $this->claimToken;
		if ( $acquired && ! $this->acquireClaimLock() ) {
			return false;
		}
		$recorded = false;
		$released = true;
		try {
			$this->refreshFailureCache();
			$recorded = $this->persistFailure( $failure );
		} finally {
			if ( $acquired ) {
				$released = $this->releaseClaimLock();
			}
		}
		return $recorded && $released;
	}
	/** @param array<string,int|string> $failure */
	private function persistFailure( array $failure ): bool {
		$history = get_option( self::FAILURE_OPTION, array() );
		if ( ! is_array( $history ) || ! array_is_list( $history ) || count( $history ) > self::MAX_FAILURES ) {
			return false;
		}
		foreach ( $history as $index => $entry ) {
			$entry = is_array( $entry ) ? $this->normalizeFailure( $entry ) : null;
			if ( null === $entry ) {
				return false;
			}
			$history[ $index ] = $entry;
		}
		$history[] = $failure;
		$history   = array_slice( $history, -self::MAX_FAILURES );
		if ( ! update_option( self::FAILURE_OPTION, $history, false ) ) {
			return false;
		}
		$readback = $this->failureHistory( $failure['repository_id'], $failure['package_type'], $failure['package_identifier'], $failure['source_revision'] );
		return in_array( $failure, $readback, true );
	}
	/** @return list<array<string,int|string>> */
	public function failureHistory( string $repositoryId, string $type, string $identifier, int $sourceRevision ): array {
		if ( ! $this->number( $repositoryId ) || ! in_array( $type, array( 'plugin', 'theme' ), true ) || ! $this->text( $identifier, 255 ) || $sourceRevision < 1 ) {
			return array();
		}
		$history = get_option( self::FAILURE_OPTION, array() );
		if ( ! is_array( $history ) || ! array_is_list( $history ) || count( $history ) > self::MAX_FAILURES ) {
			return array();
		}
		$matched = array();
		foreach ( $history as $entry ) {
			$entry = is_array( $entry ) ? $this->normalizeFailure( $entry ) : null;
			if ( null === $entry ) {
				return array();
			}
			if ( hash_equals( $repositoryId, $entry['repository_id'] ) && hash_equals( $type, $entry['package_type'] )
				&& hash_equals( $identifier, $entry['package_identifier'] ) && $sourceRevision === $entry['source_revision'] ) {
				$matched[] = $entry;
			}
		}
		return array_slice( $matched, -self::MAX_HISTORY );
	}
	/** @return array<string,mixed>|null */
	private function raw( string $repositoryId ): ?array {
		if ( ! $this->text( $repositoryId, 191 ) ) {
			return null;
		}
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) && count( $all ) <= self::MAX_RECORDS && is_array( $all[ $repositoryId ] ?? null ) ? $all[ $repositoryId ] : null;
	}
	/** @param array<string,mixed> $raw @return array<string,int|string>|null */
	private function normalize( array $raw ): ?array {
		if ( array_keys( $raw ) !== self::FIELDS || 2 !== ( $raw['schema_version'] ?? null )
			|| ! in_array( $raw['operation'] ?? null, array( 'bootstrap', 'template_update' ), true )
			|| ! $this->legacyValid( array_intersect_key( $raw, array_flip( self::LEGACY_FIELDS ) ) )
			|| ! str_starts_with( $raw['setup_branch'], 'ran-booster/release-setup-v2-' )
			|| ! $this->hash( $raw['base_sha'] ?? null, 40 )
			|| ! in_array( $raw['profile_id'] ?? null, array( 'source-ready-wordpress-plugin/2', 'source-ready-wordpress-theme/2' ), true )
			|| 'RocketsAreNostalgic/ran-booster-release-bootstrap-templates' !== ( $raw['template_repo_name'] ?? null )
			|| '1322743261' !== ( $raw['template_repo_id'] ?? null )
			|| ! $this->positiveInt( $raw['template_release_id'] ?? null ) || ! $this->textValue( $raw['template_tag'] ?? null, 191 )
			|| ! $this->hash( $raw['template_commit'] ?? null, 40 ) || ! $this->positiveInt( $raw['template_asset_id'] ?? null )
			|| 'ran-booster-release-bootstrap-templates.zip' !== ( $raw['template_asset_name'] ?? null )
			|| ! $this->positiveInt( $raw['template_asset_size'] ?? null ) || $raw['template_asset_size'] > 2097152
			|| ! $this->hash( $raw['template_asset_digest'] ?? null, 64 ) || ! $this->hash( $raw['manifest_digest'] ?? null, 64 )
			|| ! $this->hash( $raw['receipt_digest'] ?? null, 64 ) || TemplatePack::CONSUMER_API !== ( $raw['consumer_api'] ?? null )
			|| ! is_string( $raw['pack_version'] ?? null ) || 1 !== preg_match( '/\A[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?\z/D', $raw['pack_version'] )
			|| ! $this->hash( $raw['bundle_hash'] ?? null, 64 ) || ! $this->hash( $raw['changed_path_hash'] ?? null, 64 ) ) {
			return null;
		}
		/** @var array<string,int|string> $raw */
		return $raw;
	}
	/** @return list<array<string,int|string>>|null */
	private function assessmentObservations(): ?array {
		$all = get_option( self::ASSESSMENT_OPTION, array() );
		if ( ! is_array( $all ) || ! array_is_list( $all ) || count( $all ) > self::MAX_OBSERVATIONS ) {
			return null;
		}
		$seen = array();
		foreach ( $all as $index => $observation ) {
			$observation = is_array( $observation ) ? $this->normalizeObservation( $observation ) : null;
			if ( null === $observation ) {
				return null;
			}
			$key = $observation['repository_id'] . "\0" . $observation['package_type'] . "\0" . $observation['package_identifier'] . "\0" . $observation['source_revision'];
			if ( isset( $seen[ $key ] ) ) {
				return null;
			}
			$seen[ $key ]  = true;
			$all[ $index ] = $observation;
		}
		return $all;
	}
	/** @param array<string,mixed> $observation @return array<string,int|string>|null */
	private function normalizeObservation( array $observation ): ?array {
		if ( array_keys( $observation ) !== self::OBSERVATION_FIELDS || ! in_array( $observation['kind'] ?? null, self::OBSERVATION_STATUSES, true )
			|| ! $this->number( $observation['repository_id'] ?? null )
			|| ! in_array( $observation['package_type'] ?? null, array( 'plugin', 'theme' ), true )
			|| ! $this->textValue( $observation['package_identifier'] ?? null, 255 ) || ! $this->positiveInt( $observation['source_revision'] ?? null )
			|| ! is_string( $observation['observed_at'] ?? null ) || 1 !== preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $observation['observed_at'] ) ) {
			return null;
		}
		/** @var array<string,int|string> $observation */
		return $observation;
	}
	/** @param array<string,int|string> $first @param array<string,int|string> $second */
	private function sameAssessmentPackage( array $first, array $second ): bool {
		return $first['repository_id'] === $second['repository_id'] && $first['package_type'] === $second['package_type']
			&& $first['package_identifier'] === $second['package_identifier'];
	}
	/** @param array<string,mixed> $failure @return array<string,int|string>|null */
	private function normalizeFailure( array $failure ): ?array {
		if ( array_keys( $failure ) === self::LEGACY_FAILURE_FIELDS ) {
			$failure = array_merge(
				array_slice( $failure, 0, 7, true ),
				array(
					'diagnostic_code'      => 'diagnostic_detail_unavailable',
					'diagnostic_available' => false,
				),
				array_slice( $failure, 7, null, true )
			);
		}
		if ( array_keys( $failure ) !== self::FAILURE_FIELDS
			|| ! in_array( $failure['operation'] ?? null, array( 'inspect', 'setup', 'outcome', 'update_inspect', 'update_setup' ), true )
			|| ! is_string( $failure['outcome_code'] ?? null ) || 1 !== preg_match( '/\Aworkflow_[a-z0-9_]{1,55}\z/D', $failure['outcome_code'] )
			|| ! in_array( $failure['failure_stage'] ?? null, self::FAILURE_STAGES, true )
			|| ! in_array( $failure['package_type'] ?? null, array( 'plugin', 'theme' ), true )
			|| ! $this->textValue( $failure['package_identifier'] ?? null, 255 ) || ! $this->positiveInt( $failure['source_revision'] ?? null )
			|| ! $this->number( $failure['repository_id'] ?? null )
			|| ! in_array( $failure['diagnostic_code'] ?? null, self::FAILURE_DIAGNOSTIC_CODES, true )
			|| ! is_bool( $failure['diagnostic_available'] ?? null )
			|| ! is_string( $failure['correlation_reference'] ?? null ) || 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', $failure['correlation_reference'] )
			|| ! is_string( $failure['recorded_at'] ?? null ) || 1 !== preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $failure['recorded_at'] ) ) {
			return null;
		}
		/** @var array<string,int|string> $failure */
		return $failure;
	}
	/** @param array<string,mixed> $raw */
	private function legacyValid( array $raw ): bool {
		return count( $raw ) === count( self::LEGACY_FIELDS ) && $this->number( $raw['repo_id'] ?? null )
			&& $this->repository( $raw['repository'] ?? null ) && in_array( $raw['package_type'] ?? null, array( 'plugin', 'theme' ), true )
			&& $this->textValue( $raw['package_identifier'] ?? null, 255 ) && $this->positiveInt( $raw['source_revision'] ?? null )
			&& $this->branch( $raw['default_branch'] ?? null ) && $this->branch( $raw['setup_branch'] ?? null )
			&& ( str_starts_with( $raw['setup_branch'], 'ran-booster/release-setup-v1-' ) || str_starts_with( $raw['setup_branch'], 'ran-booster/release-setup-v2-' ) )
			&& $this->hash( $raw['head_sha'] ?? null, 40 ) && $this->positiveInt( $raw['pr_number'] ?? null );
	}
	private function repository( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '#\A[A-Za-z0-9][A-Za-z0-9_.-]{0,99}/[A-Za-z0-9][A-Za-z0-9_.-]{0,99}\z#D', $value );
	}
	private function branch( mixed $value ): bool {
		return $this->textValue( $value, 191 ) && ! str_contains( $value, '..' ) && ! str_contains( $value, '@{' )
			&& 0 === preg_match( '/[ ~^:?*\[\\\\]|]|(?:\A|\/)\.|\.(?:lock)?\z|\/\//', $value );
	}
	private function number( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[1-9][0-9]*\z/D', $value );
	}
	private function positiveInt( mixed $value ): bool {
		return is_int( $value ) && $value > 0;
	}
	private function hash( mixed $value, int $length ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[a-f0-9]{' . $length . '}\z/D', $value );
	}
	private function textValue( mixed $value, int $limit ): bool {
		return is_string( $value ) && $this->text( $value, $limit );
	}
	private function text( string $value, int $limit ): bool {
		return '' !== trim( $value ) && strlen( $value ) <= $limit && 1 === preg_match( '//u', $value ) && 0 === preg_match( '/[\x00-\x1F\x7F]/', $value );
	}
}
