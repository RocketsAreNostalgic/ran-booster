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
	public function nonce_action(
		string $operation,
		string $type,
		string $identifier,
		int $source_revision,
		string $channel = ''
	): string;

	/** Run the exact release verifier without changing package or updater state. */
	public function preflight(
		string $type,
		string $identifier,
		int $expected_source_revision,
		string $channel,
		string $nonce
	): ?ReleaseTrackingPreflight;

	/** Assess an eligible Branch or release-asset package without changing package or updater state. */
	public function assessment_preflight(
		string $type,
		string $identifier,
		int $expected_source_revision,
		string $channel,
		string $nonce
	): ?ReleaseTrackingPreflight;

	public function enable(
		string $type,
		string $identifier,
		int $expected_source_revision,
		string $channel,
		string $nonce
	): ReleaseTrackingResult;

	public function change_channel(
		string $type,
		string $identifier,
		int $expected_source_revision,
		string $channel,
		string $nonce
	): ReleaseTrackingResult;

	public function refresh(
		string $type,
		string $identifier,
		int $expected_source_revision,
		string $nonce
	): ReleaseTrackingResult;

	public function return_to_branch(
		string $type,
		string $identifier,
		int $expected_source_revision,
		string $nonce
	): ReleaseTrackingResult;
}
