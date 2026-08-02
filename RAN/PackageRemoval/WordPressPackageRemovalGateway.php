<?php

declare(strict_types=1);

namespace RAN\PackageRemoval;

/**
 * Delegates package removal to WordPress so uninstall and deactivation hooks run.
 */
final class WordPressPackageRemovalGateway implements PackageRemovalGateway {

	public function pluginIsActive( string $identifier ): bool {
		$this->loadPluginFunctions();

		return is_plugin_active( $identifier );
	}

	public function pluginHasActiveDependents( string $identifier ): bool {
		$this->loadPluginFunctions();
		\WP_Plugin_Dependencies::initialize();

		return \WP_Plugin_Dependencies::has_active_dependents( $identifier );
	}

	public function pluginSharesDirectory( string $identifier ): bool {
		$this->loadPluginFunctions();
		$directory = dirname( $identifier );
		if ( '.' === $directory ) {
			return false;
		}

		foreach ( array_keys( get_plugins() ) as $pluginFile ) {
			if ( $pluginFile !== $identifier && dirname( $pluginFile ) === $directory ) {
				return true;
			}
		}

		return false;
	}

	public function pluginPathIsSafe( string $identifier ): bool {
		$directory = dirname( $identifier );
		$relative  = '.' === $directory ? $identifier : $directory;

		return $this->boundedInstalledPath( WP_PLUGIN_DIR, $relative );
	}

	public function deactivatePlugin( string $identifier ): void {
		$this->loadPluginFunctions();
		deactivate_plugins( $identifier, false, false );
	}

	public function deletePlugin( string $identifier ): bool {
		$this->loadPluginFunctions();

		return true === delete_plugins( array( $identifier ) );
	}

	public function themeDeletionBlocker( string $stylesheet ): ?string {
		if ( get_stylesheet() === $stylesheet ) {
			return 'theme_active';
		}
		if ( get_template() === $stylesheet ) {
			return 'theme_parent_in_use';
		}

		foreach ( wp_get_themes() as $candidateStylesheet => $theme ) {
			if ( $candidateStylesheet !== $stylesheet && $theme->get_template() === $stylesheet ) {
				return 'theme_has_children';
			}
		}

		return null;
	}

	public function themePathIsSafe( string $stylesheet ): bool {
		return $this->boundedInstalledPath( get_theme_root( $stylesheet ), $stylesheet );
	}

	public function deleteTheme( string $stylesheet ): bool {
		$this->loadThemeFunctions();

		return true === delete_theme( $stylesheet );
	}

	private function loadPluginFunctions(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	private function loadThemeFunctions(): void {
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	private function boundedInstalledPath( string $root, string $relative ): bool {
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
