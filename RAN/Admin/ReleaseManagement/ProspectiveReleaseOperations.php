<?php

declare(strict_types=1);

namespace RAN\Admin\ReleaseManagement;

use RAN\AddOn\ReleaseTracking\ProspectiveReleaseFacade;
use Throwable;

/** @internal Owns untrusted prospective values, result projection and exact facade calls. */
final class ProspectiveReleaseOperations {
	/** @var \Closure(string, array<string, mixed>, string): \RAN\AddOn\ReleaseTracking\ProspectiveReleaseResult */
	private readonly \Closure $read_candidates;

	/** @param callable(string, array<string, mixed>, string): \RAN\AddOn\ReleaseTracking\ProspectiveReleaseResult $readCandidates */

	public function __construct( private readonly ProspectiveReleaseFacade $prospective, callable $read_candidates ) {

		$this->read_candidates = \Closure::fromCallable( $read_candidates );
	}


	public function nonce_action( string $operation, string $type ): string {
		return $this->prospective->nonce_action( $operation, $type );
	}

	/** @return list<string> */

	public function supported_provider_codes( string $type ): array {
		return $this->prospective->supported_provider_codes( $type );
	}

	/** @param array<string, mixed> $repository @return array{type:string,identifier:string,code:string,successful:bool,data:array<mixed>} */

