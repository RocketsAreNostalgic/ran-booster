<?php

declare(strict_types=1);

namespace RAN\WordPress;

use LogicException;

/** Registers the ordinary Composer package namespaces used by branch execution. */
final class BranchUpdaterBootstrap {

	/** @var array<string, string> */
	private const PREFIXES = array(
		'RAN\\WPBranchUpdater\\V1\\' => '/vendor/ran/wp-branch-updater/src/',
		'RAN\\UpdaterSupport\\V1\\'  => '/vendor/ran/updater-support/src/',
	);

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		$root = dirname( __DIR__, 2 );
		foreach ( self::PREFIXES as $directory ) {
			$path = $root . $directory;
			if ( ! is_dir( $path ) || ! is_readable( $path ) ) {
				throw new LogicException( 'RAN Booster branch updater dependency is unavailable.' );
			}
		}

		spl_autoload_register(
			static function ( string $class ) use ( $root ): void {
				foreach ( self::PREFIXES as $prefix => $directory ) {
					$length = strlen( $prefix );
					if ( 0 !== strncmp( $prefix, $class, $length ) ) {
						continue;
					}

					$file = $root . $directory . str_replace( '\\', '/', substr( $class, $length ) ) . '.php';
					if ( is_file( $file ) && is_readable( $file ) ) {
						require $file;
					}

					return;
				}
			}
		);

		self::$registered = true;
	}
}
