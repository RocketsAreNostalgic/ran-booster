<?php

declare(strict_types=1);

namespace RAN\WordPress;

use RAN\Deployment\DeploymentPolicy;
use RAN\PackageSource;
use Throwable;

/** Requests one fresh release check when updates are enabled for a release-managed package. */
final class ReleaseRecheck {

	public static function would_enable( PackageSource $source, DeploymentPolicy $before, DeploymentPolicy $after ): bool {
		return PackageSource::RELEASE_ASSET === $source
			&& DeploymentPolicy::DISABLED === $before
			&& DeploymentPolicy::DISABLED !== $after;
	}

	/**
	 * Clearing WordPress's cached update check makes its next administration request run one
	 * check through the updater's own policy, cooldown and claim gates. Failure never blocks the
	 * policy change; the check records its own outcome in Activity.
	 */
	public static function request( string $package_type ): void {
		try {
			delete_site_transient( 'plugin' === $package_type ? 'update_plugins' : 'update_themes' );
		} catch ( Throwable ) {
			return;
		}
	}
}
