<?php

namespace RAN\Storage;

use RAN\Package;
use RAN\PackageSource;
use RAN\Theme;
use RAN\WordPress\ManagedReleaseConfiguration;

class ThemeRepository extends AbstractPackageRepository {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function allBoosterThemes() {
		return $this->all_packages();
	}

	/**
	 * Read managed deployment targets without cleaning rows during an upgrade.
	 *
	 * @return array<string, Package>
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function allDeploymentThemes( ?PackageSource $source = null ): array {
		return $this->all_packages( $source );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function editTheme( $stylesheet, $input ): PackageMutationResult {
		return $this->edit_package( $stylesheet, $input );
	}

	/**
	 * @param list<array<string, mixed>> $snapshots
	 * @return array{selected: int, changed: int, unchanged: int}
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function setThemeDeploymentPolicies( array $snapshots, \RAN\Deployment\DeploymentPolicy $policy ): array {
		return $this->set_deployment_policies( $snapshots, $policy );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function disableThemeForRemoval( Theme $theme ): PackageMutationResult {
		return $this->disable_package_for_removal( $theme );
	}

	/**
	 * @param $slug
	 * @return Theme
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function fromSlug( $slug ) {
		$wp_theme = wp_get_theme( $slug );
		if ( ! $this->is_valid_theme( $wp_theme ) ) {
			throw $this->not_found_exception();
		}

		return Theme::from_wp_theme_object( $wp_theme );
	}

	/**
	 * @param $stylesheet
	 * @return Theme
	 * @throws ThemeNotFound
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function boosterThemeFromStylesheet( $stylesheet ) {
		return $this->managed_package( $stylesheet );
	}

	/** @throws ThemeNotFound */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function installedThemeFromStylesheet( string $stylesheet ): Theme {
		if ( ! $this->package_exists( $stylesheet ) ) {
			throw $this->not_found_exception();
		}

		return $this->package_from_installation( $stylesheet );
	}

	public function store( Theme $theme ): PackageMutationResult {
		return $this->store_package( $theme );
	}

	public function adopt( Theme $theme ): PackageMutationResult {
		return $this->adopt_package( $theme );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function adoptRelease(
		Theme $theme,
		ManagedReleaseConfiguration $configuration,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
		int $userId
	): PackageMutationResult {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
		return $this->adopt_release_package( $theme, $configuration, $userId );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function isInstalled( string $identifier ): bool {
		return $this->package_exists( $identifier );
	}

	protected function package_type(): int {
		return 2;
	}

	protected function package_exists( string $identifier ): bool {
		return '' !== trim( $identifier ) && $this->is_valid_theme( wp_get_theme( $identifier ) );
	}

	private function is_valid_theme( object $theme ): bool {
		return $theme->exists() && false === $theme->errors();
	}

	protected function package_from_installation( string $identifier ): Package {
		return Theme::from_wp_theme_object( wp_get_theme( $identifier ) );
	}

	protected function not_found_exception(): ThemeNotFound {
		return new ThemeNotFound( 'Couldn\'t find theme.' );
	}
}
