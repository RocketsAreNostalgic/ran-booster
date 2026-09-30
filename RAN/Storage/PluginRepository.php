<?php

namespace RAN\Storage;

use RAN\Package;
use RAN\PackageSource;
use RAN\Plugin;
use RAN\WordPress\ManagedReleaseConfiguration;

class PluginRepository extends AbstractPackageRepository {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function allBoosterPlugins() {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';

		return $this->allPackages();
	}

	/**
	 * Read managed deployment targets without cleaning rows during an upgrade.
	 *
	 * @return array<string, Package>
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function allDeploymentPlugins( ?PackageSource $source = null ): array {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';

		return $this->allPackages( $source );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function editPlugin( $file, $input ): PackageMutationResult {
		return $this->editPackage( $file, $input );
	}

	/**
	 * @param list<array<string, mixed>> $snapshots
	 * @return array{selected: int, changed: int, unchanged: int}
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function setPluginDeploymentPolicies( array $snapshots, \RAN\Deployment\DeploymentPolicy $policy ): array {
		return $this->setDeploymentPolicies( $snapshots, $policy );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function disablePluginForRemoval( Plugin $plugin ): PackageMutationResult {
		return $this->disablePackageForRemoval( $plugin );
	}

	/**
	 * @param $slug
	 * @return Plugin
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function fromSlug( $slug ) {
		$plugins = get_plugins();

		foreach ( $plugins as $file => $plugin_info ) {
			$tmp          = explode( '/', $file );
			$current_slug = $tmp[0];

			if ( $current_slug === $slug ) {
				return Plugin::fromWpArray( $file, $plugin_info );
			}
		}

		throw $this->notFoundException();
	}

	/**
	 * @param $file
	 * @return Plugin $plugin
	 * @throws PluginNotFound
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function boosterPluginFromFile( $file ) {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';

		return $this->managedPackage( $file );
	}

	/** @throws PluginNotFound */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function installedPluginFromFile( string $file ): Plugin {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( ! $this->packageExists( $file ) ) {
			throw $this->notFoundException();
		}

		return $this->packageFromInstallation( $file );
	}

	public function store( Plugin $plugin ): PackageMutationResult {
		return $this->storePackage( $plugin );
	}

	public function adopt( Plugin $plugin ): PackageMutationResult {
		return $this->adoptPackage( $plugin );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function adoptRelease(
		Plugin $plugin,
		ManagedReleaseConfiguration $configuration,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
		int $userId
	): PackageMutationResult {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
		return $this->adoptReleasePackage( $plugin, $configuration, $userId );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function isInstalled( string $identifier ): bool {
		return $this->packageExists( $identifier );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	protected function packageType(): int {
		return 1;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	protected function packageExists( string $identifier ): bool {
		if ( '' === trim( $identifier ) ) {
			return false;
		}

		if ( ! function_exists( __NAMESPACE__ . '\\get_plugins' ) && ! function_exists( 'get_plugins' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return isset( get_plugins()[ $identifier ] );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	protected function packageFromInstallation( string $identifier ): Package {
		return Plugin::fromWpArray(
			$identifier,
			get_plugin_data( WP_PLUGIN_DIR . '/' . $identifier, false, false )
		);
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	protected function notFoundException(): PluginNotFound {
		return new PluginNotFound( 'Could not find plugin.' );
	}
}
