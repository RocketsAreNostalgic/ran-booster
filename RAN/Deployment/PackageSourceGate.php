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
 * this site's address, allows them. Stable releases and Booster's own updates
 * never reach this gate. The environment comes from configuration and the
 * server, never the database, so a copied database cannot loosen it.
 */
final class PackageSourceGate {

	/** Explicit local or development environment type, or a loopback, .test or .local home host. */
	public static function is_development_site(): bool {
		if ( in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
			return true;
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) ? strtolower( trim( $host, '[]' ) ) : '';

		return in_array( $host, array( 'localhost', '::1' ), true )
			|| ( str_starts_with( $host, '127.' ) && false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) )
			|| str_ends_with( $host, '.localhost' )
			|| str_ends_with( $host, '.test' )
			|| str_ends_with( $host, '.local' );
	}

	public static function allows(): bool {
		return self::is_development_site()
			|| true === ( new GeneralPreferenceStore() )->projection()['package_sources']['value'];
	}

	/** @param string|null $channel A release package's track, 'stable' or 'prerelease'; null for a branch source. */
	public static function pauses( PackageSource $source, ?string $channel = null ): bool {
		return ( PackageSource::BRANCH === $source || 'prerelease' === $channel ) && ! self::allows();
	}

	/** @param string|null $channel A release package's track; null for a branch source. */
	public static function assert_allowed( PackageSource $source, ?string $channel = null ): void {
		if ( self::pauses( $source, $channel ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal domain exception; presentation owns escaping.
			throw new DeploymentCheckFailure( DeploymentOutcome::CODE_SOURCE_PAUSED, 'Updates are paused by a site setting.' );
		}
	}
}
