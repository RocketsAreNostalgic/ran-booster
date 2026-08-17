<?php

declare(strict_types=1);

namespace Tests\Admin\ReleaseManagement\Support;

use RAN\AddOn\ReleaseTracking\ReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingResult;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;
use RuntimeException;

final class ReleaseTrackingFacadeDouble implements ReleaseTrackingFacade {
	public int $statusReads = 0;

	public int $statusListReads = 0;

	public int $nonceActionReads = 0;

	/** @var list<list<mixed>> */
	public array $calls = array();

	/** @var list<string> */
	public array $lastIdentifiers = array();

	public bool $throwOnStatus = false;

	public bool $throwOnNonce = false;

	public function __construct( private ReleaseTrackingStatus $releaseStatus ) {
	}

	public function status( string $type, string $identifier ): ReleaseTrackingStatus {
		unset( $type, $identifier );
		++$this->statusReads;
		if ( $this->throwOnStatus ) {
			throw new RuntimeException( 'status-failure' );
		}

		return $this->releaseStatus;
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
