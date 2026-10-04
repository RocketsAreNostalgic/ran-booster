<?php

namespace RAN\Storage;

use RAN\Package;
use RAN\PackageSource;
use RAN\Theme;
use RAN\WordPress\ManagedReleaseConfiguration;

/** @extends AbstractPackageRepository<Theme> */
class ThemeRepository extends AbstractPackageRepository {

	public function all_booster_themes() {
		return $this->all_packages();
	}

	/**
	 * Read managed deployment targets without cleaning rows during an upgrade.
	 *
	 * @return array<string, Package>
	 */
	public function all_deployment_themes( ?PackageSource $source = null ): array {
		return $this->all_packages( $source );
	}

	public function edit_theme( $stylesheet, $input ): PackageMutationResult {
		return $this->edit_package( $stylesheet, $input );
	}

	/**
	 * @param list<array<string, mixed>> $snapshots
	 * @return array{selected: int, changed: int, unchanged: int}
	 */
	public function set_theme_deployment_policies( array $snapshots, \RAN\Deployment\DeploymentPolicy $policy ): array {
		return $this->set_deployment_policies( $snapshots, $policy );
	}

	public function disable_theme_for_removal( Theme $theme ): PackageMutationResult {
		return $this->disable_package_for_removal( $theme );
	}

	/**
	 * @param $slug
	 * @return Theme
	 */
	public function from_slug( $slug ) {
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
	 * @throws PackageStorageFailure
	 */
	public function booster_theme_from_stylesheet( $stylesheet ) {
		return $this->managed_package( $stylesheet );
	}

	/** @throws ThemeNotFound */
	public function installed_theme_from_stylesheet( string $stylesheet ): Theme {
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

	public function adopt_release(
		Theme $theme,
		ManagedReleaseConfiguration $configuration,
		int $user_id
	): PackageMutationResult {
		return $this->adopt_release_package( $theme, $configuration, $user_id );
	}

	public function is_installed( string $identifier ): bool {
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

	/** @return Theme */
	protected function package_from_installation( string $identifier ): Package {
		return Theme::from_wp_theme_object( wp_get_theme( $identifier ) );
	}

	protected function not_found_exception(): ThemeNotFound {
		return new ThemeNotFound( 'Couldn\'t find theme.' );
	}
}
