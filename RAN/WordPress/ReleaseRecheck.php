<?php

declare(strict_types=1);

namespace RAN\WordPress;

use RAN\Deployment\DeploymentPolicy;
use RAN\PackageSource;
use Throwable;

/** Clears the cached update check when updates are turned on or off for a release-managed package. */
final class ReleaseRecheck {

	public static function would_reset( PackageSource $source, DeploymentPolicy $before, DeploymentPolicy $after ): bool {
		return PackageSource::RELEASE_ASSET === $source
			&& ( DeploymentPolicy::DISABLED === $before ) !== ( DeploymentPolicy::DISABLED === $after );
	}

	/**
	 * Turning updates on: the next administration request runs one check through the updater's
	 * own policy, cooldown and claim gates. Turning them off: no offer cached earlier survives.
	 * Failure never blocks the policy change.
	 */
	public static function request( string $package_type ): void {
		try {
			delete_site_transient( 'plugin' === $package_type ? 'update_plugins' : 'update_themes' );
		} catch ( Throwable ) {
			return;
		}
	}
}
