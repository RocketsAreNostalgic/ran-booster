<?php

declare(strict_types=1);

namespace RAN;

use RAN\Deployment\DeploymentPolicy;

interface Package {
	public function get_identifier(): mixed;

	public function get_display_name(): string;

	public function get_version(): string;

	public function get_slug(): mixed;

	public function set_installation_slug( ?string $slug ): void;

	public function get_subdirectory(): mixed;

	public function has_subdirectory(): bool;

	public function set_subdirectory( mixed $subdirectory ): void;

	public function get_deployment_policy(): DeploymentPolicy;

	public function set_deployment_policy( DeploymentPolicy $deployment_policy ): void;

	public function get_source(): PackageSource;

	public function get_source_revision(): int;

	public function set_source( PackageSource $source, int $revision ): void;


	public function set_repository( ManagedRepository $repository ): void;

	public function get_repository(): ManagedRepository;

	public function get_branch(): mixed;

	public function get_deployment_ref(): ?string;

	public function set_deployment_ref( ?string $deployment_ref ): void;

	public function get_credential_id(): string;

	public function get_provider_code(): ?string;

	public function get_provider_repository_id(): ?string;

	public function is_private(): mixed;

	public function get_private(): mixed;
}
