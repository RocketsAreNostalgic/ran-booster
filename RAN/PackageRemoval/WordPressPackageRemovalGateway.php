<?php

declare(strict_types=1);

namespace RAN\PackageRemoval;

/**
 * Delegates package removal to WordPress so uninstall and deactivation hooks run.
 */
final class WordPressPackageRemovalGateway implements PackageRemovalGateway {

	public function installed_targets( string $type ): array {
		if ( 'plugin' === $type ) {
			$this->load_plugin_functions();
			wp_clean_plugins_cache( false );
			return array_map( static fn ( array $plugin ): string => (string) $plugin['Name'], get_plugins() );
		}
		if ( 'theme' !== $type ) {
			throw new \InvalidArgumentException( 'The package type is invalid.' );
		}
		wp_clean_themes_cache( false );
		$targets = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			if ( false === $theme->errors() ) {
				$targets[ $stylesheet ] = (string) $theme->get( 'Name' );
			}
		}
		return $targets;
	}

	public function installed_fingerprint( string $type, string $identifier ): string {
		if ( ! array_key_exists( $identifier, $this->installed_targets( $type ) ) ) {
			throw new \RuntimeException( 'The exact installed target cannot be verified.' );
		}
		$file      = 'plugin' === $type ? WP_PLUGIN_DIR . '/' . $identifier : get_theme_root( $identifier ) . '/' . $identifier . '/style.css';
		$component = $file;
		$links     = array();
		while ( true ) {
			clearstatcache( true, $component );
			if ( is_link( $component ) ) {
				$link      = readlink( $component );
				$link_stat = lstat( $component );
				if ( false === $link || false === $link_stat ) {
					throw new \RuntimeException( 'The installed link identity is unavailable.' );
				}
				$links[] = array( $component, $link, (string) $link_stat['dev'], (string) $link_stat['ino'] );
			}
			$parent = dirname( $component );
			if ( $parent === $component ) {
				break;
			}
			$component = $parent;
		}
		clearstatcache( true, $file );
		$path = realpath( $file );
		$stat = false !== $path && is_file( $path ) ? stat( $path ) : false;
		$hash = false !== $path && is_file( $path ) ? hash_file( 'sha256', $path ) : false;
		if ( false === $path || false === $stat || false === $hash ) {
			throw new \RuntimeException( 'The installed target fingerprint is unavailable.' );
		}
		return hash( 'sha256', http_build_query( array( $type, $identifier, $path, (string) $stat['dev'], (string) $stat['ino'], $hash, $links ), '', '&', PHP_QUERY_RFC3986 ) );
	}

	public function invalidate_native_target( string $type, string $identifier ): void {
		$name  = 'plugin' === $type ? 'update_plugins' : 'update_themes';
		$value = get_site_transient( $name );
		if ( ! is_object( $value ) ) {
			return;
		}
		foreach ( array( 'response', 'no_update' ) as $channel ) {
			if ( isset( $value->{$channel} ) && is_array( $value->{$channel} ) ) {
				unset( $value->{$channel}[ $identifier ] );
			}
		}
		set_site_transient( $name, $value );
		$stored = get_site_transient( $name );
		foreach ( array( 'response', 'no_update' ) as $channel ) {
			if ( is_object( $stored ) && isset( $stored->{$channel} ) && is_array( $stored->{$channel} )
				&& array_key_exists( $identifier, $stored->{$channel} ) ) {
				throw new \RuntimeException( 'The departed native target cache could not be invalidated.' );
			}
		}
	}

	public function plugin_is_active( string $identifier ): bool {
		$this->load_plugin_functions();

		return is_plugin_active( $identifier );
	}

	public function plugin_has_active_dependents( string $identifier ): bool {
		$this->load_plugin_functions();
		\WP_Plugin_Dependencies::initialize();

		return \WP_Plugin_Dependencies::has_active_dependents( $identifier );
	}

	public function plugin_shares_directory( string $identifier ): bool {
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

	public function plugin_path_is_safe( string $identifier ): bool {
		$directory = dirname( $identifier );
		$relative  = '.' === $directory ? $identifier : $directory;

		return $this->bounded_installed_path( WP_PLUGIN_DIR, $relative );
	}

	public function deactivate_plugin( string $identifier ): void {
		$this->load_plugin_functions();
		deactivate_plugins( $identifier, false, false );
	}

	public function delete_plugin( string $identifier ): bool {
		$this->load_plugin_functions();

		try {
			return true === delete_plugins( array( $identifier ) );
		} finally {
			wp_clean_plugins_cache( false );
		}
	}

	public function theme_deletion_blocker( string $stylesheet ): ?string {
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

	public function theme_path_is_safe( string $stylesheet ): bool {
		return $this->bounded_installed_path( get_theme_root( $stylesheet ), $stylesheet );
	}

	public function delete_theme( string $stylesheet ): bool {
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
		clearstatcache( true, $root );
		if ( is_link( $root ) ) {
			return false;
		}
		$root = realpath( $root );
		if ( false === $root ) {
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
