<?php

declare(strict_types=1);

namespace RAN\PackageRemoval;

/**
 * Narrow WordPress boundary for uninstalling and deleting managed packages.
 */
interface PackageRemovalGateway {

	public function plugin_is_active( string $identifier ): bool;

	public function plugin_has_active_dependents( string $identifier ): bool;

	public function plugin_shares_directory( string $identifier ): bool;

	public function plugin_path_is_safe( string $identifier ): bool;

	public function deactivate_plugin( string $identifier ): void;

	public function delete_plugin( string $identifier ): bool;

	/**
	 * Return a bounded blocker code, or null when WordPress can delete the theme.
	 */
	public function theme_deletion_blocker( string $stylesheet ): ?string;

	public function theme_path_is_safe( string $stylesheet ): bool;

	public function delete_theme( string $stylesheet ): bool;
}
