<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;

interface RepositoryReleaseNativeTargets extends ProviderCapability {
	public function hasRegisteredNativeTarget( string $packageType, string $installedIdentifier ): bool;

	/**
	 * Create a target whose remote pre-download work runs only after Core's
	 * earliest upgrader_pre_download authority fence.
	 */
	public function createNativeTarget(
		string $packageType,
		RepositoryReference $repository,
		string $metadataFile,
		string $packageRoot,
		string $installedIdentifier,
		string $channel,
		string $deploymentPolicy
	): RepositoryReleaseNativeTarget;
}
