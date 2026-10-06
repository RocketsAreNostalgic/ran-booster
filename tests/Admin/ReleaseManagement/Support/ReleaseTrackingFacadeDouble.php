<?php

declare(strict_types=1);

namespace RAN\Tests\Admin\ReleaseManagement\Support;

use RAN\AddOn\ReleaseTracking\ReleaseTrackingFacade;
use RAN\Admin\ReleaseManagement\ManagedReleaseBrowser;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingResult;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;
use RAN\RepositoryProvider\RepositoryReleaseCandidateList;
use RuntimeException;

final class ReleaseTrackingFacadeDouble implements ReleaseTrackingFacade, ManagedReleaseBrowser {
	public int $status_reads = 0;

	public int $status_list_reads = 0;

	public int $nonce_action_reads = 0;

	/** @var list<list<mixed>> */
	public array $calls = array();

	/** @var list<string> */
	public array $last_identifiers = array();

	public bool $throw_on_status = false;

	public bool $throw_on_nonce = false;

	public ?RepositoryReleaseCandidateList $candidate_list = null;

	public ?ReleaseTrackingPreflight $candidate_inspection = null;

	/** @var null|callable():void */
	public $after_candidate_list = null;

	/** @var null|callable():void */
	public $after_candidate_inspection = null;

	/** @param array<string,ReleaseTrackingStatus> $releaseStatuses */
	public function __construct(
		private ReleaseTrackingStatus $release_status,
		private array $release_statuses = array()
	) {
	}

	public function status( string $type, string $identifier ): ReleaseTrackingStatus {
		++$this->status_reads;
		if ( $this->throw_on_status ) {
			throw new RuntimeException( 'status-failure' );
		}

		return $this->release_statuses[ $type . '|' . $identifier ] ?? $this->release_status;
	}

	public function statuses( string $type, array $identifiers ): array {
		unset( $type );
		++$this->status_list_reads;
		$this->last_identifiers = $identifiers;
		if ( $this->throw_on_status ) {
			throw new RuntimeException( 'status-list-failure' );
		}

		return array( $this->release_status->identifier() => $this->release_status );
	}

	public function nonce_action(
		string $operation,
		string $type,
		string $identifier,
		int $source_revision,
		string $channel = ''
	): string {
		++$this->nonce_action_reads;
		if ( $this->throw_on_nonce ) {
			throw new RuntimeException( 'nonce-failure' );
		}

		return 'release-tracking-' . $operation . '-' . $type . '-' . $identifier . '-' . $source_revision
			. ( '' === $channel ? '' : '-' . $channel );
	}

	public function preflight( string $type, string $identifier, int $expected_source_revision, string $channel, string $nonce ): ?ReleaseTrackingPreflight {
		$this->calls[] = array( 'preflight', $type, $identifier, $expected_source_revision, $channel, $nonce );

		return $this->release_status->preflight();
	}

	public function assessment_preflight( string $type, string $identifier, int $expected_source_revision, string $channel, string $nonce ): ?ReleaseTrackingPreflight {
		$this->calls[] = array( 'assessment_preflight', $type, $identifier, $expected_source_revision, $channel, $nonce );

		return $this->release_status->preflight();
	}

	public function list_candidates( string $type, string $identifier, int $expected_source_revision, string $channel, string $nonce ): ?RepositoryReleaseCandidateList {
		$this->calls[] = array( 'list_candidates', $type, $identifier, $expected_source_revision, $channel, $nonce );
		if ( is_callable( $this->after_candidate_list ) ) {
			( $this->after_candidate_list )();
		}

		return $this->candidate_list;
	}

	public function set_status( ReleaseTrackingStatus $status ): void {
		$this->release_status = $status;
	}

	public function inspect_candidate( string $type, string $identifier, int $expected_source_revision, string $release_id, string $tag, string $channel, string $nonce ): ?ReleaseTrackingPreflight {
		$this->calls[] = array( 'inspect_candidate', $type, $identifier, $expected_source_revision, $release_id, $tag, $channel, $nonce );
		if ( is_callable( $this->after_candidate_inspection ) ) {
			( $this->after_candidate_inspection )();
		}

		return $this->candidate_inspection;
	}

	public function enable( string $type, string $identifier, int $expected_source_revision, string $channel, string $nonce ): ReleaseTrackingResult {
		$this->calls[] = array( 'enable', $type, $identifier, $expected_source_revision, $channel, $nonce );

		return ReleaseTrackingResult::succeeded( 'release_enabled', 'Release tracking enabled.' );
	}

	public function change_channel( string $type, string $identifier, int $expected_source_revision, string $channel, string $nonce ): ReleaseTrackingResult {
		$this->calls[] = array( 'change_channel', $type, $identifier, $expected_source_revision, $channel, $nonce );

		return ReleaseTrackingResult::succeeded( 'release_channel_changed', 'Release track changed.' );
	}

	public function refresh( string $type, string $identifier, int $expected_source_revision, string $nonce ): ReleaseTrackingResult {
		$this->calls[] = array( 'refresh', $type, $identifier, $expected_source_revision, $nonce );

		return ReleaseTrackingResult::succeeded( 'release_refreshed', 'Release status refreshed.' );
	}

	public function return_to_branch( string $type, string $identifier, int $expected_source_revision, string $nonce ): ReleaseTrackingResult {
		$this->calls[] = array( 'return_to_branch', $type, $identifier, $expected_source_revision, $nonce );

		return ReleaseTrackingResult::succeeded( 'branch_restored', 'Branch management restored.' );
	}
}
