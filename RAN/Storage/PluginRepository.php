<?php

namespace RAN\Storage;

use RAN\Package;
use RAN\PackageSource;
use RAN\Plugin;
use RAN\WordPress\ManagedReleaseConfiguration;

class PluginRepository extends AbstractPackageRepository {

	public function all_booster_plugins() {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';

		return $this->all_packages();
	}

	/**
	 * Read managed deployment targets without cleaning rows during an upgrade.
	 *
	 * @return array<string, Package>
	 */
	public function all_deployment_plugins( ?PackageSource $source = null ): array {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';

		return $this->all_packages( $source );
	}

	public function edit_plugin( $file, $input ): PackageMutationResult {
		return $this->edit_package( $file, $input );
	}

	/**
	 * @param list<array<string, mixed>> $snapshots
	 * @return array{selected: int, changed: int, unchanged: int}
	 */
	public function set_plugin_deployment_policies( array $snapshots, \RAN\Deployment\DeploymentPolicy $policy ): array {
		return $this->set_deployment_policies( $snapshots, $policy );
	}

	public function disable_plugin_for_removal( Plugin $plugin ): PackageMutationResult {
		return $this->disable_package_for_removal( $plugin );
	}

	/**
	 * @param $slug
	 * @return Plugin
	 */
	public function from_slug( $slug ) {
		$plugins = get_plugins();

		foreach ( $plugins as $file => $plugin_info ) {
			$tmp          = explode( '/', $file );
			$current_slug = $tmp[0];

			if ( $current_slug === $slug ) {
				return Plugin::from_wp_array( $file, $plugin_info );
			}
		}

		throw $this->not_found_exception();
	}

	/**
	 * @param $file
	 * @return Plugin $plugin
	 * @throws PluginNotFound
	 */
	public function booster_plugin_from_file( $file ) {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';

		return $this->managed_package( $file );
	}

	/** @throws PluginNotFound */
	public function installed_plugin_from_file( string $file ): Plugin {
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

	public function adopt_release(
		Plugin $plugin,
		ManagedReleaseConfiguration $configuration,
		int $user_id
	): PackageMutationResult {
		return $this->adopt_release_package( $plugin, $configuration, $user_id );
	}

	public function is_installed( string $identifier ): bool {
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
		return Plugin::from_wp_array(
			$identifier,
			get_plugin_data( WP_PLUGIN_DIR . '/' . $identifier, false, false )
		);
	}

	protected function not_found_exception(): PluginNotFound {
		return new PluginNotFound( 'Could not find plugin.' );
	}
}
