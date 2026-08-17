<?php

declare(strict_types=1);

namespace RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

/** Stores exact, bounded, non-secret setup pull-request evidence. */
final class SetupRecordStore {
	private const OPTION        = 'ran_booster_release_deployments_setup_records';
	private const MAX_RECORDS   = 100;
	private const FIELDS        = array(
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
	private const LEGACY_FIELDS = array( 'repo_id', 'repository', 'package_type', 'package_identifier', 'source_revision', 'default_branch', 'setup_branch', 'head_sha', 'pr_number' );
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
		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) || count( $all ) > self::MAX_RECORDS
			|| ( ! array_key_exists( $record['repo_id'], $all ) && count( $all ) >= self::MAX_RECORDS ) ) {
			return false;
		}
		if ( array_key_exists( $record['repo_id'], $all ) ) {
			$existing = is_array( $all[ $record['repo_id'] ] ) ? $this->normalize( $all[ $record['repo_id'] ] ) : null;
			if ( null === $existing || ! hash_equals( $record['repo_id'], $existing['repo_id'] ) ) {
				return false;
			}
		}
		$all[ $record['repo_id'] ] = $record;
		update_option( self::OPTION, $all, false );
		return $this->find( $record['repo_id'] ) === $record;
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
