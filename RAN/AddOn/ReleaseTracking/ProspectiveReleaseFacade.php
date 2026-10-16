<?php

declare(strict_types=1);

namespace RAN\AddOn\ReleaseTracking;

use RAN\Deployment\DeploymentPolicy;

/**
 * Versioned Core boundary for installing a not-yet-managed published release.
 */
interface ProspectiveReleaseFacade {

	public const API_VERSION = 10;

	/** @param array<string,mixed>|null $provider_expectation */
	public function nonce_action( string $operation, string $type, ?array $provider_expectation, ?string $expected_identifier = null, DeploymentPolicy $initial_policy = DeploymentPolicy::MANUAL ): string;

	/**
	 * Return the bounded provider codes supporting the complete prospective workflow.
	 *
	 * This is a request-local complete-product projection, not an enumeration of
	 * individual provider facets. It performs no repository resolution, credential
	 * access, remote request, discovery or mutation.
	 *
	 * @return list<string>
	 */
	public function supported_provider_codes( string $type ): array;

	/**
	 * @param array<string, mixed>  $repository_request
	 * @param 'stable'|'prerelease' $channel
	 */
	public function list_candidates(
		string $type,
		array $repository_request,
		string $channel,
		string $nonce
	): ProspectiveReleaseResult;

	/**
	 * @param array<string, mixed>  $repository_request
	 * @param 'stable'|'prerelease' $channel
	 */
	public function inspect(
		string $type,
		array $repository_request,
		string $release_id,
		string $tag,
		string $channel,
		string $nonce
	): ProspectiveReleaseResult;

	/** @param array<string,mixed> $repository_request
	 * @param 'stable'|'prerelease' $channel
	 * @param array<string,mixed>|null $provider_expectation
	 */
	public function install(
		string $type,
		array $repository_request,
		string $release_id,
		string $tag,
		string $expected_fingerprint,
		string $channel,
		string $nonce,
		?array $provider_expectation,
		?string $expected_identifier = null,
		DeploymentPolicy $initial_policy = DeploymentPolicy::MANUAL
	): ProspectiveReleaseResult;
}
