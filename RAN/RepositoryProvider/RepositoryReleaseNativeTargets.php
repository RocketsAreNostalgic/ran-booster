<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;

interface RepositoryReleaseNativeTargets extends ProviderCapability {
	public function has_registered_native_target( string $package_type, string $installed_identifier ): bool;

	/**
	 * Create a target whose remote pre-download work runs only after Core's
	 * earliest upgrader_pre_download authority fence.
	 */
	public function create_native_target(
		string $package_type,
		RepositoryReference $repository,
		string $metadata_file,
		string $package_root,
		string $installed_identifier,
		string $channel,
		string $deployment_policy
	): RepositoryReleaseNativeTarget;
}
