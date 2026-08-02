<?php

declare(strict_types=1);

namespace RAN;

use RAN\Deployment\DeploymentPolicy;

interface Package {
	public function getIdentifier(): mixed;

	public function getDisplayName(): string;

	public function getVersion(): string;

	public function getSlug(): mixed;

	public function setInstallationSlug( ?string $slug ): void;

	public function getSubdirectory(): mixed;

	public function hasSubdirectory(): bool;

	public function setSubdirectory( mixed $subdirectory ): void;

	public function getDeploymentPolicy(): DeploymentPolicy;

	public function setDeploymentPolicy( DeploymentPolicy $deploymentPolicy ): void;

	public function getSource(): PackageSource;

	public function getSourceRevision(): int;

	public function setSource( PackageSource $source, int $revision ): void;

	public function setRepository( ManagedRepository $repository ): void;

	public function getRepository(): ManagedRepository;

	public function getBranch(): mixed;

	public function getDeploymentRef(): ?string;

	public function setDeploymentRef( ?string $deploymentRef ): void;

	public function getCredentialId(): string;

	public function getProviderCode(): ?string;

	public function getProviderRepositoryId(): ?string;

	public function isPrivate(): mixed;

	public function getPrivate(): mixed;
}
