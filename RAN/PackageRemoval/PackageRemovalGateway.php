<?php

declare(strict_types=1);

namespace RAN\PackageRemoval;

/**
 * Narrow WordPress boundary for uninstalling and deleting managed packages.
 */
interface PackageRemovalGateway {

	public function pluginIsActive( string $identifier ): bool;

	public function pluginHasActiveDependents( string $identifier ): bool;

	public function pluginSharesDirectory( string $identifier ): bool;

	public function pluginPathIsSafe( string $identifier ): bool;

	public function deactivatePlugin( string $identifier ): void;

	public function deletePlugin( string $identifier ): bool;

	/**
	 * Return a bounded blocker code, or null when WordPress can delete the theme.
	 */
	public function themeDeletionBlocker( string $stylesheet ): ?string;

	public function themePathIsSafe( string $stylesheet ): bool;

	public function deleteTheme( string $stylesheet ): bool;
}
