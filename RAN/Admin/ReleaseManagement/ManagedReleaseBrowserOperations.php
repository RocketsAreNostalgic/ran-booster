<?php

declare(strict_types=1);

namespace RAN\Admin\ReleaseManagement;

use RAN\AddOn\ReleaseTracking\ReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;
use RAN\RepositoryProvider\RepositoryReleaseCandidate;
use RAN\WordPress\ManagedReleaseTargetRegistrar;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseVersion;

/**
 * @internal Projects only revision-bound managed release discovery and inspection.
 * @phpstan-type Candidate array{release_id:string,tag:string,version:string,prerelease:bool,published_at:string,version_relationship:string,zip_count?:int,latest_eligible?:bool}
 */
final class ManagedReleaseBrowserOperations {
	public function __construct( private readonly ManagedReleaseBrowser $browser, private readonly ReleaseTrackingFacade $releases ) {
	}

	/** @return array{code:string,successful:bool,data:array{candidates?:list<Candidate>,installed_version?:string,channel?:string,search_exhausted?:bool|null,diagnostics?:array<mixed>|null,rate_limited_provider?:string}} */

	public function list_candidates( string $type, string $identifier, int $revision, string $channel, string $nonce, bool $on_branch = false ): array {
		if ( ! $this->valid_request( $type, $identifier, $revision, $channel ) ) {
			return $this->outcome( 'invalid_request' );
		}
		$source = $on_branch ? 'branch' : 'release_asset';
		$status = $this->releases->status( $type, $identifier );
		if ( $revision !== $status->source_revision() || $source !== $status->source() ) {
			return $this->outcome( 'source_changed' );
		}
		$candidates = $this->browser->list_candidates( $type, $identifier, $revision, $channel, $nonce );
		if ( null === $candidates ) {
			$provider = $this->browser->anonymous_rate_limit_provider();

			return $this->outcome( 'unable_to_check', false, null === $provider ? array() : array( 'rate_limited_provider' => $provider ) );
		}
		$status = $this->releases->status( $type, $identifier );
		if ( $revision !== $status->source_revision() || $source !== $status->source() ) {
			return $this->outcome( 'source_changed' );
		}
		$eligible_candidates = $candidates->candidates;
		if ( array() === $eligible_candidates ) {
			return $this->outcome(
				'no_releases',
				true,
				array(
					'channel'           => $channel,
					'installed_version' => $status->installed_version(),
					'candidates'        => array(),
					'search_exhausted'  => $candidates->search_exhausted,
					'diagnostics'       => $candidates->diagnostics,
				)
			);
		}

		$projected = array_map( $this->candidate_projection( $status->installed_version(), $on_branch ), array_slice( $eligible_candidates, 0, 8 ) );
		if ( $on_branch ) {
			$latest = $status->eligible() && '' === ReleaseTrackingOperations::repository_blocker( $status )
				? self::latest_eligible_index( $projected, $channel, $status->installed_version() ) : null;
			foreach ( $projected as $index => $candidate ) {
				$projected[ $index ]['latest_eligible'] = $index === $latest;
			}
		}

		return $this->outcome(
			'release_candidates_available',
			true,
			array(
				'channel'           => $channel,
				'installed_version' => $status->installed_version(),
				'candidates'        => $projected,
				'search_exhausted'  => $candidates->search_exhausted,
				'diagnostics'       => $candidates->diagnostics,
			)
		);
	}

	/** @return array{code:string,successful:bool,data:array<mixed>} */

	public function inspect( string $type, string $identifier, int $revision, string $release_id, string $tag, string $channel, string $nonce ): array {
		if ( ! $this->valid_request( $type, $identifier, $revision, $channel )

			|| ! $this->valid_opaque( $release_id, 191 )
			|| ! $this->valid_opaque( $tag, 100 ) ) {
			return $this->outcome( 'invalid_request' );
		}

		$inspection = $this->browser->inspect_candidate( $type, $identifier, $revision, $release_id, $tag, $channel, $nonce );
		if ( null !== $inspection && ! $inspection->ready() && 'digest_missing' === $inspection->reason_code() ) {
			$status = $this->releases->status( $type, $identifier );
			if ( $revision !== $status->source_revision() || 'release_asset' !== $status->source() ) {
				return $this->outcome( 'source_changed' );
			}
			return $this->outcome( 'unable_to_check', false, array( 'rejection_reason' => 'digest_missing' ) );
		}
		if ( null === $inspection || ! $inspection->ready()
			|| ! hash_equals( $tag, $inspection->release_tag() ) ) {
			return $this->outcome( 'unable_to_check' );
		}
		$status = $this->releases->status( $type, $identifier );
		if ( $revision !== $status->source_revision() || 'release_asset' !== $status->source() ) {
			return $this->outcome( 'source_changed' );
		}

		$offered = self::persisted_offer_version( $status );

		return $this->outcome(
			'release_ready',
			true,
			array(
				'tag'                  => $inspection->release_tag(),
				'version'              => $inspection->latest_version(),
				'details_url'          => $inspection->release_url(),
				'installed_version'    => $status->installed_version(),
				'version_relationship' => self::version_relationship( $inspection->latest_version(), $status->installed_version() ),
				'native_offer'         => array(
					'available'  => '' !== $offered,
					// The filtered WordPress offer supplies a version without an independently proven release identity.
					'release_id' => '',
					'version'    => $offered,
				),
			)
		);
	}

