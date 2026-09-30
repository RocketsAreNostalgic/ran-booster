<?php

declare(strict_types=1);

namespace RAN;

use RAN\Deployment\DeploymentPolicy;

/** @phpstan-consistent-constructor */
abstract class AbstractPackage implements Package {

	protected $repository;
	protected DeploymentPolicy $deploymentPolicy = DeploymentPolicy::MANUAL;
	protected PackageSource $source              = PackageSource::BRANCH;
	protected int $sourceRevision                = 1;
	protected $subdirectory;
	protected ?string $deploymentRef    = null;
	protected ?string $installationSlug = null;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public API names and dynamic getter contracts remain deferred to their connected caller cohort under #167.
	public function getVersion(): string {
		return (string) ( $this->version ?? '' );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public API names and dynamic getter contracts remain deferred to their connected caller cohort under #167.
	public function getDisplayName(): string {
		$name = trim( (string) ( $this->name ?? '' ) );

		return '' === $name ? (string) $this->getIdentifier() : $name;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public API names and dynamic getter contracts remain deferred to their connected caller cohort under #167.
	public function getSlug(): mixed {
		if ( $this->hasSubdirectory() ) {
			return PackageSubdirectory::installation_slug( '', $this->getSubdirectory() );
		}

		return PackageSubdirectory::installation_slug(
			$this->installationSlug ?? $this->runtime_slug(),
			null
		);
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public API names and dynamic getter contracts remain deferred to their connected caller cohort under #167.
	public function setInstallationSlug( ?string $slug ): void {
		$this->installationSlug = null === $slug ? null : PackageSubdirectory::normalize_slug( $slug );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public API names and dynamic getter contracts remain deferred to their connected caller cohort under #167.
	public function getSubdirectory(): mixed {
		return $this->subdirectory;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public API names and dynamic getter contracts remain deferred to their connected caller cohort under #167.
	public function hasSubdirectory(): bool {
		return ! ( is_null( $this->getSubdirectory() ) || $this->getSubdirectory() === '' );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public API names and dynamic getter contracts remain deferred to their connected caller cohort under #167.
	public function setSubdirectory( mixed $subdirectory ): void {
		$this->subdirectory = PackageSubdirectory::normalize( $subdirectory );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public API names and dynamic getter contracts remain deferred to their connected caller cohort under #167.
	public function getDeploymentPolicy(): DeploymentPolicy {
		return $this->deploymentPolicy;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public API names and dynamic getter contracts remain deferred to their connected caller cohort under #167.
	public function setDeploymentPolicy( DeploymentPolicy $deploymentPolicy ): void {
		$this->deploymentPolicy = $deploymentPolicy;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public API names and dynamic getter contracts remain deferred to their connected caller cohort under #167.
	public function getSource(): PackageSource {
		return $this->source;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public API names and dynamic getter contracts remain deferred to their connected caller cohort under #167.
	public function getSourceRevision(): int {
		return $this->sourceRevision;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public API names and dynamic getter contracts remain deferred to their connected caller cohort under #167.
	public function setSource( PackageSource $source, int $revision ): void {
		if ( $revision < 1 ) {
			throw new \InvalidArgumentException( 'The managed package source revision is invalid.' );
		}

		$this->source         = $source;
		$this->sourceRevision = $revision;
	}


	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public API names and dynamic getter contracts remain deferred to their connected caller cohort under #167.
	public function setRepository( ManagedRepository $repository ): void {
		$this->repository = $repository;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public API names and dynamic getter contracts remain deferred to their connected caller cohort under #167.
	public function getRepository(): ManagedRepository {
		return $this->repository;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public API names and dynamic getter contracts remain deferred to their connected caller cohort under #167.
	public function getBranch(): mixed {
		return $this->repository->branch;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public API names and dynamic getter contracts remain deferred to their connected caller cohort under #167.
	public function getDeploymentRef(): ?string {
		return $this->deploymentRef;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public API names and dynamic getter contracts remain deferred to their connected caller cohort under #167.
	public function setDeploymentRef( ?string $deploymentRef ): void {
		$this->deploymentRef = $deploymentRef;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public API names and dynamic getter contracts remain deferred to their connected caller cohort under #167.
	public function getCredentialId(): string {
		return $this->repository->reference->credentialId ?? '';
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public API names and dynamic getter contracts remain deferred to their connected caller cohort under #167.
	public function getProviderCode(): ?string {
		return $this->repository->provider->value;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public API names and dynamic getter contracts remain deferred to their connected caller cohort under #167.
	public function getProviderRepositoryId(): ?string {
		return $this->repository->reference->providerRepositoryId;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public API names and dynamic getter contracts remain deferred to their connected caller cohort under #167.
	public function isPrivate(): mixed {
		return $this->repository->reference->private;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public API names and dynamic getter contracts remain deferred to their connected caller cohort under #167.
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

		return null;
	}

	public function __toString(): string {
		return $this->getIdentifier();
	}

	protected function runtime_slug(): string {
		throw new \LogicException( 'The package type does not define an installed package slug.' );
	}
}
