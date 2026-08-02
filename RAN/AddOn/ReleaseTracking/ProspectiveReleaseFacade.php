<?php

declare(strict_types=1);

namespace RAN\AddOn\ReleaseTracking;

/**
 * Versioned Core boundary for installing a not-yet-managed published release.
 */
interface ProspectiveReleaseFacade {

	public const API_VERSION = 4;

	public function nonceAction( string $operation, string $type ): string;

	/**
	 * Return the bounded provider codes supported for one prospective package type.
	 *
	 * This is a request-local capability projection. It performs no repository
	 * resolution, credential access, remote request, discovery or mutation.
	 *
	 * @return list<string>
	 */
	public function supportedProviderCodes( string $type ): array;

	/**
	 * @param array<string, mixed>  $repositoryRequest
	 * @param 'stable'|'prerelease' $channel
	 */
	public function listCandidates(
		string $type,
		array $repositoryRequest,
		string $channel,
		string $nonce
	): ProspectiveReleaseResult;

	/**
	 * @param array<string, mixed>  $repositoryRequest
	 * @param 'stable'|'prerelease' $channel
	 */
	public function discover(
		string $type,
		array $repositoryRequest,
		string $channel,
		string $nonce
	): ProspectiveReleaseResult;

	/**
	 * @param array<string, mixed>  $repositoryRequest
	 * @param 'stable'|'prerelease' $channel
	 */
	public function inspect(
		string $type,
		array $repositoryRequest,
		int $releaseId,
		string $tag,
		string $channel,
		string $nonce
	): ProspectiveReleaseResult;

	/**
	 * @param array<string, mixed>  $repositoryRequest
	 * @param 'stable'|'prerelease' $channel
	 */
	public function install(
		string $type,
		array $repositoryRequest,
		int $releaseId,
		string $tag,
		string $expectedFingerprint,
		string $channel,
		string $nonce
	): ProspectiveReleaseResult;
}
