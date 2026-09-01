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
	public function listCandidates( string $type, string $identifier, int $revision, string $channel, string $nonce ): array {
		if ( ! $this->validRequest( $type, $identifier, $revision, $channel ) ) {
			return $this->outcome( 'invalid_request' );
		}
		$status = $this->releases->status( $type, $identifier );
		if ( $revision !== $status->sourceRevision() || 'release_asset' !== $status->source() ) {
			return $this->outcome( 'source_changed' );
		}
		$candidates = $this->browser->listCandidates( $type, $identifier, $revision, $channel, $nonce );
		if ( null === $candidates ) {
			return $this->outcome( 'unable_to_check' );
		}
		$status = $this->releases->status( $type, $identifier );
		if ( $revision !== $status->sourceRevision() || 'release_asset' !== $status->source() ) {
			return $this->outcome( 'source_changed' );
		}
		$eligibleCandidates = $candidates->candidates;
		if ( array() === $eligibleCandidates ) {
			return $this->outcome(
				'no_releases',
				true,
				array(
					'channel'           => $channel,
					'installed_version' => $status->installedVersion(),
					'candidates'        => array(),
				)
			);
		}

		return $this->outcome(
			'release_candidates_available',
			true,
			array(
				'channel'           => $channel,
				'installed_version' => $status->installedVersion(),
				'candidates'        => array_map( $this->candidateProjection( $status->installedVersion() ), array_slice( $eligibleCandidates, 0, 8 ) ),
			)
		);
	}

	/** @return array{code:string,successful:bool,data:array<mixed>} */
	public function inspect( string $type, string $identifier, int $revision, string $releaseId, string $tag, string $channel, string $nonce ): array {
		if ( ! $this->validRequest( $type, $identifier, $revision, $channel )
			|| ! $this->validOpaque( $releaseId, 191 )
			|| ! $this->validOpaque( $tag, 100 ) ) {
			return $this->outcome( 'invalid_request' );
		}
		$inspection = $this->browser->inspectCandidate( $type, $identifier, $revision, $releaseId, $tag, $channel, $nonce );
		if ( null === $inspection || ! $inspection->ready()
			|| ! hash_equals( $tag, $inspection->releaseTag() ) ) {
			return $this->outcome( 'unable_to_check' );
		}
		$status = $this->releases->status( $type, $identifier );
		if ( $revision !== $status->sourceRevision() || 'release_asset' !== $status->source() ) {
			return $this->outcome( 'source_changed' );
		}

		return $this->outcome(
			'release_ready',
			true,
			array(
				'tag'                  => $inspection->releaseTag(),
				'version'              => $inspection->latestVersion(),
				'details_url'          => $inspection->releaseUrl(),
				'installed_version'    => $status->installedVersion(),
				'version_relationship' => self::versionRelationship( $inspection->latestVersion(), $status->installedVersion() ),
				'native_offer'         => array(
					'available'  => $status->updateAvailable(),
					'release_id' => $status->nativeOfferReleaseId(),
					'version'    => $status->latestVersion(),
				),
			)
		);
	}

	/** @return \Closure(RepositoryReleaseCandidate):array{release_id:string,tag:string,version:string,prerelease:bool,published_at:string,version_relationship:string} */
	private function candidateProjection( string $installedVersion ): \Closure {
		return static fn ( RepositoryReleaseCandidate $candidate ): array => array(
			'release_id'           => $candidate->providerReleaseId,
			'tag'                  => $candidate->tag,
			'version'              => $candidate->version,
			'prerelease'           => $candidate->prerelease,
			'published_at'         => $candidate->publishedAt,
			'version_relationship' => self::versionRelationship( $candidate->version, $installedVersion ),
		);
	}

	private static function versionRelationship( string $version, string $installedVersion ): string {
		return version_compare( $version, $installedVersion ) > 0
			? 'newer'
			: ( version_compare( $version, $installedVersion ) < 0 ? 'older' : 'same' );
	}

	private function validRequest( string $type, string $identifier, int $revision, string $channel ): bool {
		return in_array( $type, array( 'plugin', 'theme' ), true )
			&& $this->validOpaque( $identifier, 255 )
			&& $revision > 0
			&& in_array( $channel, array( 'stable', 'prerelease' ), true );
	}

	private function validOpaque( string $value, int $maximum ): bool {
		return '' !== $value && strlen( $value ) <= $maximum && 1 !== preg_match( '/[\x00-\x1F\x7F]/', $value );
	}

	/** @return array{code:string,successful:bool,data:array<mixed>} */
	private function outcome( string $code, bool $successful = false, array $data = array() ): array {
		return array(
			'code'       => $code,
			'successful' => $successful,
			'data'       => $data,
		);
	}
}
