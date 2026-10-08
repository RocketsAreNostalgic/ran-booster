<?php

declare(strict_types=1);

namespace RAN\Admin\ReleaseManagement;

use RAN\AddOn\ReleaseTracking\ReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\RepositoryProvider\RepositoryReleaseCandidate;

/** @internal Projects only revision-bound managed release discovery and inspection. */
final class ManagedReleaseBrowserOperations {
	public function __construct( private readonly ManagedReleaseBrowser $browser, private readonly ReleaseTrackingFacade $releases ) {
	}

	/** @return array{code:string,successful:bool,data:array<mixed>} */

	public function list_candidates( string $type, string $identifier, int $revision, string $channel, string $nonce ): array {
		if ( ! $this->valid_request( $type, $identifier, $revision, $channel ) ) {
			return $this->outcome( 'invalid_request' );
		}
		$status = $this->releases->status( $type, $identifier );
		if ( $revision !== $status->source_revision() || 'release_asset' !== $status->source() ) {
			return $this->outcome( 'source_changed' );
		}
		$candidates = $this->browser->list_candidates( $type, $identifier, $revision, $channel, $nonce );
		if ( null === $candidates ) {
			return $this->outcome( 'unable_to_check' );
		}
		$status = $this->releases->status( $type, $identifier );
		if ( $revision !== $status->source_revision() || 'release_asset' !== $status->source() ) {
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
				)
			);
		}

		return $this->outcome(
			'release_candidates_available',
			true,
			array(
				'channel'           => $channel,
				'installed_version' => $status->installed_version(),
				'candidates'        => array_map( $this->candidate_projection( $status->installed_version() ), array_slice( $eligible_candidates, 0, 8 ) ),
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
		if ( null === $inspection || ! $inspection->ready()
			|| ! hash_equals( $tag, $inspection->release_tag() ) ) {
			return $this->outcome( 'unable_to_check' );
		}
		$status = $this->releases->status( $type, $identifier );
		if ( $revision !== $status->source_revision() || 'release_asset' !== $status->source() ) {
			return $this->outcome( 'source_changed' );
		}

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
					'available'  => $status->update_available(),
					'release_id' => $status->native_offer_release_id(),
					'version'    => $status->latest_version(),
				),
			)
		);
	}

	/** @return \Closure(RepositoryReleaseCandidate):array{release_id:string,tag:string,version:string,prerelease:bool,published_at:string,version_relationship:string} */
	private function candidate_projection( string $installed_version ): \Closure {
		return static fn ( RepositoryReleaseCandidate $candidate ): array => array(

			'release_id'           => $candidate->provider_release_id,
			'tag'                  => $candidate->tag,
			'version'              => $candidate->version,
			'prerelease'           => $candidate->prerelease,

			'published_at'         => $candidate->published_at,
			'version_relationship' => self::version_relationship( $candidate->version, $installed_version ),
		);
	}

	private static function version_relationship( string $version, string $installed_version ): string {
		return version_compare( $version, $installed_version ) > 0
			? 'newer'
			: ( version_compare( $version, $installed_version ) < 0 ? 'older' : 'same' );
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
	 * @return array{code:string,successful:bool,data:array<mixed>}
	 * @param array<string, mixed> $data
	 */
	private function outcome( string $code, bool $successful = false, array $data = array() ): array {
		return array(
			'code'       => $code,
			'successful' => $successful,
			'data'       => $data,
		);
	}
}
