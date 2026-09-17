<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load the bundled GitHub provider package without shipping Composer's
 * runtime autoloader in the WordPress release archive.
 */
spl_autoload_register(
	function ( $class ) {
		$prefix   = 'RAN\\BoosterGitHubProvider\\V1\\';
		$base_dir = __DIR__ . '/vendor/ran/booster-github-provider/src/';
		$len      = strlen( $prefix );

		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class, $len );
		$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

/**
 * PSR-4 autoloader function, as suggested by the PHP-FIG.
 * See: https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-4-autoloader-examples.md
 */
spl_autoload_register(
	function ( $class ) {

		// project-specific namespace prefix
		$prefix = 'RAN\\';

		// base directory for the namespace prefix
		$base_dir = __DIR__ . '/RAN/';

		// does the class use the namespace prefix?
		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			// no, move to the next registered autoloader
			return;
		}

		// get the relative class name
		$relative_class = substr( $class, $len );

		// replace the namespace prefix with the base directory, replace namespace
		// separators with directory separators in the relative class name, append
		// with .php
		$file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		// if the file exists, require it
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);
