<?php

declare(strict_types=1);

namespace RAN;

use RAN\Deployment\DeploymentPolicy;

abstract class AbstractPackage implements Package {

	protected $repository;
	protected DeploymentPolicy $deploymentPolicy = DeploymentPolicy::MANUAL;
	protected PackageSource $source              = PackageSource::BRANCH;
	protected int $sourceRevision                = 1;
	protected $subdirectory;
	protected ?string $deploymentRef    = null;
	protected ?string $installationSlug = null;

	public function getVersion(): string {
		return (string) ( $this->version ?? '' );
	}

	public function getDisplayName(): string {
		$name = trim( (string) ( $this->name ?? '' ) );

		return '' === $name ? (string) $this->getIdentifier() : $name;
	}

	public function getSlug(): mixed {
		if ( $this->hasSubdirectory() ) {
			return PackageSubdirectory::installationSlug( '', $this->getSubdirectory() );
		}

		return PackageSubdirectory::installationSlug(
			$this->installationSlug ?? $this->runtimeSlug(),
			null
		);
	}

	public function setInstallationSlug( ?string $slug ): void {
		$this->installationSlug = null === $slug ? null : PackageSubdirectory::normalizeSlug( $slug );
	}

	public function getSubdirectory(): mixed {
		return $this->subdirectory;
	}

	public function hasSubdirectory(): bool {
		return ! ( is_null( $this->getSubdirectory() ) || $this->getSubdirectory() === '' );
	}

	public function setSubdirectory( mixed $subdirectory ): void {
		$this->subdirectory = PackageSubdirectory::normalize( $subdirectory );
	}

	public function getDeploymentPolicy(): DeploymentPolicy {
		return $this->deploymentPolicy;
	}

	public function setDeploymentPolicy( DeploymentPolicy $deploymentPolicy ): void {
		$this->deploymentPolicy = $deploymentPolicy;
	}

	public function getSource(): PackageSource {
		return $this->source;
	}

	public function getSourceRevision(): int {
		return $this->sourceRevision;
	}

	public function setSource( PackageSource $source, int $revision ): void {
		if ( $revision < 1 ) {
			throw new \InvalidArgumentException( 'The managed package source revision is invalid.' );
		}

		$this->source         = $source;
		$this->sourceRevision = $revision;
	}

	public function setRepository( ManagedRepository $repository ): void {
		$this->repository = $repository;
	}

	public function getRepository(): ManagedRepository {
		return $this->repository;
	}

	public function getBranch(): mixed {
		return $this->repository->branch;
	}

	public function getDeploymentRef(): ?string {
		return $this->deploymentRef;
	}

	public function setDeploymentRef( ?string $deploymentRef ): void {
		$this->deploymentRef = $deploymentRef;
	}

	public function getCredentialId(): string {
		return $this->repository->reference->credentialId ?? '';
	}

	public function getProviderCode(): ?string {
		return $this->repository->provider->value;
	}

	public function getProviderRepositoryId(): ?string {
		return $this->repository->reference->providerRepositoryId;
	}

	public function isPrivate(): mixed {
		return $this->repository->reference->private;
	}

	public function getPrivate(): mixed {
		return $this->isPrivate();
	}

	public function __get( string $name ): mixed {
		$method = 'get' . ucfirst( $name );

		if ( method_exists( $this, $method ) ) {
			return $this->$method();
		}

		if ( isset( $this->$name ) ) {
			return $this->$name;
		}
	}

	public function __toString(): string {
		return $this->getIdentifier();
	}

	protected function runtimeSlug(): string {
		throw new \LogicException( 'The package type does not define an installed package slug.' );
	}
}
