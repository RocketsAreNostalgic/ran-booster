<?php

declare(strict_types=1);

namespace RAN\PackageRemoval;

/**
 * Delegates package removal to WordPress so uninstall and deactivation hooks run.
 */
final class WordPressPackageRemovalGateway implements PackageRemovalGateway {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing gateway and executor override contracts.
	public function pluginIsActive( string $identifier ): bool {
		$this->load_plugin_functions();

		return is_plugin_active( $identifier );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing gateway and executor override contracts.
	public function pluginHasActiveDependents( string $identifier ): bool {
		$this->load_plugin_functions();
		\WP_Plugin_Dependencies::initialize();

		return \WP_Plugin_Dependencies::has_active_dependents( $identifier );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing gateway and executor override contracts.
	public function pluginSharesDirectory( string $identifier ): bool {
		$this->load_plugin_functions();
		$directory = dirname( $identifier );
		if ( '.' === $directory ) {
			return false;
		}

		foreach ( array_keys( get_plugins() ) as $plugin_file ) {
			if ( $plugin_file !== $identifier && dirname( $plugin_file ) === $directory ) {
				return true;
			}
		}

		return false;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing gateway and executor override contracts.
	public function pluginPathIsSafe( string $identifier ): bool {
		$directory = dirname( $identifier );
		$relative  = '.' === $directory ? $identifier : $directory;

		return $this->bounded_installed_path( WP_PLUGIN_DIR, $relative );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing gateway and executor override contracts.
	public function deactivatePlugin( string $identifier ): void {
		$this->load_plugin_functions();
		deactivate_plugins( $identifier, false, false );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing gateway and executor override contracts.
	public function deletePlugin( string $identifier ): bool {
		$this->load_plugin_functions();

		try {
			return true === delete_plugins( array( $identifier ) );
		} finally {
			wp_clean_plugins_cache( false );
		}
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing gateway and executor override contracts.
	public function themeDeletionBlocker( string $stylesheet ): ?string {
		if ( get_stylesheet() === $stylesheet ) {
			return 'theme_active';
		}
		if ( get_template() === $stylesheet ) {
			return 'theme_parent_in_use';
		}

		foreach ( wp_get_themes() as $candidate_stylesheet => $theme ) {
			if ( $candidate_stylesheet !== $stylesheet && $theme->get_template() === $stylesheet ) {
				return 'theme_has_children';
			}
		}

		return null;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing gateway and executor override contracts.
	public function themePathIsSafe( string $stylesheet ): bool {
		return $this->bounded_installed_path( get_theme_root( $stylesheet ), $stylesheet );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing gateway and executor override contracts.
	public function deleteTheme( string $stylesheet ): bool {
		$this->load_theme_functions();

		return true === delete_theme( $stylesheet );
	}

	private function load_plugin_functions(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	private function load_theme_functions(): void {
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	private function bounded_installed_path( string $root, string $relative ): bool {
		$root = realpath( $root );
		if ( false === $root || is_link( $root ) ) {
			return false;
		}
		$path = $root . DIRECTORY_SEPARATOR . $relative;
		if ( is_link( $path ) ) {
			return false;
		}
		$resolved = realpath( $path );

		return false !== $resolved
			&& str_starts_with( $resolved . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR );
	}
}