	/** The current native action uses WordPress's filtered offer. */
	public static function persisted_offer_version( ReleaseTrackingStatus $status ): string {
		return 'release_asset' === $status->source()
			&& $status->eligible()
			&& 'disabled' !== $status->deployment_policy()
			&& '' === ReleaseTrackingOperations::repository_blocker( $status )
			? ManagedReleaseTargetRegistrar::cached_offer_version( $status->type(), $status->identifier(), $status->installed_version() )
			: '';
	}

	/** @return \Closure(RepositoryReleaseCandidate):Candidate */
	private function candidate_projection( string $installed_version, bool $on_branch ): \Closure {
		return static fn ( RepositoryReleaseCandidate $candidate ): array => array(

			'release_id'           => $candidate->provider_release_id,
			'tag'                  => $candidate->tag,
			'version'              => $candidate->version,
			'prerelease'           => $candidate->prerelease,

			'published_at'         => $candidate->published_at,
			'version_relationship' => self::version_relationship( $candidate->version, $installed_version ),
		) + ( $on_branch ? array( 'zip_count' => count( $candidate->expected_asset_names ) ) : array() );
	}

	/**
	 * Select the same metadata candidate for both the chooser and branch list.
	 *
	 * @param list<Candidate> $candidates Projected listing.
	 */
	private static function latest_eligible_index( array $candidates, string $channel, string $installed ): ?int {
		if ( ! class_exists( ReleaseVersion::class ) || '' === $installed || strlen( $installed ) > 64 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $installed ) || sanitize_text_field( $installed ) !== $installed ) {
			return null;
		}
		$latest = null;
		foreach ( $candidates as $index => $candidate ) {
			if ( 'stable' === $channel && $candidate['prerelease'] ) {
				continue;
			}
			foreach ( array(
				'release_id' => 191,
				'tag'        => 128,
				'version'    => 64,
			) as $key => $limit ) {
				$value = $candidate[ $key ];
				if ( '' === $value || strlen( $value ) > $limit || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) || sanitize_text_field( $value ) !== $value ) {
					return null;
				}
			}
			if ( ! in_array( ReleaseVersion::relationship( $candidate['version'], $installed ), array( 'same', 'newer', 'older' ), true ) ) {
				return null;
			}
			if ( 1 === ( $candidate['zip_count'] ?? null ) && ( null === $latest || 1 === ReleaseVersion::compare( $candidate['version'], $candidates[ $latest ]['version'] ) ) ) {
				$latest = $index;
			}
		}
		return $latest;
	}

	private static function version_relationship( string $version, string $installed_version ): string {
		// The elected runtime supplies the comparator without loading a second copy here.
		return class_exists( ReleaseVersion::class, false ) ? ReleaseVersion::relationship( $version, $installed_version ) : 'invalid';
	}

	private function valid_request( string $type, string $identifier, int $revision, string $channel ): bool {
		return in_array( $type, array( 'plugin', 'theme' ), true )
			&& $this->valid_opaque( $identifier, 255 )
			&& $revision > 0
			&& in_array( $channel, array( 'stable', 'prerelease' ), true );
	}

	private function valid_opaque( string $value, int $maximum ): bool {
		return '' !== $value && strlen( $value ) <= $maximum && 1 !== preg_match( '/[\x00-\x1F\x7F]/', $value );
	}

	/**
	 * @template TData of array<string,mixed>
	 * @param TData $data
	 * @return array{code:string,successful:bool,data:TData}
	 */
	private function outcome( string $code, bool $successful = false, array $data = array() ): array {
		return array(
			'code'       => $code,
			'successful' => $successful,
			'data'       => $data,
		);
	}
}
