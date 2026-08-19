<?php

declare(strict_types=1);

namespace RAN\WordPress;

use Closure;
use LogicException;

/**
 * Registers Booster with the shared native GitHub Release updater.
 */
final class GitHubReleaseUpdaterBootstrap {

	public const UPDATER_PROSPECTIVE_API_VERSION = 4;

	private const PACKAGE_BOOTSTRAP     = '/vendor/ran/wp-github-release-updater/bootstrap.php';
	private const PROSPECTIVE_PREFLIGHT = 'RAN\\WPGitHubReleaseUpdater\\V1\\WordPress\\ReleaseCandidatePreflight';

	private static ?Closure $packageFactory = null;

	/**
	 * Register before plugins_loaded so every bundled package copy can arbitrate.
	 *
	 * @param callable|null $factory Test-only package factory override.
	 */
	public static function register(
		string $pluginFile,
		string $pluginVersion,
		?callable $factory = null,
		bool $nativeDiscovery = true
	): object {
		$factory = self::factory( $factory );

		$updater = $factory(
			pluginFile: $pluginFile,
			repository: 'RocketsAreNostalgic/ran-booster',
			providerRepositoryId: '565105478',
			pluginSlug: 'ran-booster',
			channel: str_contains( $pluginVersion, '-' ) ? 'prerelease' : 'stable',
			accessToken: null,
			autoUpdatePolicy: $nativeDiscovery ? 'forced-off' : 'disabled',
			cacheDuration: 21_600,
			failureCacheDuration: 900,
			nativeDiscovery: $nativeDiscovery
		);

		if ( ! is_object( $updater ) || ! is_callable( array( $updater, 'register' ) ) ) {
			throw new LogicException( 'RAN Booster release updater target is incompatible.' );
		}

		$updater->register();

		return $updater;
	}

	/**
	 * Return the prospective API exposed by the runtime selected for this target.
	 */
	public static function prospectiveApiVersion( object $updater ): ?int {
		return self::selectedPreflightApiVersion( $updater, 'PROSPECTIVE_API_VERSION' );
	}

	private static function selectedPreflightApiVersion( object $updater, string $constantName ): ?int {
		if ( ! is_callable( array( $updater, 'diagnostics' ) ) ) {
			return null;
		}

		try {
			$diagnostics = $updater->diagnostics();
		} catch ( \Throwable ) {
			return null;
		}
		if ( ! is_array( $diagnostics )
			|| true !== ( $diagnostics['selection_fixed'] ?? null )
			|| ! is_string( $diagnostics['selected_version'] ?? null )
			|| '' === $diagnostics['selected_version']
			|| ! class_exists( self::PROSPECTIVE_PREFLIGHT, false ) ) {
			return null;
		}

		$constant = self::PROSPECTIVE_PREFLIGHT . '::' . $constantName;
		if ( ! defined( $constant ) ) {
			return null;
		}
		$version = constant( $constant );

		return is_int( $version ) ? $version : null;
	}

	private static function factory( ?callable $factory ): Closure {
		if ( null !== $factory ) {
			return Closure::fromCallable( $factory );
		}
		if ( null !== self::$packageFactory ) {
			return self::$packageFactory;
		}

		$packageBootstrap = dirname( __DIR__, 2 ) . self::PACKAGE_BOOTSTRAP;
		if ( ! is_file( $packageBootstrap ) || ! is_readable( $packageBootstrap ) ) {
			throw new LogicException( 'RAN Booster release updater dependency is unavailable.' );
		}

		$loaded = require $packageBootstrap;
		if ( ! is_callable( $loaded ) ) {
			throw new LogicException( 'RAN Booster release updater bootstrap is incompatible.' );
		}
		self::$packageFactory = Closure::fromCallable( $loaded );

		return self::$packageFactory;
	}
}
