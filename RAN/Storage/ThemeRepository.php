<?php

namespace RAN\Storage;

use RAN\Package;
use RAN\PackageSource;
use RAN\Theme;
use RAN\WordPress\ManagedReleaseConfiguration;

class ThemeRepository extends AbstractPackageRepository {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function allBoosterThemes() {
		return $this->allPackages();
	}

	/**
	 * Read managed deployment targets without cleaning rows during an upgrade.
	 *
	 * @return array<string, Package>
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function allDeploymentThemes( ?PackageSource $source = null ): array {
		return $this->allPackages( $source );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function editTheme( $stylesheet, $input ): PackageMutationResult {
		return $this->editPackage( $stylesheet, $input );
	}

	/**
	 * @param list<array<string, mixed>> $snapshots
	 * @return array{selected: int, changed: int, unchanged: int}
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function setThemeDeploymentPolicies( array $snapshots, \RAN\Deployment\DeploymentPolicy $policy ): array {
		return $this->setDeploymentPolicies( $snapshots, $policy );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function disableThemeForRemoval( Theme $theme ): PackageMutationResult {
		return $this->disablePackageForRemoval( $theme );
	}

	/**
	 * @param $slug
	 * @return Theme
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function fromSlug( $slug ) {
		$wp_theme = wp_get_theme( $slug );
		if ( ! $this->is_valid_theme( $wp_theme ) ) {
			throw $this->notFoundException();
		}

		return Theme::fromWpThemeObject( $wp_theme );
	}

	/**
	 * @param $stylesheet
	 * @return Theme
	 * @throws ThemeNotFound
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function boosterThemeFromStylesheet( $stylesheet ) {
		return $this->managedPackage( $stylesheet );
	}

	/** @throws ThemeNotFound */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function installedThemeFromStylesheet( string $stylesheet ): Theme {
		if ( ! $this->packageExists( $stylesheet ) ) {
			throw $this->notFoundException();
		}

		return $this->packageFromInstallation( $stylesheet );
	}

	public function store( Theme $theme ): PackageMutationResult {
		return $this->storePackage( $theme );
	}

	public function adopt( Theme $theme ): PackageMutationResult {
		return $this->adoptPackage( $theme );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function adoptRelease(
		Theme $theme,
		ManagedReleaseConfiguration $configuration,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
		int $userId
	): PackageMutationResult {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
		return $this->adoptReleasePackage( $theme, $configuration, $userId );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function isInstalled( string $identifier ): bool {
		return $this->packageExists( $identifier );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	protected function packageType(): int {
		return 2;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	protected function packageExists( string $identifier ): bool {
		return '' !== trim( $identifier ) && $this->is_valid_theme( wp_get_theme( $identifier ) );
	}

	private function is_valid_theme( object $theme ): bool {
		return $theme->exists() && false === $theme->errors();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	protected function packageFromInstallation( string $identifier ): Package {
		return Theme::fromWpThemeObject( wp_get_theme( $identifier ) );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	protected function notFoundException(): ThemeNotFound {
		return new ThemeNotFound( 'Couldn\'t find theme.' );
	}
}
