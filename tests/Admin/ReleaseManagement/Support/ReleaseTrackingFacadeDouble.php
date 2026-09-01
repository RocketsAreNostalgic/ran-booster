<?php

declare(strict_types=1);

namespace Tests\Admin\ReleaseManagement\Support;

use RAN\AddOn\ReleaseTracking\ReleaseTrackingFacade;
use RAN\Admin\ReleaseManagement\ManagedReleaseBrowser;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingResult;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;
use RAN\RepositoryProvider\RepositoryReleaseCandidateList;
use RuntimeException;

final class ReleaseTrackingFacadeDouble implements ReleaseTrackingFacade, ManagedReleaseBrowser {
	public int $statusReads = 0;

	public int $statusListReads = 0;

	public int $nonceActionReads = 0;

	/** @var list<list<mixed>> */
	public array $calls = array();

	/** @var list<string> */
	public array $lastIdentifiers = array();

	public bool $throwOnStatus = false;

	public bool $throwOnNonce = false;

	public ?RepositoryReleaseCandidateList $candidateList = null;

	public ?ReleaseTrackingPreflight $candidateInspection = null;

	/** @var null|callable():void */
	public $afterCandidateList = null;

	/** @var null|callable():void */
	public $afterCandidateInspection = null;

	/** @param array<string,ReleaseTrackingStatus> $releaseStatuses */
	public function __construct(
		private ReleaseTrackingStatus $releaseStatus,
		private array $releaseStatuses = array()
	) {
	}

	public function status( string $type, string $identifier ): ReleaseTrackingStatus {
		++$this->statusReads;
		if ( $this->throwOnStatus ) {
			throw new RuntimeException( 'status-failure' );
		}

		return $this->releaseStatuses[ $type . '|' . $identifier ] ?? $this->releaseStatus;
	}

	public function statuses( string $type, array $identifiers ): array {
		unset( $type );
		++$this->statusListReads;
		$this->lastIdentifiers = $identifiers;
		if ( $this->throwOnStatus ) {
			throw new RuntimeException( 'status-list-failure' );
		}

		return array( $this->releaseStatus->identifier() => $this->releaseStatus );
	}

	public function nonceAction(
		string $operation,
		string $type,
		string $identifier,
		int $sourceRevision,
		string $channel = ''
	): string {
		++$this->nonceActionReads;
		if ( $this->throwOnNonce ) {
			throw new RuntimeException( 'nonce-failure' );
		}

		return 'release-tracking-' . $operation . '-' . $type . '-' . $identifier . '-' . $sourceRevision
			. ( '' === $channel ? '' : '-' . $channel );
	}

	public function preflight( string $type, string $identifier, int $expectedSourceRevision, string $channel, string $nonce ): ?ReleaseTrackingPreflight {
		$this->calls[] = array( 'preflight', $type, $identifier, $expectedSourceRevision, $channel, $nonce );

		return $this->releaseStatus->preflight();
	}

	public function assessmentPreflight( string $type, string $identifier, int $expectedSourceRevision, string $channel, string $nonce ): ?ReleaseTrackingPreflight {
		$this->calls[] = array( 'assessment_preflight', $type, $identifier, $expectedSourceRevision, $channel, $nonce );

		return $this->releaseStatus->preflight();
	}

	public function listCandidates( string $type, string $identifier, int $expectedSourceRevision, string $channel, string $nonce ): ?RepositoryReleaseCandidateList {
		$this->calls[] = array( 'list_candidates', $type, $identifier, $expectedSourceRevision, $channel, $nonce );
		if ( is_callable( $this->afterCandidateList ) ) {
			( $this->afterCandidateList )();
		}

		return $this->candidateList;
	}

	public function setStatus( ReleaseTrackingStatus $status ): void {
		$this->releaseStatus = $status;
	}

	public function inspectCandidate( string $type, string $identifier, int $expectedSourceRevision, string $releaseId, string $tag, string $channel, string $nonce ): ?ReleaseTrackingPreflight {
		$this->calls[] = array( 'inspect_candidate', $type, $identifier, $expectedSourceRevision, $releaseId, $tag, $channel, $nonce );
		if ( is_callable( $this->afterCandidateInspection ) ) {
			( $this->afterCandidateInspection )();
		}

		return $this->candidateInspection;
	}

	public function enable( string $type, string $identifier, int $expectedSourceRevision, string $channel, string $nonce ): ReleaseTrackingResult {
		$this->calls[] = array( 'enable', $type, $identifier, $expectedSourceRevision, $channel, $nonce );

		return ReleaseTrackingResult::succeeded( 'release_enabled', 'Release tracking enabled.' );
	}

	public function changeChannel( string $type, string $identifier, int $expectedSourceRevision, string $channel, string $nonce ): ReleaseTrackingResult {
		$this->calls[] = array( 'change_channel', $type, $identifier, $expectedSourceRevision, $channel, $nonce );

		return ReleaseTrackingResult::succeeded( 'release_channel_changed', 'Release track changed.' );
	}

	public function refresh( string $type, string $identifier, int $expectedSourceRevision, string $nonce ): ReleaseTrackingResult {
		$this->calls[] = array( 'refresh', $type, $identifier, $expectedSourceRevision, $nonce );

		return ReleaseTrackingResult::succeeded( 'release_refreshed', 'Release status refreshed.' );
	}

	public function returnToBranch( string $type, string $identifier, int $expectedSourceRevision, string $nonce ): ReleaseTrackingResult {
		$this->calls[] = array( 'return_to_branch', $type, $identifier, $expectedSourceRevision, $nonce );

		return ReleaseTrackingResult::succeeded( 'branch_restored', 'Branch management restored.' );
	}
}
