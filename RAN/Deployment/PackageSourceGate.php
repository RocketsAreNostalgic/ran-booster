<?php

declare(strict_types=1);

namespace RAN\Deployment;

use RAN\PackageSource;
use RAN\Settings\GeneralPreferenceStore;

/**
 * The one answer every install and update path reads before acting on a branch
 * source or a pre-release track.
 *
 * A development site gates nothing. Any other site pauses both until
 * RAN_BOOSTER_ALLOW_BRANCH_AND_PRERELEASE_SOURCES, or the saved setting bound to
 * this site's address, allows them. Stable releases are never paused by it, and
 * Booster's own updates never reach it. The saved setting is bound to the home
 * address, so a database copied to another address arrives paused.
 */
final class PackageSourceGate {

	/**
	 * An environment type set explicitly, through the WP_ENVIRONMENT_TYPE constant or
	 * environment variable that wp_get_environment_type() reads, decides alone: only local
	 * or development counts, so production or staging overrides the host. With neither
	 * set, WordPress reports production by default and a loopback, .localhost, .test or
	 * .local home host counts instead.
	 */
	public static function is_development_site(): bool {
		if ( defined( 'WP_ENVIRONMENT_TYPE' ) || false !== getenv( 'WP_ENVIRONMENT_TYPE' ) ) {
			return in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) ? strtolower( trim( $host, '[]' ) ) : '';

		return in_array( $host, array( 'localhost', '::1' ), true )
			|| ( str_starts_with( $host, '127.' ) && false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) )
			|| str_ends_with( $host, '.localhost' )
			|| str_ends_with( $host, '.test' )
			|| str_ends_with( $host, '.local' );
	}

	/** @param bool $fresh Read the saved setting past this request's option cache; a mutation fence passes true. */
	public static function allows( bool $fresh = false ): bool {
		if ( self::is_development_site() ) {
			return true;
		}
		// The constant wins over the saved value, so a fence never needs the database when it is set.
		if ( defined( 'RAN_BOOSTER_ALLOW_BRANCH_AND_PRERELEASE_SOURCES' ) ) {
			return true === constant( 'RAN_BOOSTER_ALLOW_BRANCH_AND_PRERELEASE_SOURCES' );
		}
		try {
			return true === ( new GeneralPreferenceStore() )->projection( $fresh )['package_sources']['value'];
		} catch ( \RuntimeException ) {
			return false;
		}
	}

	/** @param string|null $channel A release package's track, 'stable' or 'prerelease'; null for a branch source. */
	public static function pauses( PackageSource $source, ?string $channel = null, bool $fresh = false ): bool {
		return ( PackageSource::BRANCH === $source || 'prerelease' === $channel ) && ! self::allows( $fresh );
	}

	/** @param string|null $channel A release package's track; null for a branch source. */
	public static function assert_allowed( PackageSource $source, ?string $channel = null ): void {
		if ( self::pauses( $source, $channel ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal domain exception; presentation owns escaping.
			throw new DeploymentCheckFailure( DeploymentOutcome::CODE_SOURCE_PAUSED, 'Updates are paused by a site setting.' );
		}
	}
}
