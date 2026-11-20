<?php

declare(strict_types=1);

namespace RAN\Deployment;

use RAN\Logging\BoosterLogger;
use RAN\Package;
use RAN\PackageSource;
use RAN\Settings\GeneralPreferenceStore;
use RAN\WordPress\ManagedReleaseStore;

/**
 * The one answer every install and update path reads before acting on a branch
 * source or a pre-release track.
 *
 * A development site gates nothing. Any other site pauses each until its own
 * override allows it: RAN_BOOSTER_ALLOW_BRANCH_SOURCES or the saved branch setting
 * for branch sources, RAN_BOOSTER_ALLOW_PRERELEASE_SOURCES or the saved pre-release
 * setting for the Preview track. Each saved setting is bound to this site's address. Stable releases are never paused by it, and
 * Booster's own updates never reach it. A database copied to another address
 * arrives paused.
 */
final class PackageSourceGate {

	/** @var array<string, true> Overrides, or the release channel, whose unreadable state this request has already logged. */
	private static array $logged_unreadable = array();

	/** One release store per request, so its storage checks run once rather than once per package. Tests that reach it run in separate processes. */
	private static ?ManagedReleaseStore $releases = null;

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

	/**
	 * @param 'branch_sources'|'prerelease_sources' $override Which override to read.
	 * @param bool $fresh Read the saved setting past this request's option cache; a mutation fence passes true.
	 */
	public static function allows( string $override, bool $fresh = false ): bool {
		if ( self::is_development_site() ) {
			return true;
		}
		// The constant wins over the saved value, so a fence never needs the database when it is set.
		$constant = 'RAN_BOOSTER_ALLOW_' . strtoupper( $override );
		if ( defined( $constant ) ) {
			return true === constant( $constant );
		}
		try {
			return true === ( new GeneralPreferenceStore() )->projection( $fresh )[ $override ]['value'];
		} catch ( \RuntimeException $failure ) {
			if ( ! isset( self::$logged_unreadable[ $override ] ) ) {
				self::$logged_unreadable[ $override ] = true;
				BoosterLogger::log_exception(
					'Package source setting unreadable; paused.',
					$failure,
					array(
						'source' => $override,
						'state'  => 'paused',
					)
				);
			}
			return false;
		}
	}

	/**
	 * Whether this managed package is paused here. A Disabled package never counts. The cheap
	 * answers come first, so only a release package on a site that pauses Preview tracks reads
	 * its stored channel; an unreadable channel counts as not paused and is logged once.
	 *
	 * @param string $type The managed package type, plugin or theme.
	 */
	public static function pauses_package( Package $package, string $type ): bool {
		if ( DeploymentPolicy::DISABLED === $package->get_deployment_policy() ) {
			return false;
		}
		if ( PackageSource::BRANCH === $package->get_source() ) {
			return self::pauses( PackageSource::BRANCH );
		}
		if ( self::allows( 'prerelease_sources' ) ) {
			return false;
		}
		try {
			self::$releases ??= new ManagedReleaseStore();
			return 'prerelease' === self::$releases->configuration( $type, (string) $package->get_identifier() )?->channel();
		} catch ( \RuntimeException | \InvalidArgumentException $failure ) {
			if ( ! isset( self::$logged_unreadable['release_channel'] ) ) {
				self::$logged_unreadable['release_channel'] = true;
				BoosterLogger::log_exception(
					'Package pause state unavailable.',
					$failure,
					array(
						'operation'    => 'package_pause_state',
						'outcome_code' => 'configuration_unavailable',
					)
				);
			}
			return false;
		}
	}

	/** @param string|null $channel A release package's track, 'stable' or 'prerelease'; null for a branch source. */
	public static function pauses( PackageSource $source, ?string $channel = null, bool $fresh = false ): bool {
		$override = PackageSource::BRANCH === $source ? 'branch_sources' : ( 'prerelease' === $channel ? 'prerelease_sources' : null );
		return null !== $override && ! self::allows( $override, $fresh );
	}

	/** @param string|null $channel A release package's track; null for a branch source. */
	public static function assert_allowed( PackageSource $source, ?string $channel = null ): void {
		if ( self::pauses( $source, $channel ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal domain exception; presentation owns escaping.
			throw new DeploymentCheckFailure( DeploymentOutcome::CODE_SOURCE_PAUSED, 'Updates are paused by a site setting.' );
		}
	}
}
