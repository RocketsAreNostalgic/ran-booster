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

		return $this->all_packages();
	}

	/**
	 * Read managed deployment targets without cleaning rows during an upgrade.
	 *
	 * @return array<string, Package>
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function allDeploymentPlugins( ?PackageSource $source = null ): array {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';

		return $this->all_packages( $source );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function editPlugin( $file, $input ): PackageMutationResult {
		return $this->edit_package( $file, $input );
	}

	/**
	 * @param list<array<string, mixed>> $snapshots
	 * @return array{selected: int, changed: int, unchanged: int}
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function setPluginDeploymentPolicies( array $snapshots, \RAN\Deployment\DeploymentPolicy $policy ): array {
		return $this->set_deployment_policies( $snapshots, $policy );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function disablePluginForRemoval( Plugin $plugin ): PackageMutationResult {
		return $this->disable_package_for_removal( $plugin );
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

		throw $this->not_found_exception();
	}

	/**
	 * @param $file
	 * @return Plugin $plugin
	 * @throws PluginNotFound
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function boosterPluginFromFile( $file ) {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';

		return $this->managed_package( $file );
	}

	/** @throws PluginNotFound */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function installedPluginFromFile( string $file ): Plugin {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( ! $this->package_exists( $file ) ) {
			throw $this->not_found_exception();
		}

		return $this->package_from_installation( $file );
	}

	public function store( Plugin $plugin ): PackageMutationResult {
		return $this->store_package( $plugin );
	}

	public function adopt( Plugin $plugin ): PackageMutationResult {
		return $this->adopt_package( $plugin );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function adoptRelease(
		Plugin $plugin,
		ManagedReleaseConfiguration $configuration,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
		int $userId
	): PackageMutationResult {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
		return $this->adopt_release_package( $plugin, $configuration, $userId );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function isInstalled( string $identifier ): bool {
		return $this->package_exists( $identifier );
	}

	protected function package_type(): int {
		return 1;
	}

	protected function package_exists( string $identifier ): bool {
		if ( '' === trim( $identifier ) ) {
			return false;
		}

		if ( ! function_exists( __NAMESPACE__ . '\\get_plugins' ) && ! function_exists( 'get_plugins' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return isset( get_plugins()[ $identifier ] );
	}

	protected function package_from_installation( string $identifier ): Package {
		return Plugin::fromWpArray(
			$identifier,
			get_plugin_data( WP_PLUGIN_DIR . '/' . $identifier, false, false )
		);
	}

	protected function not_found_exception(): PluginNotFound {
		return new PluginNotFound( 'Could not find plugin.' );
	}
}
