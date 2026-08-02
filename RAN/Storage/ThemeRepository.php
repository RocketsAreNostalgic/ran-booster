<?php

namespace RAN\Storage;

use RAN\Package;
use RAN\PackageSource;
use RAN\Theme;
use RAN\WordPress\ManagedReleaseConfiguration;

class ThemeRepository extends AbstractPackageRepository {

	public function allBoosterThemes() {
		return $this->allPackages();
	}

	/**
	 * Read managed deployment targets without cleaning rows during an upgrade.
	 *
	 * @return array<string, Package>
	 */
	public function allDeploymentThemes( ?PackageSource $source = null ): array {
		return $this->allPackages( $source );
	}

	public function editTheme( $stylesheet, $input ): PackageMutationResult {
		return $this->editPackage( $stylesheet, $input );
	}

	/**
	 * @param list<array<string, mixed>> $snapshots
	 * @return array{selected: int, changed: int, unchanged: int}
	 */
	public function setThemeDeploymentPolicies( array $snapshots, \RAN\Deployment\DeploymentPolicy $policy ): array {
		return $this->setDeploymentPolicies( $snapshots, $policy );
	}

	public function disableThemeForRemoval( Theme $theme ): PackageMutationResult {
		return $this->disablePackageForRemoval( $theme );
	}

	/**
	 * @param $slug
	 * @return Theme
	 */
	public function fromSlug( $slug ) {
		$wpTheme = wp_get_theme( $slug );
		if ( ! $this->isValidTheme( $wpTheme ) ) {
			throw $this->notFoundException();
		}

		return Theme::fromWpThemeObject( $wpTheme );
	}

	/**
	 * @param $stylesheet
	 * @return Theme
	 * @throws ThemeNotFound
	 */
	public function boosterThemeFromStylesheet( $stylesheet ) {
		return $this->managedPackage( $stylesheet );
	}

	/** @throws ThemeNotFound */
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

	public function adoptRelease(
		Theme $theme,
		ManagedReleaseConfiguration $configuration,
		int $userId
	): PackageMutationResult {
		return $this->adoptReleasePackage( $theme, $configuration, $userId );
	}

	public function isInstalled( string $identifier ): bool {
		return $this->packageExists( $identifier );
	}

	protected function packageType(): int {
		return 2;
	}

	protected function packageExists( string $identifier ): bool {
		return '' !== trim( $identifier ) && $this->isValidTheme( wp_get_theme( $identifier ) );
	}

	private function isValidTheme( object $theme ): bool {
		return $theme->exists() && false === $theme->errors();
	}

	protected function packageFromInstallation( string $identifier ): Package {
		return Theme::fromWpThemeObject( wp_get_theme( $identifier ) );
	}

	protected function notFoundException(): ThemeNotFound {
		return new ThemeNotFound( 'Couldn\'t find theme.' );
	}
}
