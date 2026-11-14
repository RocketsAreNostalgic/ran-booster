<?php

declare(strict_types=1);

namespace RAN\Admin;

/**
 * Owns the fixed panel and view slugs of the Booster administration URLs.
 *
 * Each slug is the visible label in lowercase with hyphens. Unknown slugs are
 * never mapped to another panel: callers render the not-found result instead.
 */
final class AdminRoutes {

	public const TROUBLESHOOTING_PANELS = array( 'installation', 'diagnostics', 'activity', 'logging', 'support-report' );
	public const PROVIDER_TASKS         = array( 'status', 'repositories', 'webhook-receiver' );
	public const PROVIDER_VIEWS         = array( 'overview', 'credentials', 'signing-secrets' );
	public const REPOSITORY_VIEWS       = array( 'status', 'branch', 'releases' );

	/** Provider views that replace the overview with a management card. */
	public const PROVIDER_MANAGEMENT_VIEWS = array( 'credentials', 'signing-secrets' );
}
