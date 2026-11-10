<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

/**
 * Passive, display-only lookup of an installed Composer package's version.
 *
 * The version comes only from Composer's own installed-package metadata, never
 * from Core's version, a plugin header, composer.lock or the network. When that
 * metadata is not loaded (for example in a release archive, which ships no
 * Composer runtime) the answer is null rather than a guess.
 */
final class InstalledPackageVersion {
	/** @var \Closure(string): ?string */
	private readonly \Closure $lookup;

	/** @param (callable(string): ?string)|null $lookup Test seam; production uses Composer's InstalledVersions. */
	public function __construct( ?callable $lookup = null ) {
		$this->lookup = null === $lookup ? self::composer_lookup( ... ) : \Closure::fromCallable( $lookup );
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
}
