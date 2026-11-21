<?php

namespace RAN\Storage;

use RAN\Package;
use RAN\PackageSource;
use RAN\Theme;
use RAN\UpdaterSupport\V1\PackageDirectory;
use RAN\WordPress\ManagedReleaseConfiguration;

/** @extends AbstractPackageRepository<Theme> */
class ThemeRepository extends AbstractPackageRepository {

	/** @return array<string, Theme> */
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

	/**
	 * @param mixed $stylesheet Identifier is validated by package hydration.
	 * @param array<string, mixed> $input Editable package fields, validated before persistence.
	 */
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
	 * @param string $slug
	 * @return Theme
	 */
	public function from_slug( $slug ) {
		$wp_theme = wp_get_theme( $slug );
		if ( ! $wp_theme->exists() || false !== $wp_theme->errors() ) {
			throw $this->not_found_exception();
		}

		return Theme::from_wp_theme_object( $this->installed_wp_theme( (string) $slug, $wp_theme ) );
	}

	/**
	 * @param mixed $stylesheet Identifier is validated by package hydration.
	 * @return Theme
	 * @throws ThemeNotFound
	 * @throws PackageStorageFailure
	 * @throws \InvalidArgumentException When saved management settings are invalid.
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
		if ( '' === trim( $identifier ) ) {
			return false;
		}
		$theme = wp_get_theme( $identifier );
		return $theme->exists() && false === $theme->errors();
	}

	/** @return Theme */
	protected function package_from_installation( string $identifier ): Package {
		return Theme::from_wp_theme_object( $this->installed_wp_theme( $identifier, wp_get_theme( $identifier ) ) );
	}

	/**
	 * On a case-insensitive filesystem WordPress resolves a differently-cased name to the installed
	 * folder but keeps the spelling it was given, so record the folder's own spelling.
	 *
	 * @param \WP_Theme $resolved WordPress's answer for that name.
	 */
	private function installed_wp_theme( string $name, $resolved ): \WP_Theme {
		$themes = wp_get_themes();
		if ( isset( $themes[ $name ] ) ) {
			return $themes[ $name ];
		}
		foreach ( $themes as $stylesheet => $installed ) {
			if ( PackageDirectory::names_collide( (string) $stylesheet, $name ) ) {
				return $installed;
			}
		}

		return $resolved;
	}

	protected function not_found_exception(): ThemeNotFound {
		return new ThemeNotFound( 'Couldn\'t find theme.' );
	}
}
