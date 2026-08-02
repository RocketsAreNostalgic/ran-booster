<?php

namespace RAN\Storage;

use RAN\Package;
use RAN\PackageSource;
use RAN\Plugin;
use RAN\WordPress\ManagedReleaseConfiguration;

class PluginRepository extends AbstractPackageRepository {

	public function allBoosterPlugins() {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';

		return $this->allPackages();
	}

	/**
	 * Read managed deployment targets without cleaning rows during an upgrade.
	 *
	 * @return array<string, Package>
	 */
	public function allDeploymentPlugins( ?PackageSource $source = null ): array {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';

		return $this->allPackages( $source );
	}

	public function editPlugin( $file, $input ): PackageMutationResult {
		return $this->editPackage( $file, $input );
	}

	/**
	 * @param list<array<string, mixed>> $snapshots
	 * @return array{selected: int, changed: int, unchanged: int}
	 */
	public function setPluginDeploymentPolicies( array $snapshots, \RAN\Deployment\DeploymentPolicy $policy ): array {
		return $this->setDeploymentPolicies( $snapshots, $policy );
	}

	public function disablePluginForRemoval( Plugin $plugin ): PackageMutationResult {
		return $this->disablePackageForRemoval( $plugin );
	}

	/**
	 * @param $slug
	 * @return Plugin
	 */
	public function fromSlug( $slug ) {
		$plugins = get_plugins();

		foreach ( $plugins as $file => $pluginInfo ) {
			$tmp         = explode( '/', $file );
			$currentSlug = $tmp[0];

			if ( $currentSlug === $slug ) {
				return ran_booster()->make( 'RAN\Plugin' )->fromWpArray( $file, $pluginInfo );
			}
		}

		throw $this->notFoundException();
	}

	/**
	 * @param $file
	 * @return Plugin $plugin
	 * @throws PluginNotFound
	 */
	public function boosterPluginFromFile( $file ) {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';

		return $this->managedPackage( $file );
	}

	/** @throws PluginNotFound */
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

	public function adoptRelease(
		Plugin $plugin,
		ManagedReleaseConfiguration $configuration,
		int $userId
	): PackageMutationResult {
		return $this->adoptReleasePackage( $plugin, $configuration, $userId );
	}

	public function isInstalled( string $identifier ): bool {
		return $this->packageExists( $identifier );
	}

	protected function packageType(): int {
		return 1;
	}

	protected function packageExists( string $identifier ): bool {
		if ( '' === trim( $identifier ) ) {
			return false;
		}

		if ( ! function_exists( __NAMESPACE__ . '\\get_plugins' ) && ! function_exists( 'get_plugins' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return isset( get_plugins()[ $identifier ] );
	}

	protected function packageFromInstallation( string $identifier ): Package {
		return Plugin::fromWpArray(
			$identifier,
			get_plugin_data( WP_PLUGIN_DIR . '/' . $identifier, false, false )
		);
	}

	protected function notFoundException(): PluginNotFound {
		return new PluginNotFound( 'Could not find plugin.' );
	}
}
