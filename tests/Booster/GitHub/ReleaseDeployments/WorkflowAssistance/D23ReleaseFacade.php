<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use RAN\AddOn\ReleaseTracking\ReleaseTrackingEligibility;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingResult;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;

final class D23ReleaseFacade implements ReleaseTrackingFacade {
	public string $preflightCode                        = ReleaseTrackingPreflight::RELEASE_UNAVAILABLE;
	public ?ReleaseTrackingPreflight $preflightResponse = null;
	public bool $preflightContractUnavailable           = false;
	/** @var list<list<mixed>> */
	public array $calls = array();
	public function __construct( private readonly string $source = 'branch' ) {
	}
	public function status( string $type, string $identifier ): ReleaseTrackingStatus {
		$root = 'theme' === $type ? 'example-theme' : 'example-plugin';
		return new ReleaseTrackingStatus( $type, $identifier, $this->source, 3, '101', 'manual', new ReleaseTrackingEligibility( ReleaseTrackingEligibility::ELIGIBLE, 'https://github.com/owner/example-plugin', $root ), null, $root, '1.2.3' );
	}
	public function statuses( string $type, array $identifiers ): array {
		return array( $identifiers[0] => $this->status( $type, $identifiers[0] ) ); }
	public function nonceAction( string $operation, string $type, string $identifier, int $sourceRevision, string $channel = '' ): string {
		return 'nonce'; }
	public function preflight( string $type, string $identifier, int $expectedSourceRevision, string $channel, string $nonce ): ?ReleaseTrackingPreflight {
		$this->calls[] = array( 'preflight', $type, $identifier, $expectedSourceRevision, $channel, $nonce );
		return $this->preflightContractUnavailable ? null : ( $this->preflightResponse ?? new ReleaseTrackingPreflight( $this->preflightCode, 'example-plugin' ) ); }
	public function assessmentPreflight( string $type, string $identifier, int $expectedSourceRevision, string $channel, string $nonce ): ?ReleaseTrackingPreflight {
		$this->calls[] = array( 'assessment_preflight', $type, $identifier, $expectedSourceRevision, $channel, $nonce );
		return $this->preflightContractUnavailable ? null : ( $this->preflightResponse ?? new ReleaseTrackingPreflight( $this->preflightCode, 'example-plugin' ) ); }
	public function enable( string $type, string $identifier, int $expectedSourceRevision, string $channel, string $nonce ): ReleaseTrackingResult {
		return ReleaseTrackingResult::failed( 'unused', 'unused' ); }
	public function changeChannel( string $type, string $identifier, int $expectedSourceRevision, string $channel, string $nonce ): ReleaseTrackingResult {
		return ReleaseTrackingResult::failed( 'unused', 'unused' ); }
	public function refresh( string $type, string $identifier, int $expectedSourceRevision, string $nonce ): ReleaseTrackingResult {
		return ReleaseTrackingResult::failed( 'unused', 'unused' ); }
	public function returnToBranch( string $type, string $identifier, int $expectedSourceRevision, string $nonce ): ReleaseTrackingResult {
		return ReleaseTrackingResult::failed( 'unused', 'unused' ); }
}
