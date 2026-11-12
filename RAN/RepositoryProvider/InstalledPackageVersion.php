<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

/**
 * Passive, display-only lookup of an installed Composer package's version.
 *
 * The version comes only from Composer's own installed-package metadata or, in a
 * release archive that ships no Composer runtime, from the record the build wrote
 * from composer.lock (ran-booster-packages.json). It never comes from Core's
 * version, a plugin header or the network. When neither is available, for example
 * in a release built before the record existed, the answer is null rather than a
 * guess.
 */
final class InstalledPackageVersion {
	/** @var \Closure(string): ?string */
	private readonly \Closure $lookup;

	/**
	 * @param (callable(string): ?string)|null $lookup Test seam; production uses Composer's InstalledVersions, then the build record.
	 * @param string|null $record_path Test seam for the build record's location.
	 */
	public function __construct( ?callable $lookup = null, ?string $record_path = null ) {
		$record_path ??= dirname( __DIR__, 2 ) . '/ran-booster-packages.json';
		$this->lookup = null === $lookup
			? static fn ( string $package ): ?string => self::composer_lookup( $package ) ?? self::recorded( $package, $record_path )
			: \Closure::fromCallable( $lookup );
	}

	/** Returns the package's installed version, or null when it cannot be verified. */
	public function for_package( string $package ): ?string {
		try {
			$version = ( $this->lookup )( $package );
		} catch ( \Throwable ) {
			return null;
		}
		// Bounded display text only; Composer's pretty version such as "v1.0.0-beta.21".
		return is_string( $version ) && 1 === preg_match( '/\A[0-9A-Za-z][0-9A-Za-z.+_-]{0,79}\z/D', $version ) ? $version : null;
	}

	private static function composer_lookup( string $package ): ?string {
		if ( ! class_exists( \Composer\InstalledVersions::class, false ) || ! \Composer\InstalledVersions::isInstalled( $package ) ) {
			return null;
		}
		return \Composer\InstalledVersions::getPrettyVersion( $package );
	}

	/**
	 * Build-time record written by scripts/build-release.sh. It is absent in a
	 * development checkout and in releases built before it existed.
	 */
	private static function recorded( string $package, string $path ): ?string {
		$size = is_file( $path ) ? filesize( $path ) : false;
		if ( is_link( $path ) || false === $size || $size > 4096 ) {
			return null;
		}
		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a fixed local build record, not a remote URL.
		$document = false === $contents ? null : json_decode( $contents, true, 8 );
		if ( ! is_array( $document )
			|| 'ran-booster-built-with' !== ( $document['schema'] ?? null )
			|| 1 !== ( $document['schema_version'] ?? null )
			|| ! is_array( $document['packages'] ?? null ) ) {
			return null;
		}
		$version = $document['packages'][ $package ] ?? null;

		return is_string( $version ) ? $version : null;
	}
}
