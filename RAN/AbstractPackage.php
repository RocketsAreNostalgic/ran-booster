<?php

declare(strict_types=1);

namespace RAN; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Core owns the established three-character RAN namespace; WPCS requires four characters.

use RAN\Deployment\DeploymentPolicy;

/**
 * @phpstan-consistent-constructor
 * @property-read ManagedRepository $repository Getter-backed repository read.
 * @property-read mixed $name Optional plugin/theme metadata, returned unchanged.
 */
abstract class AbstractPackage implements Package {

	/** @var ManagedRepository|null Unset until repository hydration. */
	protected $repository;
	protected DeploymentPolicy $deployment_policy = DeploymentPolicy::MANUAL;
	protected PackageSource $source               = PackageSource::BRANCH;
	protected int $source_revision                = 1;
	/** @var string|null Normalized relative path, or no subdirectory. */
	protected $subdirectory;
	protected ?string $deployment_ref    = null;
	protected ?string $installation_slug = null;

	public function get_version(): string {
		return (string) ( $this->version ?? '' );
	}

	public function get_display_name(): string {
		$name = trim( (string) ( $this->name ?? '' ) );

		return '' === $name ? (string) $this->get_identifier() : $name;
	}

	public function get_slug(): mixed {
		if ( $this->has_subdirectory() ) {
			return PackageSubdirectory::installation_slug( '', $this->get_subdirectory() );
		}

		return PackageSubdirectory::installation_slug(
			$this->installation_slug ?? $this->runtime_slug(),
			null
		);
	}

	public function set_installation_slug( ?string $slug ): void {
		$this->installation_slug = null === $slug ? null : PackageSubdirectory::normalize_slug( $slug );
	}

	public function get_subdirectory(): mixed {
		return $this->subdirectory;
	}

	public function has_subdirectory(): bool {
		return ! ( is_null( $this->get_subdirectory() ) || $this->get_subdirectory() === '' );
	}

	public function set_subdirectory( mixed $subdirectory ): void {
		$this->subdirectory = PackageSubdirectory::normalize( $subdirectory );
	}

	public function get_deployment_policy(): DeploymentPolicy {
		return $this->deployment_policy;
	}

	public function set_deployment_policy( DeploymentPolicy $deployment_policy ): void {
		$this->deployment_policy = $deployment_policy;
	}

	public function get_source(): PackageSource {
		return $this->source;
	}

	public function get_source_revision(): int {
		return $this->source_revision;
	}

	public function set_source( PackageSource $source, int $revision ): void {
		if ( $revision < 1 ) {
			throw new \InvalidArgumentException( 'The managed package source revision is invalid.' );
		}

		$this->source          = $source;
		$this->source_revision = $revision;
	}


	public function set_repository( ManagedRepository $repository ): void {
		$this->repository = $repository;
	}

	public function get_repository(): ManagedRepository {
		return $this->repository;
	}

	public function get_branch(): mixed {
		return $this->repository->branch;
	}

	public function get_deployment_ref(): ?string {
		return $this->deployment_ref;
	}

	public function set_deployment_ref( ?string $deployment_ref ): void {
		$this->deployment_ref = $deployment_ref;
	}

	public function get_credential_id(): string {
		return $this->repository->reference->credential_id ?? '';
	}

	public function get_provider_code(): ?string {
		return $this->repository->provider->value;
	}

	public function get_provider_repository_id(): ?string {
		return $this->repository->reference->provider_repository_id;
	}

	public function is_private(): mixed {
		return $this->repository->reference->private;
	}

	public function get_private(): mixed {
		return $this->is_private();
	}

	public function __get( string $name ): mixed {
		$getters = array(
			'branch'               => 'get_branch',
			'credentialid'         => 'get_credential_id',
			'deploymentpolicy'     => 'get_deployment_policy',
			'deploymentref'        => 'get_deployment_ref',
			'displayname'          => 'get_display_name',
			'identifier'           => 'get_identifier',
			'private'              => 'get_private',
			'providercode'         => 'get_provider_code',
			'providerrepositoryid' => 'get_provider_repository_id',
			'repository'           => 'get_repository',
			'slug'                 => 'get_slug',
			'source'               => 'get_source',
			'sourcerevision'       => 'get_source_revision',
			'subdirectory'         => 'get_subdirectory',
			'version'              => 'get_version',
		);
		$method  = $getters[ strtolower( $name ) ] ?? 'get' . ucfirst( $name );

		if ( method_exists( $this, $method ) && ( isset( $getters[ strtolower( $name ) ] ) || ! in_array( strtolower( $method ), $getters, true ) ) ) {
			return $this->$method();
		}

		if ( isset( $this->$name ) ) {
			return $this->$name;
		}

		return null;
	}

	public function __toString(): string {
		return $this->get_identifier();
	}

	protected function runtime_slug(): string {
		throw new \LogicException( 'The package type does not define an installed package slug.' );
	}
}
