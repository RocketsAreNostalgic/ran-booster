<?php

declare(strict_types=1);

namespace RAN\Admin\ReleaseManagement;

use RAN\AddOn\ReleaseTracking\ProspectiveReleaseFacade;
use Throwable;

/** @internal Owns untrusted prospective values, result projection and exact facade calls. */
final class ProspectiveReleaseOperations {
	/** @var \Closure(string, array<string, mixed>, string): \RAN\AddOn\ReleaseTracking\ProspectiveReleaseResult */
	private readonly \Closure $readCandidates;

	/** @param callable(string, array<string, mixed>, string): \RAN\AddOn\ReleaseTracking\ProspectiveReleaseResult $readCandidates */
	public function __construct( private readonly ProspectiveReleaseFacade $prospective, callable $readCandidates ) {
		$this->readCandidates = \Closure::fromCallable( $readCandidates );
	}

	public function nonceAction( string $operation, string $type ): string {
		return $this->prospective->nonceAction( $operation, $type );
	}

	/** @return list<string> */
	public function supportedProviderCodes( string $type ): array {
		return $this->prospective->supportedProviderCodes( $type );
	}

	/** @param array<string, mixed> $repository @return array{type:string,identifier:string,code:string,successful:bool,data:array<mixed>} */
	public function listCandidates( string $type, array $repository, string $channel ): array {
		$outcome    = static fn ( string $code, bool $successful, array $data = array() ): array => array(
			'type'       => in_array( $type, array( 'plugin', 'theme' ), true ) ? $type : 'plugin',
			'identifier' => '',
			'code'       => $code,
			'successful' => $successful,
			'data'       => $data,
		);
		$repository = $this->normalizeProspectiveRepository( $repository );
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) || null === $repository || ! in_array( $channel, array( 'stable', 'prerelease' ), true ) ) {
			return $outcome( 'invalid_request', false );
		}
		try {
			$result = ( $this->readCandidates )( $type, $repository, $channel );
		} catch ( Throwable ) {
			return $outcome( 'unable_to_check', false );
		}
		$data  = $this->normalizeCandidateListData( $result->data() );
		$codes = array( 'runtime_unsupported', 'unsupported_provider', 'no_releases', 'unable_to_check' );
		if ( ! $result->successful() && in_array( $result->code(), $codes, true ) ) {
			return $outcome( $result->code(), false );
		}
		if ( $result->successful() && 'release_candidates_available' === $result->code() && isset( $data['candidates'] ) && count( $data['candidates'] ) > 0 && count( $data['candidates'] ) <= 8 && ( ! isset( $data['channel'] ) || hash_equals( $channel, (string) $data['channel'] ) ) ) {
			return $outcome( 'release_candidates_available', true, $data );
		}
		return $outcome( 'operation_failed', false );
	}

	/** @param array<string, mixed> $untrustedRepository */
	public function execute(
		string $operation,
		string $type,
		array $untrustedRepository,
		int $releaseId,
		string $tag,
		string $fingerprint,
		string $channel,
		string $nonce
	): array {
		$repository = $this->normalizeProspectiveRepository( $untrustedRepository );
		$outcome    = static fn ( string $code, bool $successful, array $data = array() ): array => array(
			'type'       => in_array( $type, array( 'plugin', 'theme' ), true ) ? $type : 'plugin',
			'identifier' => is_string( $data['identifier'] ?? null ) ? $data['identifier'] : '',
			'code'       => $code,
			'successful' => $successful,
			'data'       => $data,
		);

		if ( ! in_array( $operation, array( 'list_candidates', 'inspect', 'install' ), true )
			|| ! in_array( $type, array( 'plugin', 'theme' ), true )
			|| null === $repository
			|| ! in_array( $channel, array( 'stable', 'prerelease' ), true )
			|| ( 'list_candidates' !== $operation && ( $releaseId < 1 || ! $this->validReleaseTag( $tag ) ) )
			|| ( 'install' === $operation && ! $this->validFingerprint( $fingerprint ) ) ) {
			return $outcome( 'invalid_request', false );
		}

		$result = match ( $operation ) {
			'list_candidates' => $this->prospective->listCandidates( $type, $repository, $channel, $nonce ),
			'inspect' => $this->prospective->inspect( $type, $repository, $releaseId, $tag, $channel, $nonce ),
			'install' => $this->prospective->install( $type, $repository, $releaseId, $tag, $fingerprint, $channel, $nonce ),
		};
		$code       = $result->code();
		$data       = 'list_candidates' === $operation
			? $this->normalizeCandidateListData( $result->data() )
			: $this->normalizeProspectiveData( $result->data() );
		$successful = $result->successful();
		if ( ! $this->validProspectiveResult( $operation, $code, $successful, $data )
			|| ( isset( $data['channel'] ) && ! hash_equals( $channel, (string) $data['channel'] ) )
			|| ( 'inspect' === $operation
				&& $successful
				&& ( $releaseId !== ( $data['release_id'] ?? null )
					|| ! hash_equals( $tag, (string) ( $data['tag'] ?? '' ) ) ) ) ) {
			return $outcome( 'operation_failed', false );
		}

		return $outcome( $code, $successful, $data );
	}

	/**
	 * @param array<mixed> $data
	 * @return array{candidates?:list<array{release_id:int,tag:string,version:string,prerelease:bool,published_at:string,expected_asset_names:list<string>}>,channel?:string}
	 */
	private function normalizeCandidateListData( array $data ): array {
		$rawCandidates = is_array( $data['candidates'] ?? null ) ? $data['candidates'] : null;
		if ( null === $rawCandidates || count( $rawCandidates ) > 8 ) {
			return array();
		}
		$candidates = array();
		foreach ( $rawCandidates as $rawCandidate ) {
			if ( ! is_array( $rawCandidate ) ) {
				return array();
			}
			$releaseId = $rawCandidate['release_id'] ?? null;
			$tag       = $rawCandidate['tag'] ?? null;
			$version   = $rawCandidate['version'] ?? null;
			$preview   = $rawCandidate['prerelease'] ?? null;
			$published = $rawCandidate['published_at'] ?? null;
			$assets    = $rawCandidate['expected_asset_names'] ?? null;
			if ( ! is_int( $releaseId )
				|| $releaseId < 1
				|| ! $this->validReleaseTag( $tag )
				|| ! $this->validVersion( $version )
				|| ! is_bool( $preview )
				|| ! is_string( $published )
				|| strlen( $published ) > 40
				|| 1 !== preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:.+-]{5,30}Z?\z/D', $published )
				|| ! is_array( $assets )
				|| count( $assets ) > 8 ) {
				return array();
			}
			$assetNames = array();
			foreach ( $assets as $assetName ) {
				if ( ! is_string( $assetName )
					|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,190}\z/D', $assetName ) ) {
					return array();
				}
				$assetNames[] = $assetName;
			}
			$candidates[] = array(
				'release_id'           => $releaseId,
				'tag'                  => $tag,
				'version'              => $version,
				'prerelease'           => $preview,
				'published_at'         => $published,
				'expected_asset_names' => $assetNames,
			);
		}
		$safe = array( 'candidates' => $candidates );
		if ( isset( $data['channel'] )
			&& is_string( $data['channel'] )
			&& in_array( $data['channel'], array( 'stable', 'prerelease' ), true ) ) {
			$safe['channel'] = $data['channel'];
		}

		return $safe;
	}

	/** @param array<string, mixed> $repository @return array<string, string>|null */
	private function normalizeProspectiveRepository( array $repository ): ?array {
		$allowed = array(
			'provider'                            => 32,
			'repository'                          => 201,
			'credential_id'                       => 64,
			'branch'                              => 191,
			'public_lookup_profile_id'            => 64,
			'provider_repository_identity_source' => 16,
		);
		$safe    = array();
		foreach ( $allowed as $key => $maximumBytes ) {
			if ( ! array_key_exists( $key, $repository ) ) {
				continue;
			}
			$value = $repository[ $key ];
			if ( ! is_string( $value )
				|| strlen( $value ) > $maximumBytes
				|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
				return null;
			}
			$safe[ $key ] = $value;
		}
		if ( ! isset( $safe['provider'], $safe['repository'] )
			|| 1 !== preg_match( '/\A[a-z][a-z0-9_-]{0,31}\z/D', $safe['provider'] )
			|| '' === $safe['repository']
			|| ( isset( $safe['credential_id'] )
				&& '' !== $safe['credential_id']
				&& 1 !== preg_match( '/\A[A-Za-z0-9_-]{3,64}\z/D', $safe['credential_id'] ) )
			|| ( isset( $safe['public_lookup_profile_id'] )
				&& '' !== $safe['public_lookup_profile_id']
				&& 1 !== preg_match( '/\A[A-Za-z0-9_-]{3,64}\z/D', $safe['public_lookup_profile_id'] ) )
			|| ( isset( $safe['provider_repository_identity_source'] )
				&& ! in_array( $safe['provider_repository_identity_source'], array( 'manual', 'picker' ), true ) ) ) {
			return null;
		}

		return $safe;
	}

	/** @param array<mixed> $data @return array<string, bool|int|string> */
	private function normalizeProspectiveData( array $data ): array {
		$allowed = array( 'release_id', 'tag', 'version', 'commit', 'details_url', 'package_root', 'main_file', 'fingerprint', 'identifier', 'channel' );
		$safe    = array();
		foreach ( $allowed as $key ) {
			$value = $data[ $key ] ?? null;
			if ( 'release_id' === $key && is_int( $value ) && $value > 0 ) {
				$safe[ $key ] = $value;
				continue;
			}
			if ( is_string( $value )
				&& strlen( $value ) <= $this->prospectiveValueLimit( $key )
				&& 1 !== preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
				$valid = match ( $key ) {
					'tag' => $this->validReleaseTag( $value ),
					'version' => $this->validVersion( $value ),
					'commit' => 1 === preg_match( '/\A[a-f0-9]{40}\z/D', $value ),
					'details_url' => $this->validDetailsUrl( $value ),
					'package_root' => $this->validPackageRoot( $value ),
					'main_file' => $this->validMainFile( $value ),
					'fingerprint' => $this->validFingerprint( $value ),
					'identifier' => $this->validIdentifier( $value ),
					'channel' => in_array( $value, array( 'stable', 'prerelease' ), true ),
					default => true,
				};
				if ( $valid ) {
					$safe[ $key ] = $value;
				}
			}
		}

		return $safe;
	}

	/** @param array<mixed> $data */
	private function validProspectiveResult( string $operation, string $code, bool $successful, array $data ): bool {
		$failureCodes = array(
			'forbidden',
			'unsupported_provider',
			'no_releases',
			'release_invalid',
			'unable_to_check',
			'install_failed',
			'package_already_exists',
			'management_state_uncertain',
			'installed_but_unmanaged',
			'installation_cleanup_failed',
			'invalid_request',
			'wordpress_refused',
			'wordpress_failed',
			'wordpress_restored',
			'wordpress_uncertain',
			'operation_mismatch',
		);
		if ( ! $successful ) {
			if ( ! in_array( $code, $failureCodes, true )
				|| ( isset( $data['identifier'] ) && ! $this->validIdentifier( $data['identifier'] ) ) ) {
				return false;
			}
			if ( 'installed_but_unmanaged' === $code ) {
				return isset( $data['identifier'], $data['version'] )
					&& $this->validIdentifier( $data['identifier'] )
					&& $this->validVersion( $data['version'] );
			}
			if ( 'management_state_uncertain' === $code ) {
				return isset( $data['identifier'] ) && $this->validIdentifier( $data['identifier'] );
			}

			return true;
		}
		if ( 'list_candidates' === $operation ) {
			return 'release_candidates_available' === $code
				&& isset( $data['candidates'] )
				&& is_array( $data['candidates'] )
				&& count( $data['candidates'] ) > 0
				&& count( $data['candidates'] ) <= 8;
		}
		if ( 'inspect' === $operation ) {
			return 'release_ready' === $code
				&& isset(
					$data['release_id'],
					$data['tag'],
					$data['version'],
					$data['commit'],
					$data['details_url'],
					$data['package_root'],
					$data['main_file'],
					$data['fingerprint']
				)
				&& is_int( $data['release_id'] )
				&& $this->validReleaseTag( $data['tag'] )
				&& $this->validVersion( $data['version'] )
				&& is_string( $data['commit'] )
				&& 1 === preg_match( '/\A[a-f0-9]{40}\z/D', $data['commit'] )
				&& $this->validDetailsUrl( $data['details_url'] )
				&& $this->validPackageRoot( $data['package_root'] )
				&& $this->validMainFile( $data['main_file'] )
				&& $this->validFingerprint( $data['fingerprint'] );
		}

		return 'install' === $operation
			&& 'installed' === $code
			&& isset( $data['identifier'], $data['version'] )
			&& $this->validIdentifier( $data['identifier'] )
			&& $this->validVersion( $data['version'] );
	}

	private function prospectiveValueLimit( string $key ): int {
		return match ( $key ) {
			'details_url' => 500,
			'identifier' => 255,
			'tag' => 100,
			'version' => 64,
			'commit' => 40,
			'fingerprint' => 67,
			'channel' => 10,
			default => 191,
		};
	}

	private function validReleaseTag( mixed $tag ): bool {
		return is_string( $tag ) && 1 === preg_match( '/\A[^\x00-\x1F\x7F]{1,100}\z/D', $tag );
	}

	private function validFingerprint( mixed $fingerprint ): bool {
		return is_string( $fingerprint ) && 1 === preg_match( '/\Av1:[a-f0-9]{64}\z/D', $fingerprint );
	}

	private function validVersion( mixed $version ): bool {
		return is_string( $version ) && 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,63}\z/D', $version );
	}

	private function validPackageRoot( mixed $packageRoot ): bool {
		return is_string( $packageRoot ) && 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,190}\z/D', $packageRoot );
	}

	private function validMainFile( mixed $mainFile ): bool {
		return is_string( $mainFile ) && 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,190}\z/D', $mainFile );
	}

	private function validIdentifier( mixed $identifier ): bool {
		return is_string( $identifier )
			&& '' !== $identifier
			&& strlen( $identifier ) <= 255
			&& 1 !== preg_match( '/[\x00-\x1F\x7F\\\\]/', $identifier )
			&& 1 !== preg_match( '#(?:\A|/)\.\.?(?:/|\z)#', $identifier );
	}

	private function validDetailsUrl( mixed $url ): bool {
		if ( ! is_string( $url )
			|| 1 === preg_match( '/[\x00-\x20\x7F]/', $url )
			|| false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}
		$parts = wp_parse_url( $url );

		return is_array( $parts )
			&& 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
			&& is_string( $parts['host'] ?? null )
			&& '' !== $parts['host']
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] )
			&& ! isset( $parts['port'] )
			&& ! isset( $parts['query'] )
			&& ! isset( $parts['fragment'] )
			&& is_string( $parts['path'] ?? null )
			&& str_starts_with( $parts['path'], '/' );
	}
}
