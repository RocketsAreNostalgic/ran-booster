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
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function listCandidates( string $type, string $identifier, int $revision, string $channel, string $nonce ): array {
		if ( ! $this->valid_request( $type, $identifier, $revision, $channel ) ) {
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
		$eligible_candidates = $candidates->candidates;
		if ( array() === $eligible_candidates ) {
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
				'candidates'        => array_map( $this->candidate_projection( $status->installedVersion() ), array_slice( $eligible_candidates, 0, 8 ) ),
			)
		);
	}

	/** @return array{code:string,successful:bool,data:array<mixed>} */
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
	public function inspect( string $type, string $identifier, int $revision, string $releaseId, string $tag, string $channel, string $nonce ): array {
		if ( ! $this->valid_request( $type, $identifier, $revision, $channel )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			|| ! $this->valid_opaque( $releaseId, 191 )
			|| ! $this->valid_opaque( $tag, 100 ) ) {
			return $this->outcome( 'invalid_request' );
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
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
				'version_relationship' => self::version_relationship( $inspection->latestVersion(), $status->installedVersion() ),
				'native_offer'         => array(
					'available'  => $status->updateAvailable(),
					'release_id' => $status->nativeOfferReleaseId(),
					'version'    => $status->latestVersion(),
				),
			)
		);
	}

	/** @return \Closure(RepositoryReleaseCandidate):array{release_id:string,tag:string,version:string,prerelease:bool,published_at:string,version_relationship:string} */
	private function candidate_projection( string $installed_version ): \Closure {
		return static fn ( RepositoryReleaseCandidate $candidate ): array => array(
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the external DTO or promoted constructor property contract.
			'release_id'           => $candidate->providerReleaseId,
			'tag'                  => $candidate->tag,
			'version'              => $candidate->version,
			'prerelease'           => $candidate->prerelease,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the external DTO or promoted constructor property contract.
			'published_at'         => $candidate->publishedAt,
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

	/** @return array{code:string,successful:bool,data:array<mixed>} */
	private function outcome( string $code, bool $successful = false, array $data = array() ): array {
		return array(
			'code'       => $code,
			'successful' => $successful,
			'data'       => $data,
		);
	}
}
