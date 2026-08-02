<?php

declare(strict_types=1);

namespace RAN\AddOn\ReleaseTracking;

/**
 * Narrow release-source boundary published to trusted release-management add-ons.
 */
interface ReleaseTrackingFacade {

	public function status( string $type, string $identifier ): ReleaseTrackingStatus;

	/**
	 * @param list<string> $identifiers
	 * @return array<string, ReleaseTrackingStatus>
	 */
	public function statuses( string $type, array $identifiers ): array;

	/**
	 * Return Core's purpose-specific, source-revision-bound operation nonce scope.
	 *
	 * This derives an action string only. It neither creates nor authorizes a
	 * WordPress nonce.
	 */
	public function nonceAction(
		string $operation,
		string $type,
		string $identifier,
		int $sourceRevision,
		string $channel = ''
	): string;

	/** Run the exact release verifier without changing package or updater state. */
	public function preflight(
		string $type,
		string $identifier,
		int $expectedSourceRevision,
		string $channel,
		string $nonce
	): ?ReleaseTrackingPreflight;

	public function enable(
		string $type,
		string $identifier,
		int $expectedSourceRevision,
		string $channel,
		string $nonce
	): ReleaseTrackingResult;

	public function changeChannel(
		string $type,
		string $identifier,
		int $expectedSourceRevision,
		string $channel,
		string $nonce
	): ReleaseTrackingResult;

	public function refresh(
		string $type,
		string $identifier,
		int $expectedSourceRevision,
		string $nonce
	): ReleaseTrackingResult;

	public function returnToBranch(
		string $type,
		string $identifier,
		int $expectedSourceRevision,
		string $nonce
	): ReleaseTrackingResult;
}