	public function list_candidates( string $type, array $repository, string $channel ): array {
		$outcome    = static fn ( string $code, bool $successful, array $data = array() ): array => array(
			'type'       => in_array( $type, array( 'plugin', 'theme' ), true ) ? $type : 'plugin',
			'identifier' => '',
			'code'       => $code,
			'successful' => $successful,
			'data'       => $data,
		);
		$repository = $this->normalize_prospective_repository( $repository );
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) || null === $repository || ! in_array( $channel, array( 'stable', 'prerelease' ), true ) ) {
			return $outcome( 'invalid_request', false );
		}
		try {
			$result = ( $this->read_candidates )( $type, $repository, $channel );
		} catch ( Throwable ) {
			return $outcome( 'unable_to_check', false );
		}
		$data  = $this->normalize_candidate_list_data( $result->data() );
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

		array $untrusted_repository,

		string $release_id,
		string $tag,
		string $fingerprint,
		string $channel,
		string $nonce
	): array {
		$outcome = static fn ( string $code, bool $successful, array $data = array() ): array => array(
			'type'       => in_array( $type, array( 'plugin', 'theme' ), true ) ? $type : 'plugin',
			'identifier' => is_string( $data['identifier'] ?? null ) ? $data['identifier'] : '',
			'code'       => $code,
			'successful' => $successful,
			'data'       => $data,
		);

		if ( ! in_array( $operation, array( 'inspect', 'install' ), true ) ) {
			return $outcome( 'invalid_request', false );
		}


		$repository = $this->normalize_prospective_repository( $untrusted_repository );
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true )
			|| null === $repository
			|| ! in_array( $channel, array( 'stable', 'prerelease' ), true )

			|| ! $this->valid_release_id( $release_id )
			|| ! $this->valid_release_tag( $tag )
			|| ( 'install' === $operation && ! $this->valid_fingerprint( $fingerprint ) ) ) {
			return $outcome( 'invalid_request', false );
		}

		$result = match ( $operation ) {

			'inspect' => $this->prospective->inspect( $type, $repository, $release_id, $tag, $channel, $nonce ),

			'install' => $this->prospective->install( $type, $repository, $release_id, $tag, $fingerprint, $channel, $nonce ),
		};
		$code       = $result->code();
		$data       = $this->normalize_prospective_data( $result->data() );
		$successful = $result->successful();
		if ( ! $this->valid_prospective_result( $operation, $code, $successful, $data )
			|| ( isset( $data['channel'] ) && ! hash_equals( $channel, (string) $data['channel'] ) )
			|| ( 'inspect' === $operation
				&& $successful

				&& ( ( $data['release_id'] ?? null ) !== $release_id
					|| ! hash_equals( $tag, (string) ( $data['tag'] ?? '' ) ) ) ) ) {
			return $outcome( 'operation_failed', false );
		}

		return $outcome( $code, $successful, $data );
	}

	/**
	 * @param array<mixed> $data
	 * @return array{candidates?:list<array{release_id:string,tag:string,version:string,prerelease:bool,published_at:string,expected_asset_names:list<string>}>,channel?:string}
	 */
	private function normalize_candidate_list_data( array $data ): array {
		$raw_candidates = is_array( $data['candidates'] ?? null ) ? $data['candidates'] : null;
		if ( null === $raw_candidates || count( $raw_candidates ) > 8 ) {
			return array();
		}
		$candidates = array();
		foreach ( $raw_candidates as $raw_candidate ) {
			if ( ! is_array( $raw_candidate ) ) {
				return array();
			}
			$release_id = $raw_candidate['release_id'] ?? null;
			$tag        = $raw_candidate['tag'] ?? null;
			$version    = $raw_candidate['version'] ?? null;
			$preview    = $raw_candidate['prerelease'] ?? null;
			$published  = $raw_candidate['published_at'] ?? null;
			$assets     = $raw_candidate['expected_asset_names'] ?? null;
			if ( ! $this->valid_release_id( $release_id )
				|| ! $this->valid_release_tag( $tag )
				|| ! $this->valid_version( $version )
				|| ! is_bool( $preview )
				|| ! is_string( $published )
				|| strlen( $published ) > 40
				|| 1 !== preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:.+-]{5,30}Z?\z/D', $published )
				|| ! is_array( $assets )
				|| count( $assets ) > 8 ) {
				return array();
			}
			$asset_names = array();
			foreach ( $assets as $asset_name ) {
				if ( ! is_string( $asset_name )
					|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,190}\z/D', $asset_name ) ) {
					return array();
				}
				$asset_names[] = $asset_name;
			}
			$candidates[] = array(
				'release_id'           => $release_id,
				'tag'                  => $tag,
				'version'              => $version,
				'prerelease'           => $preview,
				'published_at'         => $published,
				'expected_asset_names' => $asset_names,
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
	private function normalize_prospective_repository( array $repository ): ?array {
		$allowed = array(
			'provider'                            => 32,
			'repository'                          => 201,
			'credential_id'                       => 64,
			'branch'                              => 191,
			'public_lookup_profile_id'            => 64,
			'provider_repository_identity_source' => 16,
		);
		$safe    = array();
		foreach ( $allowed as $key => $maximum_bytes ) {
			if ( ! array_key_exists( $key, $repository ) ) {
				continue;
			}
			$value = $repository[ $key ];
			if ( ! is_string( $value )
				|| strlen( $value ) > $maximum_bytes
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

	/** @param array<mixed> $data @return array<string, bool|string> */
	private function normalize_prospective_data( array $data ): array {
		$allowed = array( 'release_id', 'tag', 'version', 'commit', 'details_url', 'package_root', 'main_file', 'fingerprint', 'identifier', 'channel' );
		$safe    = array();
		foreach ( $allowed as $key ) {
			$value = $data[ $key ] ?? null;
			if ( is_string( $value )
				&& strlen( $value ) <= $this->prospective_value_limit( $key )
				&& 1 !== preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
				$valid = match ( $key ) {
					'release_id' => $this->valid_release_id( $value ),
					'tag' => $this->valid_release_tag( $value ),
					'version' => $this->valid_version( $value ),
					'commit' => 1 === preg_match( '/\A[a-f0-9]{40}\z/D', $value ),
					'details_url' => $this->valid_details_url( $value ),
					'package_root' => $this->valid_package_root( $value ),
					'main_file' => $this->valid_main_file( $value ),
					'fingerprint' => $this->valid_fingerprint( $value ),
					'identifier' => $this->valid_identifier( $value ),
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
	private function valid_prospective_result( string $operation, string $code, bool $successful, array $data ): bool {
		$failure_codes = array(
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
			if ( ! in_array( $code, $failure_codes, true )
				|| ( isset( $data['identifier'] ) && ! $this->valid_identifier( $data['identifier'] ) ) ) {
				return false;
			}
			if ( 'installed_but_unmanaged' === $code ) {
				return isset( $data['identifier'], $data['version'] )
					&& $this->valid_identifier( $data['identifier'] )
					&& $this->valid_version( $data['version'] );
			}
			if ( 'management_state_uncertain' === $code ) {
				return isset( $data['identifier'] ) && $this->valid_identifier( $data['identifier'] );
			}

			return true;
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
				&& $this->valid_release_id( $data['release_id'] )
				&& $this->valid_release_tag( $data['tag'] )
				&& $this->valid_version( $data['version'] )
				&& is_string( $data['commit'] )
				&& 1 === preg_match( '/\A[a-f0-9]{40}\z/D', $data['commit'] )
				&& $this->valid_details_url( $data['details_url'] )
				&& $this->valid_package_root( $data['package_root'] )
				&& $this->valid_main_file( $data['main_file'] )
				&& $this->valid_fingerprint( $data['fingerprint'] );
		}

		return 'install' === $operation
			&& 'installed' === $code
			&& isset( $data['identifier'], $data['version'] )
			&& $this->valid_identifier( $data['identifier'] )
			&& $this->valid_version( $data['version'] );
	}

	private function prospective_value_limit( string $key ): int {
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

	private function valid_release_tag( mixed $tag ): bool {
		return is_string( $tag ) && 1 === preg_match( '/\A[^\x00-\x1F\x7F]{1,100}\z/D', $tag );
	}

	private function valid_release_id( mixed $release_id ): bool {
		return is_string( $release_id )
			&& 1 === preg_match( '/\A[^\x00-\x1F\x7F]{1,191}\z/D', $release_id );
	}

	private function valid_fingerprint( mixed $fingerprint ): bool {
		return is_string( $fingerprint ) && 1 === preg_match( '/\Av2:[a-f0-9]{64}\z/D', $fingerprint );
	}

	private function valid_version( mixed $version ): bool {
		return is_string( $version ) && 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,63}\z/D', $version );
	}

	private function valid_package_root( mixed $package_root ): bool {
		return is_string( $package_root ) && 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,190}\z/D', $package_root );
	}

	private function valid_main_file( mixed $main_file ): bool {
		return is_string( $main_file ) && 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,190}\z/D', $main_file );
	}

	private function valid_identifier( mixed $identifier ): bool {
		return is_string( $identifier )
			&& '' !== $identifier
			&& strlen( $identifier ) <= 255
			&& 1 !== preg_match( '/[\x00-\x1F\x7F\\\\]/', $identifier )
			&& 1 !== preg_match( '#(?:\A|/)\.\.?(?:/|\z)#', $identifier );
	}

	private function valid_details_url( mixed $url ): bool {
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
